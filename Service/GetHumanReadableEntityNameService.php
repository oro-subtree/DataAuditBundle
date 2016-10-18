<?php
namespace Oro\Bundle\DataAuditBundle\Service;

use Doctrine\Common\Persistence\ManagerRegistry;
use Oro\Bundle\DataAuditBundle\Entity\AbstractAudit;
use Oro\Bundle\DataAuditBundle\Entity\Repository\AuditRepository;
use Oro\Bundle\EntityBundle\Provider\EntityNameResolver;

class GetHumanReadableEntityNameService
{
    /**
     * @var ManagerRegistry
     */
    private $doctrine;

    /**
     * @var EntityNameResolver
     */
    private $entityNameResolver;

    /**
     * @param ManagerRegistry $doctrine
     * @param EntityNameResolver $entityNameResolver
     */
    public function __construct(ManagerRegistry $doctrine, EntityNameResolver $entityNameResolver)
    {
        $this->doctrine = $doctrine;
        $this->entityNameResolver = $entityNameResolver;
    }

    /**
     * @param string $entityClass
     * @param int $entityId
     *
     * @return string
     */
    public function getName($entityClass, $entityId)
    {
        $entity = $this->doctrine->getManagerForClass($entityClass)->find($entityClass, $entityId);
        if ($entity) {
            return $this->entityNameResolver->getName($entity);
        }

        /** @var AuditRepository $auditRepository */
        $auditRepository = $this->doctrine->getRepository(AbstractAudit::class);
        $entityAudit = $auditRepository->findLastAuditForEntity($entityClass, $entityId);
        if ($entityAudit && $entityAudit->getObjectName()) {
            return $entityAudit->getObjectName();
        }

        return sprintf('%s::%s', (new \ReflectionClass($entityClass))->getShortName(), $entityId);
    }
}
