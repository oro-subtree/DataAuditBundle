<?php

namespace Oro\Bundle\DataAuditBundle\Controller\Api\Soap;

use Symfony\Component\DependencyInjection\ContainerAware;
use Doctrine\Common\Persistence\ObjectManager;
use BeSimple\SoapBundle\ServiceDefinition\Annotation as Soap;

use Oro\Bundle\DataAuditBundle\Entity\Audit;

class AuditController extends ContainerAware
{
    /**
     * @Soap\Method("getAudits")
     * @Soap\Result(phpType = "Oro\Bundle\DataAuditBundle\Entity\Audit[]")
     */
    public function cgetAction()
    {
        return $this->getManager()->getRepository('OroDataAuditBundle:Audit')->findAll();
    }

    /**
     * @Soap\Method("getAudit")
     * @Soap\Param("id", phpType = "int")
     * @Soap\Result(phpType = "Oro\Bundle\DataAuditBundle\Entity\Audit")
     */
    public function getAction($id)
    {
        return $this->getEntity('OroDataAuditBundle:Audit', (int) $id);
    }

    /**
     * @Soap\Method("deleteAudit")
     * @Soap\Param("id", phpType = "int")
     * @Soap\Result(phpType = "boolean")
     */
    public function deleteAction($id)
    {
        $em = $this->getManager();
        $entity = $this->getEntity('OroDataAuditBundle:Audit', (int) $id);

        $em->remove($entity);
        $em->flush();

        return true;
    }

    /**
     * Shortcut to get entity
     *
     * @param  string     $repo
     * @param  int|string $id
     * @throws \SoapFault
     * @return AuditSoap
     */
    protected function getEntity($repo, $id)
    {
        $entity = $this->getManager()->find($repo, $id);

        if (!$entity) {
            throw new \SoapFault('NOT_FOUND', sprintf('Record #%u can not be found', $id));
        }

        return $entity;
    }

    /**
     * @return ObjectManager
     */
    protected function getManager()
    {
        return $this->container->get('doctrine.orm.entity_manager');
    }
}
