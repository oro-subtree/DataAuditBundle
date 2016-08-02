<?php
namespace Oro\Bundle\DataAudit\Tests\Functional\Async;

use Doctrine\ORM\EntityManagerInterface;
use Oro\Bundle\DataAuditBundle\Async\AuditChangedEntitiesProcessor;
use Oro\Bundle\DataAuditBundle\Async\Topics;
use Oro\Bundle\DataAuditBundle\Entity\Audit;
use Oro\Bundle\TestFrameworkBundle\Entity\TestAuditDataOwner;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;
use Oro\Component\MessageQueue\Client\TraceableMessageProducer;
use Oro\Component\MessageQueue\Consumption\MessageProcessorInterface;
use Oro\Component\MessageQueue\Transport\Null\NullMessage;
use Oro\Component\MessageQueue\Transport\Null\NullSession;

class AuditChangedEntitiesProcessorTest extends WebTestCase
{
    protected function setUp()
    {
        parent::setUp();

        $this->initClient([], [], true);
        $this->startTransaction();
    }

    protected function tearDown()
    {
        parent::tearDown();

        $this->rollbackTransaction();
        self::$loadedFixtures = [];
    }

    public function testCouldBeGetFromContainerAsService()
    {
        /** @var AuditChangedEntitiesProcessor $processor */
        $processor = $this->getContainer()->get('oro_dataaudit.async.audit_changed_entities');
        
        $this->assertInstanceOf(AuditChangedEntitiesProcessor::class, $processor);
    }

    public function testShouldDoNothingIfAnythingChangedInMessage()
    {
        $message = $this->createMessage([
            'timestamp' => time(),
            'transaction_id' => 'aTransactionId',
            'entities_inserted' => [],
            'entities_updated' => [],
            'entities_deleted' => [],
            'collections_updated' => [],
        ]);

        /** @var AuditChangedEntitiesProcessor $processor */
        $processor = $this->getContainer()->get('oro_dataaudit.async.audit_changed_entities');

        $processor->process($message, new NullSession());

        $this->assertStoredAuditCount(0);
    }

    public function testShouldReturnAckOnProcess()
    {
        $message = $this->createMessage([
            'timestamp' => time(),
            'transaction_id' => 'aTransactionId',
            'entities_inserted' => [],
            'entities_updated' => [],
            'entities_deleted' => [],
            'collections_updated' => [],
        ]);

        /** @var AuditChangedEntitiesProcessor $processor */
        $processor = $this->getContainer()->get('oro_dataaudit.async.audit_changed_entities');

        $this->assertEquals(MessageProcessorInterface::ACK, $processor->process($message, new NullSession()));
    }

    public function testShouldSendSameMessageToProcessEntitiesRelationsAndInverseRelations()
    {
        $message = $this->createMessage([
            'timestamp' => time(),
            'transaction_id' => 'aTransactionId',
            'entities_inserted' => [],
            'entities_updated' => [],
            'entities_deleted' => [],
            'collections_updated' => [],
        ]);
        $expectedMessage = json_decode($message->getBody(), true);

        /** @var AuditChangedEntitiesProcessor $processor */
        $processor = $this->getContainer()->get('oro_dataaudit.async.audit_changed_entities');

        $processor->process($message, new NullSession());
        
        $traces = $this->getMessageProducer()->getTraces();
        $this->assertCount(2, $traces);
        
        $this->assertEquals(Topics::ENTITIES_RELATIONS_CHANGED, $traces[0]['topic']);
        $this->assertEquals($expectedMessage, $traces[0]['message']);

        $this->assertEquals(Topics::ENTITIES_INVERSED_RELATIONS_CHANGED, $traces[1]['topic']);
        $this->assertEquals($expectedMessage, $traces[1]['message']);
    }

    public function testShouldCreateAuditForInsertedEntity()
    {
        $expectedLoggedAt = new \DateTime('2012-02-01 03:02:01+0000');

        $message = $this->createMessage([
            'timestamp' => $expectedLoggedAt->getTimestamp(),
            'transaction_id' => 'aTransactionId',
            'entities_inserted' => [
                [
                    'entity_class' => TestAuditDataOwner::class,
                    'entity_id' => 123,
                    'change_set' => [
                        'stringProperty' => [null, 'aNewValue'],
                    ],
                ]
            ],
            'entities_updated' => [],
            'entities_deleted' => [],
            'collections_updated' => [],
        ]);

        /** @var AuditChangedEntitiesProcessor $processor */
        $processor = $this->getContainer()->get('oro_dataaudit.async.audit_changed_entities');

        $processor->process($message, new NullSession());

        $this->assertStoredAuditCount(1);
    }

    public function testShouldCreateAuditForUpdatedEntity()
    {
        $expectedLoggedAt = new \DateTime('2012-02-01 03:02:01+0000');

        $message = $this->createMessage([
            'timestamp' => $expectedLoggedAt->getTimestamp(),
            'transaction_id' => 'aTransactionId',
            'entities_inserted' => [],
            'entities_updated' => [
                [
                    'entity_class' => TestAuditDataOwner::class,
                    'entity_id' => 123,
                    'change_set' => [
                        'stringProperty' => [null, 'aNewValue'],
                    ],
                ]
            ],
            'entities_deleted' => [],
            'collections_updated' => [],
        ]);

        /** @var AuditChangedEntitiesProcessor $processor */
        $processor = $this->getContainer()->get('oro_dataaudit.async.audit_changed_entities');

        $processor->process($message, new NullSession());

        $this->assertStoredAuditCount(1);
    }

    public function testShouldCreateAuditForDeletedEntity()
    {
        $expectedLoggedAt = new \DateTime('2012-02-01 03:02:01+0000');

        $message = $this->createMessage([
            'timestamp' => $expectedLoggedAt->getTimestamp(),
            'transaction_id' => 'aTransactionId',
            'entities_inserted' => [],
            'entities_updated' => [],
            'entities_deleted' => [
                [
                    'entity_class' => TestAuditDataOwner::class,
                    'entity_id' => 123,
                    'change_set' => [],
                ]
            ],
            'collections_updated' => [],
        ]);

        /** @var AuditChangedEntitiesProcessor $processor */
        $processor = $this->getContainer()->get('oro_dataaudit.async.audit_changed_entities');

        $processor->process($message, new NullSession());

        $this->assertStoredAuditCount(1);
    }

    public function testShouldProcessAllChangedEntities()
    {
        $message = $this->createMessage([
            'timestamp' => time(),
            'transaction_id' => 'aTransactionId',
            'entities_inserted' => [
                [
                    'entity_class' => TestAuditDataOwner::class,
                    'entity_id' => 123,
                    'change_set' => [
                        'stringProperty' => [null, 'aNewValue'],
                    ],
                ]
            ],
            'entities_updated' => [
                [
                    'entity_class' => TestAuditDataOwner::class,
                    'entity_id' => 234,
                    'change_set' => [
                        'stringProperty' => [null, 'aNewValue'],
                    ],
                ]
            ],
            'entities_deleted' => [
                [
                    'entity_class' => TestAuditDataOwner::class,
                    'entity_id' => 345,
                    'change_set' => [],
                ]
            ],
            'collections_updated' => [],
        ]);

        /** @var AuditChangedEntitiesProcessor $processor */
        $processor = $this->getContainer()->get('oro_dataaudit.async.audit_changed_entities');

        $processor->process($message, new NullSession());

        $this->assertStoredAuditCount(3);
    }

    public function testShouldIncrementVersionWhenEntityChangedAgain()
    {
        /** @var AuditChangedEntitiesProcessor $processor */
        $processor = $this->getContainer()->get('oro_dataaudit.async.audit_changed_entities');

        $processor->process($this->createMessage([
            'timestamp' => time(),
            'transaction_id' => 'aTransactionId',
            'entities_updated' => [
                [
                    'entity_class' => TestAuditDataOwner::class,
                    'entity_id' => 123,
                    'change_set' => [
                        'stringProperty' => [null, 'aNewValue'],
                    ],
                ]
            ],
            'entities_inserted' => [],
            'entities_deleted' => [],
            'collections_updated' => [],
        ]), new NullSession());

        $this->assertStoredAuditCount(1);
        $audit = $this->findLastStoredAudit();
        $this->assertEquals(1, $audit->getVersion());

        $processor->process($this->createMessage([
            'timestamp' => time(),
            'transaction_id' => 'anotherTransactionId',
            'entities_updated' => [
                [
                    'entity_class' => TestAuditDataOwner::class,
                    'entity_id' => 123,
                    'change_set' => [
                        'stringProperty' => [null, 'aNewValue'],
                    ],
                ]
            ],
            'entities_inserted' => [],
            'entities_deleted' => [],
            'collections_updated' => [],
        ]), new NullSession());

        $this->assertStoredAuditCount(2);
        $audit = $this->findLastStoredAudit();
        $this->assertEquals(2, $audit->getVersion());
    }

    public function testShouldBeTolerantToMessageDuplication()
    {
        $message = $this->createMessage([
            'timestamp' => time(),
            'transaction_id' => 'aTransactionId',
            'entities_updated' => [
                [
                    'entity_class' => TestAuditDataOwner::class,
                    'entity_id' => 123,
                    'change_set' => [
                        'stringProperty' => [null, 'aNewValue'],
                    ],
                ]
            ],
            'entities_inserted' => [],
            'entities_deleted' => [],
            'collections_updated' => [],
        ]);

        /** @var AuditChangedEntitiesProcessor $processor */
        $processor = $this->getContainer()->get('oro_dataaudit.async.audit_changed_entities');

        $processor->process($message, new NullSession());
        $processor->process($message, new NullSession());
        $processor->process($message, new NullSession());

        $this->assertStoredAuditCount(1);
    }

    private function assertStoredAuditCount($expected)
    {
        $this->assertCount($expected, $this->getEntityManager()->getRepository(Audit::class)->findAll());
    }

    /**
     * @return Audit
     */
    private function findLastStoredAudit()
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('log')
            ->from(Audit::class, 'log')
            ->orderBy('log.id', 'DESC')
            ->setMaxResults(1)
        ;

        return $qb->getQuery()->getSingleResult();
    }

    /**
     * @param array $body
     * @return NullMessage
     */
    private function createMessage(array $body)
    {
        $message = new NullMessage();
        $message->setBody(json_encode($body));
        
        return $message;
    }

    /**
     * @return EntityManagerInterface
     */
    private function getEntityManager()
    {
        return $this->getContainer()->get('doctrine.orm.entity_manager');
    }

    /**
     * @return TraceableMessageProducer
     */
    private function getMessageProducer()
    {
        return $this->getClient()->getContainer()->get('oro_message_queue.client.message_producer');
    }
}
