<?php
namespace Oro\Bundle\DataAuditBundle\EventListener;

use Doctrine\Common\EventSubscriber;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\PersistentCollection;
use Oro\Bundle\DataAuditBundle\Async\Topics;
use Oro\Bundle\DataAuditBundle\Entity\AbstractAudit;
use Oro\Bundle\DataAuditBundle\Entity\AbstractAuditField;
use Oro\Bundle\PlatformBundle\EventListener\OptionalListenerInterface;
use Oro\Bundle\SecurityBundle\Authentication\Token\OrganizationContextTokenInterface;
use Oro\Bundle\UserBundle\Entity\AbstractUser;
use Oro\Component\MessageQueue\Client\MessageProducerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The listener does not support next features:
 *
 * Collection::clear - the deletion diff is empty because clear does takeSnapshot internally
 * Collection::removeElement - in case of "fetch extra lazy" does not schedule anything
 * "Doctrine will only check the owning side of an association for changes."
 * http://doctrine-orm.readthedocs.io/projects/doctrine-orm/en/latest/reference/unitofwork-associations.html
 */
class SendChangedEntitiesToMessageQueueListener implements EventSubscriber, OptionalListenerInterface
{
    /**
     * @var MessageProducerInterface
     */
    private $messageProducer;

    /**
     * @var TokenStorageInterface
     */
    private $securityTokenStorage;

    /**
     * @var \SplObjectStorage
     */
    private $allInsertions;

    /**
     * @var \SplObjectStorage
     */
    private $allUpdates;

    /**
     * @var \SplObjectStorage
     */
    private $allDeletions;

    /**
     * @var \SplObjectStorage
     */
    private $allCollectionUpdates;

    /**
     * @var boolean
     */
    private $enabled;

    /**
     * @param MessageProducerInterface $messageProducer
     * @param TokenStorageInterface $securityTokenStorage
     */
    public function __construct(MessageProducerInterface $messageProducer, TokenStorageInterface $securityTokenStorage)
    {
        $this->messageProducer = $messageProducer;
        $this->securityTokenStorage = $securityTokenStorage;

        $this->allInsertions = new \SplObjectStorage;
        $this->allUpdates = new \SplObjectStorage;
        $this->allDeletions = new \SplObjectStorage;
        $this->allCollectionUpdates = new \SplObjectStorage;
        
        $this->setEnabled(true);
    }

    /**
     * @param OnFlushEventArgs $eventArgs
     */
    public function onFlush(OnFlushEventArgs $eventArgs)
    {
        if (false == $this->enabled) {
            return;
        }
        
        $em = $eventArgs->getEntityManager();
        $uow = $em->getUnitOfWork();

        $insertions = new \SplObjectStorage();
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $insertions[$entity] = $uow->getEntityChangeSet($entity);
        }
        $this->allInsertions[$em] = $insertions;

        $updates = new \SplObjectStorage();
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $updates[$entity] = $uow->getEntityChangeSet($entity);
        }
        $this->allUpdates[$em] = $updates;

        $deletions = new \SplObjectStorage();
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $changeSet = [];
            $entityMeta = $em->getClassMetadata(get_class($entity));

            // in order to audit many to one inverse side we have to store some info to change set.
            foreach ($entityMeta->associationMappings as $filedName => $mapping) {
                if (ClassMetadataInfo::MANY_TO_ONE == $mapping['type']) {
                    if ($relatedEntity = $entityMeta->getFieldValue($entity, $filedName)) {
                        $changeSet[$filedName] = [
                            $this->convertEntityToArray($em, $relatedEntity, []),
                            null
                        ];
                    }
                }
            }

            $deletions[$entity] = $this->convertEntityToArray($em, $entity, $changeSet);
        }
        $this->allDeletions[$em] = $deletions;

        $collectionUpdates = new \SplObjectStorage();
        foreach ($uow->getScheduledCollectionUpdates() as $collection) {
            /** @var $collection PersistentCollection */
            
            $insertDiff = $collection->getInsertDiff();
            $deleteDiff = [];
            foreach ($collection->getDeleteDiff() as $deletedEntity) {
                $deleteDiff[] = $this->convertEntityToArray($em, $deletedEntity, []);
            }

            $collectionUpdates[$collection] = [
                'insertDiff' => $insertDiff,
                'deleteDiff' => $deleteDiff,
            ];
        }
        $this->allCollectionUpdates[$em] = $collectionUpdates;
    }

    /**
     * @param PostFlushEventArgs $eventArgs
     */
    public function postFlush(PostFlushEventArgs $eventArgs)
    {
        if (false == $this->enabled) {
            return;
        }
        
        $em = $eventArgs->getEntityManager();
        try {
            $message = [
                'timestamp' => time(),
                'transaction_id' => uniqid().'-'.uniqid().'-'.uniqid(),
                'entities_updated' => [],
                'entities_inserted' => [],
                'entities_deleted' => [],
                'collections_updated' => [],
            ];

            $token = $this->securityTokenStorage->getToken();
            if ($token && $token->getUser() instanceof AbstractUser) {
                /** @var AbstractUser $user */
                $user = $token->getUser();

                $message['user_id'] = $user->getId();
                $message['user_class'] = ClassUtils::getClass($user);
            }

            if ($token instanceof OrganizationContextTokenInterface) {
                $organization = $token->getOrganizationContext();

                $message['organization_id'] = $organization->getId();
            }

            $toBeSend = false;

            foreach ($this->allInsertions[$em] as $entity) {
                if ($entity instanceof  AbstractAudit || $entity instanceof  AbstractAuditField) {
                    continue;
                }

                $toBeSend = true;

                $changeSet = $this->allInsertions[$em][$entity];
                $message['entities_inserted'][] = $this->convertEntityToArray($em, $entity, $changeSet);
            }

            foreach ($this->allUpdates[$em] as $entity) {
                if ($entity instanceof  AbstractAudit || $entity instanceof  AbstractAuditField) {
                    continue;
                }

                $toBeSend = true;

                $changeSet = $this->allUpdates[$em][$entity];
                $message['entities_updated'][] = $this->convertEntityToArray($em, $entity, $changeSet);
            }

            foreach ($this->allDeletions[$em] as $entity) {
                if ($entity instanceof  AbstractAudit || $entity instanceof  AbstractAuditField) {
                    continue;
                }

                $toBeSend = true;

                $message['entities_deleted'][] = $this->allDeletions[$em][$entity];
            }

            foreach ($this->allCollectionUpdates[$em] as $collection) {
                /** @var PersistentCollection $collection */
                $owner = $collection->getOwner();
                if ($owner instanceof  AbstractAudit) {
                    continue;
                }


                $new = ['inserted' => [], 'deleted' => [], 'changed' => [],];
                foreach ($this->allCollectionUpdates[$em][$collection]['insertDiff'] as $entity) {
                    $new['inserted'][] = $this->convertEntityToArray($em, $entity, []);
                }
                foreach ($this->allCollectionUpdates[$em][$collection]['deleteDiff'] as $entity) {
                    $new['deleted'][] = $entity;
                }


                $ownerFieldName = $collection->getMapping()['fieldName'];
                $entityData  = $this->convertEntityToArray($em, $owner, []);
                $entityData['change_set'][$ownerFieldName] = [null, $new];

                if ($new['inserted'] || $new['deleted']) {
                    $toBeSend = true;

                    $message['collections_updated'][] = $entityData;
                }
            }

            if ($toBeSend) {
                $this->messageProducer->send(Topics::ENTITIES_CHANGED, $message);
            }
        } finally {
            $this->allInsertions->detach($em);
            $this->allUpdates->detach($em);
            $this->allDeletions->detach($em);
            $this->allCollectionUpdates->detach($em);
        }
    }

    /**
     * @param EntityManagerInterface $em
     * @param array $changeSet
     *
     * @return array
     */
    protected function sanitizeChangeSet(EntityManagerInterface $em, array $changeSet)
    {
        $sanitizedChangeSet = [];
        foreach ($changeSet as $property => $change) {
            $sanitizedNew = $new = $change[1];
            $sanitizedOld = $old = $change[0];

            if ($old instanceof \DateTime) {
                $sanitizedOld = $old->format(DATE_ISO8601);
            } elseif (is_object($old) && $em->contains($old)) {
                $sanitizedOld = $this->convertEntityToArray($em, $old, []);
            } elseif (is_object($old)) {
                continue;
            }

            if ($new instanceof \DateTime) {
                $sanitizedNew = $new->format(DATE_ISO8601);
            } elseif (is_object($new) && $em->contains($new)) {
                $sanitizedNew = $this->convertEntityToArray($em, $new, []);
            } elseif (is_object($new)) {
                continue;
            }
            
            if ($sanitizedOld === $sanitizedNew) {
                continue;
            }

            $sanitizedChangeSet[$property] = [$sanitizedOld, $sanitizedNew];
        }

        return $sanitizedChangeSet;
    }

    /**
     * @param EntityManagerInterface $em
     * @param object $entity
     * @param array $changeSet
     *
     * @return array
     */
    private function convertEntityToArray(EntityManagerInterface $em, $entity, array $changeSet)
    {
        $entityClass = ClassUtils::getClass($entity);

        return [
            'entity_class' => $entityClass,
            'entity_id' => $this->getEntityId($em, $entity),
            'change_set' => $this->sanitizeChangeSet($em, $changeSet),
        ];
    }

    /**
     * @param EntityManagerInterface $em
     * @param object $entity
     *
     * @return int|string
     */
    private function getEntityId(EntityManagerInterface $em, $entity)
    {
        $entityMeta = $em->getClassMetadata(get_class($entity));
        $idFieldName = $entityMeta->getSingleIdentifierFieldName();

        return $entityMeta->getReflectionProperty($idFieldName)->getValue($entity);
    }

    /**
     * {@inheritdoc}
     */
    public function getSubscribedEvents()
    {
        return [Events::onFlush, Events::postFlush];
    }

    /**
     * {@inheritdoc}
     */
    public function setEnabled($enabled = true)
    {
        $this->enabled = $enabled;
    }
}
