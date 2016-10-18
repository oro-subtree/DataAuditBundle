<?php
namespace Oro\Bundle\DataAuditBundle\Async;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Oro\Bundle\DataAuditBundle\Model\EntityReference;
use Oro\Bundle\DataAuditBundle\Service\ConvertEntityChangesToAuditService;
use Oro\Bundle\OrganizationBundle\Entity\Organization;
use Oro\Component\MessageQueue\Client\TopicSubscriberInterface;
use Oro\Component\MessageQueue\Consumption\MessageProcessorInterface;
use Oro\Component\MessageQueue\Transport\MessageInterface;
use Oro\Component\MessageQueue\Transport\SessionInterface;
use Oro\Component\MessageQueue\Util\JSON;

class AuditChangedEntitiesInverseRelationsProcessor implements MessageProcessorInterface, TopicSubscriberInterface
{
    /**
     * @var ManagerRegistry
     */
    private $doctrine;
    
    /**
     * @var ConvertEntityChangesToAuditService
     */
    private $convertEntityChangesToAuditService;

    /**
     * @param ManagerRegistry $doctrine
     * @param ConvertEntityChangesToAuditService $convertEntityChangesToAuditService
     */
    public function __construct(
        ManagerRegistry $doctrine,
        ConvertEntityChangesToAuditService $convertEntityChangesToAuditService
    ) {
        $this->doctrine = $doctrine;
        $this->convertEntityChangesToAuditService = $convertEntityChangesToAuditService;
    }

    /**
     * {@inheritdoc}
     */
    public function process(MessageInterface $message, SessionInterface $session)
    {
        $body = JSON::decode($message->getBody());

        $loggedAt = \DateTime::createFromFormat('U', $body['timestamp']);
        $transactionId = $body['transaction_id'];

        $user = new EntityReference();
        if (isset($body['user_id'])) {
            $user = new EntityReference($body['user_class'], $body['user_id']);
        }

        $organization = new EntityReference();
        if (isset($body['organization_id'])) {
            $organization = new EntityReference(Organization::class, $body['organization_id']);
        }

        $map = [];

        // one to one, one to many, many to many inverse side
        $sourceEntitiesData = array_merge(
            $body['entities_inserted'],
            $body['entities_updated'],
            $body['entities_deleted'],
            $body['collections_updated']
        );

        $this->processManyToOneAndManyToManyAndOneToOneRelations($sourceEntitiesData, $map);
        $this->processEntityFromCollectionUpdated($body['entities_updated'], $map);

        $this->convertEntityChangesToAuditService->convert(
            $map,
            $transactionId,
            $loggedAt,
            $user,
            $organization
        );

        return self::ACK;
    }

    private function processManyToOneAndManyToManyAndOneToOneRelations(array $sourceEntitiesData, array &$map)
    {
        foreach ($sourceEntitiesData as $sourceEntityData) {
            $sourceEntityClass = $sourceEntityData['entity_class'];
            $sourceEntityId = $sourceEntityData['entity_id'];
            /** @var EntityManagerInterface $sourceEntityManager */
            $sourceEntityManager = $this->doctrine->getManagerForClass($sourceEntityClass);
            $sourceEntityMeta = $sourceEntityManager->getClassMetadata($sourceEntityClass);

            foreach ($sourceEntityData['change_set'] as $sourceFieldName => $sourceChange) {
                if (false == isset($sourceEntityMeta->associationMappings[$sourceFieldName]['inversedBy'])) {
                    continue;
                }

                $entityClass = $sourceEntityMeta->associationMappings[$sourceFieldName]['targetEntity'];
                $fieldName = $sourceEntityMeta->associationMappings[$sourceFieldName]['inversedBy'];
                $entityManager = $this->doctrine->getManagerForClass($entityClass);
                $entityMeta = $entityManager->getClassMetadata($entityClass);

                if ($sourceEntityMeta->isSingleValuedAssociation($sourceFieldName) &&
                    $entityMeta->isCollectionValuedAssociation($fieldName)
                ) {
                    // many to one
                    $this->processManyToOneRelation(
                        $sourceChange,
                        $entityClass,
                        $fieldName,
                        $sourceEntityClass,
                        $sourceEntityId,
                        $map
                    );
                } elseif ($sourceEntityMeta->isCollectionValuedAssociation($sourceFieldName) &&
                    $entityMeta->isCollectionValuedAssociation($fieldName)
                ) {
                    // many to many
                    $this->processManyToManyRelation(
                        $sourceChange,
                        $entityClass,
                        $fieldName,
                        $sourceEntityClass,
                        $sourceEntityId,
                        $map
                    );
                } elseif ($sourceEntityMeta->isSingleValuedAssociation($sourceFieldName) &&
                    $entityMeta->isSingleValuedAssociation($fieldName)
                ) {
                    // one to one
                    $this->processOneToOneRelations(
                        $sourceChange,
                        $entityClass,
                        $fieldName,
                        $sourceEntityClass,
                        $sourceEntityId,
                        $map
                    );
                } else {
                    throw new \LogicException('Unexpected old value');
                }
            }
        }
    }

    /**
     * @param array $sourceChange
     * @param string $entityClass
     * @param string $fieldName
     * @param string $sourceEntityClass
     * @param int $sourceEntityId
     * @param array $map
     */
    private function processManyToOneRelation(
        $sourceChange,
        $entityClass,
        $fieldName,
        $sourceEntityClass,
        $sourceEntityId,
        &$map
    ) {
        list($old, $new) = $sourceChange;

        if ($old) {
            $entityId = $old['entity_id'];

            $change = $this->getCollectionChangeSetFromMap($map, $entityClass, $entityId, $fieldName);
            $change[1]['deleted'][] = [
                'entity_class' => $sourceEntityClass,
                'entity_id' => $sourceEntityId,
                'change_set' => [],
            ];

            $this->addChangeSetToMap($map, $entityClass, $entityId, $fieldName, $change);
        }

        if ($new) {
            $entityId = $new['entity_id'];

            $change = $this->getCollectionChangeSetFromMap($map, $entityClass, $entityId, $fieldName);
            $change[1]['inserted'][] = [
                'entity_class' => $sourceEntityClass,
                'entity_id' => $sourceEntityId,
                'change_set' => [],
            ];

            $this->addChangeSetToMap($map, $entityClass, $entityId, $fieldName, $change);
        }
    }

    /**
     * @param array $sourceChange
     * @param string $entityClass
     * @param string $fieldName
     * @param string $sourceEntityClass
     * @param int $sourceEntityId
     * @param array $map
     */
    private function processManyToManyRelation(
        $sourceChange,
        $entityClass,
        $fieldName,
        $sourceEntityClass,
        $sourceEntityId,
        &$map
    ) {
        list($old, $new) = $sourceChange;

        foreach ($new['inserted'] as $insertedEntityData) {
            $entityId = $insertedEntityData['entity_id'];

            $change = $this->getCollectionChangeSetFromMap($map, $entityClass, $entityId, $fieldName);
            $change[1]['inserted'][] = [
                'entity_class' => $sourceEntityClass,
                'entity_id' => $sourceEntityId,
                'change_set' => [],
            ];

            $this->addChangeSetToMap($map, $entityClass, $entityId, $fieldName, $change);
        }

        foreach ($new['deleted'] as $deletedEntityData) {
            $entityId = $deletedEntityData['entity_id'];

            $change = $this->getCollectionChangeSetFromMap($map, $entityClass, $entityId, $fieldName);
            $change[1]['deleted'][] = [
                'entity_class' => $sourceEntityClass,
                'entity_id' => $sourceEntityId,
                'change_set' => [],
            ];

            $this->addChangeSetToMap($map, $entityClass, $entityId, $fieldName, $change);
        }
    }

    /**
     * @param array $sourceChange
     * @param string $entityClass
     * @param string $fieldName
     * @param string $sourceEntityClass
     * @param int $sourceEntityId
     * @param array $map
     */
    private function processOneToOneRelations(
        $sourceChange,
        $entityClass,
        $fieldName,
        $sourceEntityClass,
        $sourceEntityId,
        &$map
    ) {
        list($old, $new) = $sourceChange;

        if ($old) {
            $entityId = $old['entity_id'];

            $change = $this->getChangeSetFromMap($map, $entityClass, $entityId, $fieldName);
            $change[0] = [
                'entity_class' => $sourceEntityClass,
                'entity_id' => $sourceEntityId,
                'change_set' => [],
            ];

            $this->addChangeSetToMap($map, $entityClass, $entityId, $fieldName, $change);
        }

        if ($new) {
            $entityId = $new['entity_id'];

            $change = $this->getChangeSetFromMap($map, $entityClass, $entityId, $fieldName);
            $change[1] = [
                'entity_class' => $sourceEntityClass,
                'entity_id' => $sourceEntityId,
                'change_set' => [],
            ];

            $this->addChangeSetToMap($map, $entityClass, $entityId, $fieldName, $change);
        }
    }

    private function processEntityFromCollectionUpdated(array $sourceEntitiesData, array &$map)
    {
        // many to one. updated entity is part of a collection on inversed side of relation.
        foreach ($sourceEntitiesData as $sourceEntityData) {
            $sourceEntityClass = $sourceEntityData['entity_class'];
            $sourceEntityId = $sourceEntityData['entity_id'];
            /** @var EntityManagerInterface $sourceEntityManager */
            $sourceEntityManager = $this->doctrine->getManagerForClass($sourceEntityClass);
            $sourceEntityMeta = $sourceEntityManager->getClassMetadata($sourceEntityClass);

            foreach ($sourceEntityMeta->associationMappings as $sourceFieldName => $associationMapping) {
                if (false == isset($associationMapping['inversedBy'])) {
                    continue;
                }

                $entityClass = $sourceEntityMeta->associationMappings[$sourceFieldName]['targetEntity'];
                $fieldName = $sourceEntityMeta->associationMappings[$sourceFieldName]['inversedBy'];
                $entityManager = $this->doctrine->getManagerForClass($entityClass);
                $entityMeta = $entityManager->getClassMetadata($entityClass);

                if ($sourceEntityMeta->isSingleValuedAssociation($sourceFieldName) &&
                    $entityMeta->isCollectionValuedAssociation($fieldName)
                ) {
                    $sourceEntity = $sourceEntityManager->find($sourceEntityClass, $sourceEntityId);
                    if (!$sourceEntity) {
                        // the entity may be removed after update and since we are processing stuff in background
                        // it is possible that the update is processed after the real remove was performed.
                        continue;
                    }

                    $entity = $sourceEntityMeta->getFieldValue($sourceEntity, $sourceFieldName);
                    if (!$entity) {
                        // this the case where source entity does not belong to any collections
                        continue;
                    }

                    $entityId = $this->getEntityId($entityManager, $entity);

                    $change = $this->getCollectionChangeSetFromMap($map, $entityClass, $entityId, $fieldName);
                    $change[1]['changed'][] = [
                        'entity_class' => $sourceEntityClass,
                        'entity_id' => $sourceEntityId,
                        'change_set' => [],
                    ];

                    $this->addChangeSetToMap($map, $entityClass, $entityId, $fieldName, $change);
                }
            }
        }
    }

    /**
     * @param array $map
     * @param string $entityClass
     * @param int $entityId
     * @param string $fieldName
     * @param array $change
     *
     * @return array
     *
     */
    private function addChangeSetToMap(array &$map, $entityClass, $entityId, $fieldName, array $change)
    {
        $map[$entityClass.$entityId]['change_set'][$fieldName] = $change;
    }

    /**
     * @param array $map
     * @param string $entityClass
     * @param int $entityId
     * @param string $fieldName
     *
     * @return array
     */
    private function getChangeSetFromMap(array &$map, $entityClass, $entityId, $fieldName)
    {
        if (empty($entityClass) || empty($entityId)) {
            throw new \LogicException('Entity class either entity id cannot be empty');
        }

        if (false == isset($map[$entityClass.$entityId])) {
            $map[$entityClass.$entityId] = [
                'entity_class' => $entityClass,
                'entity_id' => $entityId,
                'change_set' => [],
            ];
        }

        return isset($map[$entityClass.$entityId]['change_set'][$fieldName]) ?
            $map[$entityClass.$entityId]['change_set'][$fieldName] :
            [null, null]
        ;
    }

    /**
     * @param array $map
     * @param string $entityClass
     * @param int $entityId
     * @param string $fieldName
     *
     * @return array
     */
    private function getCollectionChangeSetFromMap(array &$map, $entityClass, $entityId, $fieldName)
    {
        $change = $this->getChangeSetFromMap($map, $entityClass, $entityId, $fieldName);

        if (null === $change[1]) {
            $change[1] = ['inserted' => [], 'deleted' => [], 'changed' => []];
        }

        return $change;
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
    public static function getSubscribedTopics()
    {
        return [Topics::ENTITIES_INVERSED_RELATIONS_CHANGED];
    }
}
