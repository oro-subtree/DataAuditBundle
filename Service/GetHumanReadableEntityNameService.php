<?php
namespace Oro\Bundle\DataAuditBundle\Service;

use Oro\Bundle\DataAuditBundle\Entity\AbstractAudit;
use Oro\Bundle\DataAuditBundle\Entity\Repository\AuditRepository;
use Oro\Bundle\EntityBundle\Provider\EntityNameProvider;
use Oro\Bundle\EntityBundle\Provider\EntityNameResolver;
use Symfony\Bridge\Doctrine\RegistryInterface;

class GetHumanReadableEntityNameService
{
    /**
     * @var RegistryInterface
     */
    private $doctrine;

    /**
     * @var EntityNameProvider
     */
    private $entityNameResolver;

    /**
     * @param RegistryInterface $doctrine
     * @param EntityNameResolver $entityNameResolver
     */
    public function __construct(RegistryInterface $doctrine, EntityNameResolver $entityNameResolver)
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
            if (method_exists($entity, '__toString')) {
                return (string) $entity;
            }
            
            if ($entityName = $this->entityNameResolver->getName($entity)) {
                return $entityName;
            }
        }

        if (!$entity) {
            /** @var AuditRepository $auditRepository */
            $auditRepository = $this->doctrine->getRepository(AbstractAudit::class);
            $entityAudit = $auditRepository->findLastAuditForEntity($entityClass, $entityId);
            if ($entityAudit && $entityAudit->getObjectName()) {
                return $entityAudit->getObjectName();
            }
        }

        return sprintf('%s::%s', (new \ReflectionClass($entityClass))->getShortName(), $entityId);
    }
}
