<?php

namespace Oro\Bundle\DataAuditBundle\Filter;

use Doctrine\ORM\Query\Expr;

use LogicException;

use Symfony\Component\Form\FormFactoryInterface;

use Oro\Bundle\DataAuditBundle\Entity\AuditField;
use Oro\Bundle\DataAuditBundle\Form\Type\FilterType;
use Oro\Bundle\FilterBundle\Datasource\FilterDatasourceAdapterInterface;
use Oro\Bundle\FilterBundle\Datasource\Orm\OrmFilterDatasourceAdapter;
use Oro\Bundle\FilterBundle\Filter\EntityFilter;
use Oro\Bundle\FilterBundle\Filter\FilterUtility;
use Oro\Bundle\QueryDesignerBundle\QueryDesigner\Manager;

class AuditFilter extends EntityFilter
{
    const TYPE_CHANGED = 'changed';
    const TYPE_CHANGED_TO_VALUE = 'changed_to_value';

    /** @var Manager */
    protected $queryDesignerManager;

    /** @var string */
    protected $auditAlias;

    /** @var string */
    protected $auditFieldAlias;

    /**
     * @param FormFactoryInterface $factory
     * @param FilterUtility $util
     * @param Manager $queryDesignerManager
     */
    public function __construct(FormFactoryInterface $factory, FilterUtility $util, Manager $queryDesignerManager)
    {
        parent::__construct($factory, $util);
        $this->queryDesignerManager = $queryDesignerManager;
    }

    /**
     * {@inheritDoc}
     */
    public function apply(FilterDatasourceAdapterInterface $ds, $data)
    {
        $this->auditAlias = uniqid('a');
        $this->auditFieldAlias = uniqid('f');

        if (!$ds instanceof OrmFilterDatasourceAdapter) {
            throw new LogicException(sprintf(
                '"Oro\Bundle\FilterBundle\Datasource\Orm\OrmFilterDatasourceAdapter" expected but "%s" given.',
                get_class($ds)
            ));
        }

        $qb = $ds->getQueryBuilder();

        $fieldName = $this->getField($data['auditFilter']['columnName']);
        list($objectAlias) = $qb->getRootAliases();
        $objectClass = $this->getClass($data['auditFilter']['columnName'], $qb->getRootEntities());
        $metadata = $qb->getEntityManager()->getClassMetadata($objectClass);

        if ($metadata->isIdentifierComposite) {
            throw new \LogicException('Composite identifiers are not supported.');
        }

        $identifier = $metadata->getIdentifier()[0];

        $qb
            ->join(
                'Oro\Bundle\DataAuditBundle\Entity\Audit',
                $this->auditAlias,
                Expr\Join::WITH,
                sprintf(
                    '%s.objectClass = :objectClass AND %s.objectId = %s.%s',
                    $this->auditAlias,
                    $this->auditAlias,
                    $objectAlias,
                    $identifier
                )
            )
            ->join(
                sprintf('%s.fields', $this->auditAlias),
                $this->auditFieldAlias,
                Expr\Join::WITH,
                sprintf('%s.field = :field', $this->auditFieldAlias)
            )
            ->setParameter('objectClass', $objectClass)
            ->setParameter('field', $fieldName)
        ;

        $this->applyFilter($ds, 'datetime', sprintf('%s.loggedAt', $this->auditAlias), $data['auditFilter']['data']);
        $this->applyNewAuditValueFilter($ds, $objectClass, $fieldName, $data);
    }

    /**
     * @param OrmFilterDatasourceAdapter $ds
     * @param string $objectClass
     * @param string $fieldName
     * @param array $data
     */
    protected function applyNewAuditValueFilter(OrmFilterDatasourceAdapter $ds, $objectClass, $fieldName, array $data)
    {
        if ($data['auditFilter']['type'] !== static::TYPE_CHANGED_TO_VALUE) {
            return;
        }

        $metadata = $ds->getQueryBuilder()->getEntityManager()->getClassMetadata($objectClass);
        $type = $metadata->getTypeOfField($fieldName);
        if (!$type) {
            $type = 'text';
        }

        $newValueField = sprintf('new%s', ucfirst(AuditField::normalizeDataTypeName($type)));

        $this->applyFilter(
            $ds,
            $data['filter']['filter'],
            sprintf('%s.%s', $this->auditFieldAlias, $newValueField),
            $data['filter']['data']
        );
    }

    /**
     * @param FilterDatasourceAdapterInterface $ds
     * @param string $name
     * @param string $field
     * @param mixed $data
     */
    protected function applyFilter(FilterDatasourceAdapterInterface $ds, $name, $field, $data)
    {
        $filter = $this->queryDesignerManager->createFilter($name, [
            FilterUtility::DATA_NAME_KEY => $field,
        ]);

        $form = $filter->getForm();
        if (!$form->isSubmitted()) {
            $form->submit($data);
        }

        if ($form->isValid()) {
            $filter->apply($ds, $form->getData());
        }
    }

    /**
     * @param string $columnName
     * @param string[] $rootEntities
     *
     * @return string
     */
    protected function getClass($columnName, array $rootEntities)
    {
        if (strpos($columnName, '::') === false) {
            return reset($rootEntities);
        }

        $matches = [];
        preg_match_all('/(?<=\+)[^\+]+(?=::)/', $columnName, $matches);

        return end($matches[0]);
    }

    /**
     * @param string $columnName
     *
     * @return string
     */
    protected function getField($columnName)
    {
        list(, $fieldName) = explode('.', $this->get(FilterUtility::DATA_NAME_KEY));
        if (strpos($fieldName, '\\') === false) {
            return $fieldName;
        }

        $matches = [];
        preg_match('/^[^+]+/', $columnName, $matches);

        return $matches[0];
    }

    /**
     * {@inheritDoc}
     */
    public function getForm()
    {
        if (!$this->form) {
            $this->form = $this->formFactory->create($this->getFormType());
        }

        return $this->form;
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata()
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    protected function getFormType()
    {
        return FilterType::NAME;
    }
}
