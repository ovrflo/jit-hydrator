<?php

namespace Ovrflo\JitHydrator;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\NotifyPropertyChanged;
use Doctrine\Common\Persistence\ObjectManagerAware;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Statement;
use Doctrine\DBAL\Types\Type;
use Doctrine\Instantiator\Instantiator;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Proxy\Proxy;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\UnitOfWork;

/**
 * @author Catalin Dan <dancatalin18@gmail.com>
 */
class HydratorGenerator
{
    public const OPTIMIZE_TYPE_CONVERSION = 1;

    private $classWriter;
    /** @var ResultSetMapping */
    private $rsm;
    /** @var Statement */
    private $stmt;
    /** @var array */
    private $hints;
    /** @var array */
    private $flags;

    private $metadataCache = [];
    /**
     * @var EntityManager
     */
    private $entityManager;
    /**
     * @var bool
     */
    private $debug;

    public function __construct(string $className, string $namespace, ResultSetMapping $rsm, $stmt, array $hints = [], EntityManager $entityManager, bool $debug = false)
    {
        $this->rsm           = $rsm;
        $this->stmt          = $stmt;
        $this->hints         = $hints;
        $this->entityManager = $entityManager;
        $this->flags = [
            self::OPTIMIZE_TYPE_CONVERSION => true,
        ];

        $this->classWriter = new ClassWriter($className, $namespace);
        $this->debug = $debug;
    }

    private function getClassMetadata(string $class)
    {
        return isset($this->metadataCache[$class]) ? $this->metadataCache[$class] : ($this->metadataCache[$class] = $this->entityManager->getClassMetadata($class));
    }

    public function dump(bool $forEval = false)
    {
        $rootEntities = array_diff(array_keys($this->rsm->aliasMap), array_keys($this->rsm->parentAliasMap));

        $this->classWriter
            ->addUse(Type::class)
            ->addUse(Proxy::class)
            ->addProperty('entityManager', 'private', null, '\\' . EntityManager::class)
            ->addProperty('databasePlatform', 'private', null, '\\' . AbstractPlatform::class)
            ->addProperty('unitOfWork', 'private', null, '\\' . UnitOfWork::class)
            ->addProperty('instantiator', 'private', null, '\\' . Instantiator::class)
            ->addProperty('identityMap', 'private', '[]', 'array')
        ;

        $classMetadataConstructorMap = [];

        $constructor = $this->classWriter->createMethod('__construct', ['entityManager', '\\' . EntityManager::class])->setVisibility('public');
        $constructor->writeln('// Statement: ' . $this->stmt->queryString ?? '');
        $constructor->writeln('$this->entityManager = $entityManager;');
        $constructor->writeln('$this->databasePlatform = $entityManager->getConnection()->getDatabasePlatform();');
        $constructor->writeln('$this->unitOfWork = $entityManager->getUnitOfWork();');
        $constructor->writeln('$this->instantiator = new \\' . Instantiator::class . '();');
        $constructor->writeln('$reflectionUow = new \\ReflectionClass(' . var_export(UnitOfWork::class, true) . ');');
        $constructor->writeln("foreach (['identityMap'] as \$propertyName) {");
        $constructor->indent();
        $constructor->writeln('$reflectionUow->getProperty($propertyName)->setAccessible(true);');
        $constructor->outdent();
        $constructor->writeln('}');

        $rowHydrateMethod = $this->classWriter->createMethod('hydrate', ['data', 'array'], ['result', null, null, true])->setVisibility('public');

        /** @var Method[] $hydrateMethods */
        $hydrateMethods = [];
        $classMetadata = [];
        $selectedEntities = [];
        foreach ($this->rsm->aliasMap as $alias => $entityClass) {
            if (!isset($selectedEntities[$entityClass])) {
                $selectedEntities[$entityClass] = true;
            }
            if (!isset($classMetadata[$entityClass])) {
                $classMetadata[$entityClass] = $this->getClassMetadata($entityClass);
            }
            if (!isset($classMetadataConstructorMap[$entityClass])) {
                $classMetadataConstructorMap[$entityClass] = [];
            }
            $classMetadataConstructorMap[$entityClass][] = $alias;
        }

        $aliasColumnMap = [];
        $joinedRelations = [];
        $inverseJoinedRelations = [];
        $aliasMetaMap = [];

        foreach ($this->rsm->parentAliasMap as $alias => $parentAlias) {
            if (!isset($joinedRelations[$parentAlias])) {
                $joinedRelations[$parentAlias] = [];
            }
            $joinedRelations[$parentAlias][$this->rsm->relationMap[$alias]] = $alias;
            $association = $this->getClassMetadata($this->rsm->aliasMap[$parentAlias])->getAssociationMapping($this->rsm->relationMap[$alias]);
            if (isset($association['inversedBy'])) {
                if (!isset($inverseJoinedRelations[$alias])) {
                    $inverseJoinedRelations[$alias] = [];
                }
                $inverseJoinedRelations[$alias][$association['inversedBy']] = $parentAlias;
            }
        }

        foreach ($this->rsm->fieldMappings as $key => $field) {
            if (!isset($aliasColumnMap[$this->rsm->columnOwnerMap[$key]])) {
                $aliasColumnMap[$this->rsm->columnOwnerMap[$key]] = [];
            }
            $aliasColumnMap[$this->rsm->columnOwnerMap[$key]][$field] = $key;
        }

        foreach ($this->rsm->metaMappings as $key => $field) {
            if (!isset($aliasMetaMap[$this->rsm->columnOwnerMap[$key]])) {
                $aliasMetaMap[$this->rsm->columnOwnerMap[$key]] = [];
            }
            $aliasMetaMap[$this->rsm->columnOwnerMap[$key]][$field] = $key;
        }

        $identityMap = [];

        foreach ($aliasColumnMap as $alias => $fields) {
            $entityClass = $this->rsm->aliasMap[$alias];
            $classMetadata = $this->getClassMetadata($entityClass);
            $identityMap[$entityClass] = [];

            $hydrateMethod = $this->classWriter->createMethod('newEntity_' . $alias);
            $hydrateMethod
                ->addArgument('data', 'array')
                ->addArgument('proxy', 'Proxy', 'null')
                ->setReturnType('\\' . $entityClass)
                ->addThrows('\\' . DBALException::class)
            ;
            $hydrateMethod->writeln(sprintf('$classMetadata = ' . $this->getMetadataPropertyName($entityClass) . ';'));
            $idHash = [];
            foreach ($classMetadata->getIdentifierFieldNames() as $identifierFieldName) {
                $idHash[] = '$data[' . var_export($fields[$identifierFieldName], true) . ']';
            }
            $hydrateMethod->writeln(sprintf('$idHash = ' . implode(" . ' ' . ", $idHash) . ';'));
            $hydrateMethod->writeln(sprintf('$result = $proxy ?? $this->instantiator->instantiate(' . var_export($entityClass, true) . ');'));
            if ($classMetadata->reflClass->implementsInterface(ObjectManagerAware::class)) {
                $hydrateMethod->writeln(sprintf('$result->injectObjectManager($this->entityManager, $classMetadata);'));
            }
            if ($classMetadata->reflClass->implementsInterface(NotifyPropertyChanged::class)) {
                $hydrateMethod->writeln(sprintf('$result->addPropertyChangedListener($this->unitOfWork);'));
            }

            $hydrateMethod->writeln('$entityData = [];');
            foreach ($fields as $field => $column) {
                $hydrateMethod->writeln('// hydrate ' . $alias . '.' . $field);
                $exportedKey = var_export($column, true);
                if (isset($this->flags[self::OPTIMIZE_TYPE_CONVERSION]) && $this->flags[self::OPTIMIZE_TYPE_CONVERSION]) {
                    switch ($classMetadata->fieldMappings[$field]['type']) {
                        case Type::TEXT:
                        case Type::STRING:
                            $hydrateMethod->writeln('$value = $entityData[' . var_export($field, true) . '] = $data[' . $exportedKey . '];');
                            break;
                        case Type::BOOLEAN:
                            $hydrateMethod->writeln('$value = $entityData[' . var_export($field, true) . '] = (null === $data[' . $exportedKey . ']) ? null : (bool) $data[' . $exportedKey . '];');
                            break;
                        case Type::BIGINT:
                            $hydrateMethod->writeln('$value = $entityData[' . var_export($field, true) . '] = (null === $data[' . $exportedKey . ']) ? null : (string) $data[' . $exportedKey . '];');
                            break;
                        case Type::INTEGER:
                        case Type::SMALLINT:
                            $hydrateMethod->writeln('$value = $entityData[' . var_export($field, true) . '] = (null === $data[' . $exportedKey . ']) ? null : (int) $data[' . $exportedKey . '];');
                            break;
                        case Type::DECIMAL:
                        case Type::FLOAT:
                            $hydrateMethod->writeln('$value = $entityData[' . var_export($field, true) . '] = (null === $data[' . $exportedKey . ']) ? null : (float) $data[' . $exportedKey . '];');
                            break;
                        case Type::SIMPLE_ARRAY:
                            $hydrateMethod->writeln('$value = $entityData[' . var_export($field, true) . '] = (null === $data[' . $exportedKey . ']) ? [] : (is_resource($data[' . $exportedKey . ']) ? explode(\',\', stream_get_contents($value)) : explode(\',\', $data[' . $exportedKey . ']));');
                            break;
                        case Type::DATETIME:
                        case Type::DATETIME_IMMUTABLE:
                            $dateTimeClass = $classMetadata->fieldMappings[$field]['type'] === Type::DATETIME ? \DateTime::class : \DateTimeImmutable::class;
                            $hydrateMethod->writeln('$value = $data[' . $exportedKey . '];');
                            $hydrateMethod->writeln('$value = (null === $value || $value instanceof \\DateTimeInterface) ? $value : \\' . $dateTimeClass . '::createFromFormat(' . var_export($this->entityManager->getConnection()->getDatabasePlatform()->getDateTimeFormatString(), true) . ', $value);');
                            $hydrateMethod->writeln('$value = $entityData[' . var_export($field, true) . '] = $value ?: \\date_create($data[' . $exportedKey . ']);');
                            break;
                        default:
                            $hydrateMethod->writeln('$value = $entityData[' . var_export($field, true) . '] = Type::getType(' . var_export($classMetadata->fieldMappings[$field]['type'], true) . ')->convertToPHPValue($data[' . var_export($column, true) . '], $this->databasePlatform);');
                    }
                } else {
                    $hydrateMethod->writeln('$value = $entityData[' . var_export($field, true) . '] = Type::getType(' . var_export($classMetadata->fieldMappings[$field]['type'], true) . ')->convertToPHPValue($data[' . var_export($column, true) . '], $this->databasePlatform);');
                }
                $hydrateMethod->writeln(sprintf('$classMetadata->reflFields[' . var_export($field, true) . ']->setValue($result, $value);'));
                $hydrateMethod->writeln();
            }

            if (count($classMetadata->getAssociationMappings())) {
                foreach ($classMetadata->getAssociationMappings() as $name => $mapping) {
                    $targetEntityClass = $mapping['targetEntity'];
                    $targetClassMetadata = $this->getClassMetadata($targetEntityClass);
                    $classMetadataConstructorMap[$targetEntityClass][] = $alias . '_' . $name;
                    switch ($mapping['type']) {
                        case ClassMetadata::ONE_TO_ONE:
                        case ClassMetadata::MANY_TO_ONE:
                            $hydrateMethod->writeln('// hydrate ' . ($mapping['type'] === ClassMetadata::ONE_TO_ONE ? 'one' : 'many') . '-to-one ' . $name);
                            if (!isset($joinedRelations[$alias][$name])) {
                                $metaColumnsIncluded = count(array_diff(array_keys($mapping['sourceToTargetKeyColumns']), array_keys($aliasMetaMap[$alias]))) === 0;
                                $column = $aliasMetaMap[$alias][array_keys($mapping['sourceToTargetKeyColumns'])[0]];
                                if ($metaColumnsIncluded) {
                                    $ifColumns = [];
                                    foreach (array_keys($mapping['sourceToTargetKeyColumns']) as $joinColumnName) {
                                        $ifColumns[] = '$data[' . var_export($aliasMetaMap[$alias][$joinColumnName], true) . ']';
                                    }
                                    $hydrateMethod->writeIf(implode(' !== null || ', $ifColumns) . ' !== null');
                                    $hydrateMethod->writeln('$idHash = ' . implode(" . ' ' . ", $ifColumns) . ';');
                                    $hydrateMethod
                                        ->writeIf('isset($this->identityMap[' . var_export($targetEntityClass, true) . '][$idHash])')
                                        ->writeln('$proxy_' . $alias . '_' . $name . ' = $this->identityMap[' . var_export($targetEntityClass, true) . '][$idHash];')
                                        ->writeElseIf('$proxy_' . $alias . '_' . $name . ' = $this->unitOfWork->tryGetByIdHash($idHash, ' . var_export($targetEntityClass, true) . ')')
                                        ->writeln('$this->identityMap[' . var_export($targetEntityClass, true) . '][$idHash] = $proxy_' . $alias . '_' . $name . ';')
                                        ->writeElse()
                                    ;
                                    $hydrateMethod->writeln('$reference = [')->indent();
                                    foreach ($targetClassMetadata->getIdentifier() as $identifierColumn) {
                                        $hydrateMethod->writeln(var_export($identifierColumn, true) . ' => Type::getType(' . var_export($this->rsm->typeMappings[$column], true) . ')->convertToPHPValue($data[' . var_export($column, true) . '], $this->databasePlatform),');
                                    }
                                    $hydrateMethod->outdent()->writeln('];');
                                    $hydrateMethod->writeln('$proxy_' . $alias . '_' . $name . ' = $this->identityMap[' . var_export($targetEntityClass, true) . '][$idHash] =  $this->entityManager->getProxyFactory()->getProxy(' . var_export($targetEntityClass, true) . ', $reference);');
                                    $hydrateMethod->writeEndif();
                                    $hydrateMethod->writeln($this->getMetadataPropertyName($classMetadata->name) . '->reflFields[' . var_export($name, true) . ']->setValue($result, $proxy_' . $alias . '_' . $name . ');');
                                    $hydrateMethod->writeEndif();
                                }
                            }
                            break;
                        case ClassMetadata::ONE_TO_MANY:
                        case ClassMetadata::MANY_TO_MANY:
                            $hydrateMethod->writeln('// hydrate ' . ($mapping['type'] === ClassMetadata::ONE_TO_MANY ? 'one' : 'many') . '-to-many ' . $name);
                            $hydrateMethod->writeln('$collection_' . $name . ' = (new \\' . PersistentCollection::class . '($this->entityManager, ' . $this->getMetadataPropertyName($targetEntityClass) . ', new \\' . ArrayCollection::class . '()));');
                            $hydrateMethod->writeln('$collection_' . $name . '->setOwner($result, ' . var_export($mapping, true) . ');');
                            $hydrateMethod->writeln('$classMetadata->reflFields[' . var_export($name, true) . ']->setValue($result, $collection_' . $name . ');');
                            if (isset($inverseJoinedRelations[$alias][$name])) {
                            }
                            break;
                    }
                    $hydrateMethod->writeln('');
                }
            }

            $idHash = [];
            foreach ($classMetadata->getIdentifierFieldNames() as $identifierFieldName) {
                $idHash[] = var_export($identifierFieldName, true) . ' => $data[' . var_export($fields[$identifierFieldName], true) . ']';
            }
            $hydrateMethod->writeln('$this->unitOfWork->registerManaged($result, [' . implode(" . ' ' . ", $idHash) . '], $entityData);');

            $hydrateMethods[$alias] = $hydrateMethod;
        }
        $constructor->writeln('$this->identityMap = [')->indent();
        foreach (array_keys($identityMap) as $entityClass) {
            $constructor->writeln(var_export($entityClass, true) . ' => [],');
        }
        $constructor->outdent()->writeln('];');

        foreach ($aliasColumnMap as $alias => $fields) {
            $entityClass = $this->rsm->aliasMap[$alias];
            $entityClassEscaped = var_export($entityClass, true);
            $classMetadata = $this->getClassMetadata($entityClass);
            $idHash = [];
            foreach ($classMetadata->getIdentifierFieldNames() as $identifierFieldName) {
                $idHash[] = '$data[' . var_export($fields[$identifierFieldName], true) . ']';
            }
            $rowHydrateMethod->writeln(sprintf('$idHash = ' . implode(" . ' ' . ", $idHash) . ';'));
            $rowHydrateMethod
                ->writeln('$new_entity_' . $alias . ' = false;')
                ->writeIf(implode(' === null && ', $idHash) . ' === null ')
                ->writeln('$entity_' . $alias . ' = null;')
                ->writeElseIf('$uow_entity_' . $alias . ' = $this->unitOfWork->tryGetByIdHash($idHash, ' . $entityClassEscaped . ')')
                ->writeln('$entity_' . $alias . ' = $this->identityMap[' . $entityClassEscaped . '][$idHash] = $uow_entity_' . $alias . ';')
                ->writeln('$new_entity_' . $alias . ' = true;')
                ->writeElseIf('!isset($this->identityMap[' . $entityClassEscaped . '][$idHash])')
                ->call($hydrateMethods[$alias], ['data' => '$data'], [
                    'inline' => false,
                    'assign' => '$entity_' . $alias . ' = $this->identityMap[' . $entityClassEscaped . '][$idHash]',
                ])
                ->writeln('$new_entity_' . $alias . ' = true;')
                ->writeElse()
                ->writeln('$entity_' . $alias . ' = $this->identityMap[' . $entityClassEscaped . '][$idHash];')
                ->writeIf('$entity_' . $alias . ' instanceof Proxy && !$entity_' . $alias . '->__isInitialized__')
                ->writeln('$this->newEntity_' . $alias . '($data, $entity_' . $alias . ');')
                ->writeln('$entity_' . $alias . '->__isInitialized__ = true;')
                ->writeln('$entity_' . $alias . '->__initializer__ = $entity_' . $alias .'->__cloner__ = null;')
                ->writeEndif()
                ->writeEndif()
            ;

            $rowHydrateMethod->writeln('');
        }

        foreach ($aliasColumnMap as $alias => $fields) {
            $entityClass = $this->rsm->aliasMap[$alias];
            $classMetadata = $this->getClassMetadata($entityClass);


            if (\count($classMetadata->getAssociationMappings())) {
                $rowHydrateMethod
                    ->writeln('')
                    ->writeln(sprintf('// hydrating associations for %s - %s', $alias, $entityClass))
                    ->writeIf('$entity_' . $alias . ' !== null')
                ;
                foreach ($classMetadata->getAssociationMappings() as $name => $mapping) {
                    $targetEntityClass = $mapping['targetEntity'];
                    $targetClassMetadata = $this->getClassMetadata($targetEntityClass);
                    switch ($mapping['type']) {
                        case ClassMetadata::MANY_TO_ONE:
                        case ClassMetadata::ONE_TO_ONE:
                            if (isset($joinedRelations[$alias][$name])) {
                                $rowHydrateMethod->writeIf('$new_entity_' . $alias . ' && $entity_' . $joinedRelations[$alias][$name]);
                                $rowHydrateMethod->writeln(sprintf($this->getMetadataPropertyName($classMetadata->name) . '->reflFields[' . var_export($name, true) . ']->setValue($entity_' . $alias . ', $entity_' . $joinedRelations[$alias][$name] . ');'));
                                $rowHydrateMethod->writeEndif();
                            }
                            break;
                        case ClassMetadata::ONE_TO_MANY:
                        case ClassMetadata::MANY_TO_MANY:
                            if (isset($joinedRelations[$alias][$name])) {
                                $rowHydrateMethod->writeIf('$new_entity_' . $joinedRelations[$alias][$name]);
                                $rowHydrateMethod->writeln('$collection_' . $alias . '_' . $name . ' = ' . $this->getMetadataPropertyName($classMetadata->name) . '->reflFields[' . var_export($name, true) . ']->getValue($entity_' . $alias . ');');
                                $rowHydrateMethod->writeln('$collection_' . $alias . '_' . $name . '->hydrateAdd($entity_' . $joinedRelations[$alias][$name] . ');');
                                $rowHydrateMethod->writeEndif();
                            } elseif (isset($inverseJoinedRelations[$alias][$name])) {
                                $rowHydrateMethod->writeIf('$new_entity_' . $inverseJoinedRelations[$alias][$name]);
                                $rowHydrateMethod->writeln('$collection_' . $alias . '_' . $name . ' = ' . $this->getMetadataPropertyName($classMetadata->name) . '->reflFields[' . var_export($name, true) . ']->getValue($entity_' . $alias . ');');
                                $rowHydrateMethod->writeln('$collection_' . $alias . '_' . $name . '->hydrateAdd($entity_' . $inverseJoinedRelations[$alias][$name] . ');');
                                $rowHydrateMethod->writeEndif();
                            }
                            break;
                    }
                }
                $rowHydrateMethod->writeEndif();
            }
        }

        foreach ($classMetadataConstructorMap as $class => $names) {
            $metadataPropertyName = $this->getMetadataPropertyName($class, true);
            $this->classWriter->addProperty($metadataPropertyName, 'private', null, '\\' . ClassMetadata::class, false, 'for ' . $class);
            $constructor->writeln('$this->' . $metadataPropertyName . ' = $this->entityManager->getClassMetadata(' . var_export($class, true) . ');');
        }

        foreach ($hydrateMethods as $entityClass => $method) {
            $method->writeln('return $result;');
        }

        if (\count($rootEntities) === 1) {
            if (!count($this->rsm->scalarMappings)) {
                $rowHydrateMethod->writeIf('$new_entity_' . $rootEntities[0]);
                $rowHydrateMethod->writeln('$result[] = $entity_' . $rootEntities[0] . ';');
                $rowHydrateMethod->writeEndif();
            } else {
                $rowHydrateMethod->writeln('$result[] = [')->indent();
                $rowHydrateMethod->writeln('$entity_' . $rootEntities[0] . ',');

                foreach ($this->rsm->scalarMappings as $columnName => $fieldName) {
                    $rowHydrateMethod->writeln(var_export($fieldName, true) . ' => $data[' . var_export($columnName, true) . '],');
                }

                $rowHydrateMethod->outdent()->writeln('];');
            }
        } else {
            $rowHydrateMethod->writeln('$result[] = [')->indent();
            foreach ($aliasColumnMap as $alias => $fields) {
                $rowHydrateMethod->writeln(var_export($alias, true) . ' => $entity_' . $alias . ',');
            }

            foreach ($this->rsm->scalarMappings as $columnName => $fieldName) {
                $rowHydrateMethod->writeln(var_export($fieldName, true) . ' => $data[' . var_export($columnName, true) . '],');
            }

            $rowHydrateMethod->outdent()->writeln('];');
        }

        return $this->classWriter->dump($forEval);
    }

    private function getMetadataPropertyName(string $class, bool $onlyName = false)
    {
        return (!$onlyName ? '$this->' : '') . 'metadata_' . str_replace('\\', '_', strtolower($class));
    }
}
