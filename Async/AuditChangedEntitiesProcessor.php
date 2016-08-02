<?php
namespace Oro\Bundle\DataAuditBundle\Async;

use Oro\Bundle\DataAuditBundle\Entity\Audit;
use Oro\Bundle\DataAuditBundle\Service\ConvertEntityChangesToAuditService;
use Oro\Bundle\OrganizationBundle\Entity\Organization;
use Oro\Bundle\UserBundle\Entity\AbstractUser;
use Oro\Component\MessageQueue\Client\MessageProducerInterface;
use Oro\Component\MessageQueue\Client\TopicSubscriberInterface;
use Oro\Component\MessageQueue\Consumption\MessageProcessorInterface;
use Oro\Component\MessageQueue\Transport\MessageInterface;
use Oro\Component\MessageQueue\Transport\SessionInterface;
use Oro\Component\MessageQueue\Util\JSON;
use Symfony\Bridge\Doctrine\RegistryInterface;

class AuditChangedEntitiesProcessor implements MessageProcessorInterface, TopicSubscriberInterface
{
    /**
     * @var RegistryInterface
     */
    private $doctrine;

    /**
     * @var ConvertEntityChangesToAuditService
     */
    private $convertEntityChangesToAuditService;

    /**
     * @var MessageProducerInterface
     */
    private $messageProducer;

    /**
     * @param RegistryInterface $doctrine
     * @param ConvertEntityChangesToAuditService $convertEntityChangesToAuditService
     * @param MessageProducerInterface $messageProducer
     */
    public function __construct(
        RegistryInterface $doctrine,
        ConvertEntityChangesToAuditService $convertEntityChangesToAuditService,
        MessageProducerInterface $messageProducer
    ) {
        $this->doctrine = $doctrine;
        $this->convertEntityChangesToAuditService = $convertEntityChangesToAuditService;
        $this->messageProducer = $messageProducer;
    }

    /**
     * {@inheritdoc}
     */
    public function process(MessageInterface $message, SessionInterface $session)
    {
        $body = JSON::decode($message->getBody());

        $loggedAt = \DateTime::createFromFormat('U', $body['timestamp']);
        $transactionId = $body['transaction_id'];

        /** @var AbstractUser|null $user */
        $user = null;
        if (isset($body['user_id'])) {
            $user = $this->doctrine->getRepository($body['user_class'])->find($body['user_id']);
        }

        /** @var Organization|null $organization */
        $organization = null;
        if (isset($body['organization_id'])) {
            $organization = $this->doctrine->getRepository(Organization::class)->find($body['organization_id']);
        }

        $this->convertEntityChangesToAuditService->convert(
            $body['entities_inserted'],
            $transactionId,
            $loggedAt,
            Audit::ACTION_CREATE,
            $user,
            $organization
        );

        $this->convertEntityChangesToAuditService->convert(
            $body['entities_updated'],
            $transactionId,
            $loggedAt,
            Audit::ACTION_UPDATE,
            $user,
            $organization
        );

        $this->convertEntityChangesToAuditService->convertSkipFields(
            $body['entities_deleted'],
            $transactionId,
            Audit::ACTION_REMOVE,
            $loggedAt,
            $user,
            $organization
        );

        $this->messageProducer->send(Topics::ENTITIES_RELATIONS_CHANGED, $body);
        $this->messageProducer->send(Topics::ENTITIES_INVERSED_RELATIONS_CHANGED, $body);

        return self::ACK;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedTopics()
    {
        return [Topics::ENTITIES_CHANGED];
    }
}
