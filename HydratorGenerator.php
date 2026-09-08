<?php

namespace Ovrflo\JitHydrator;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Event\ListenersInvoker;
use Doctrine\ORM\Internal\HydrationCompleteHandler;
use Doctrine\ORM\Query;
use Doctrine\Persistence\NotifyPropertyChanged;
use Doctrine\Persistence\ObjectManagerAware;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Statement;
use Doctrine\DBAL\Types\Type;
use Doctrine\Instantiator\Instantiator;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Proxy\ProxyFactory;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\Proxy;

/**
 * @author Catalin Dan <dancatalin18@gmail.com>
 */
class HydratorGenerator
{
    private $classWriter;
    /** @var ResultSetMapping */
    private $rsm;
    /** @var Statement|Result */
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

    public function __construct(string $className, string $namespace, ResultSetMapping $rsm, $stmt, array $hints, EntityManager $entityManager, bool $debug = false, array $flags = [])
    {
        $this->rsm           = $rsm;
        $this->stmt          = $stmt;
        $this->hints         = $hints;
        $this->entityManager = $entityManager;
        $this->flags = array_replace([
            JitObjectHydrator::JIT_FLAG_OPTIMIZE_TYPE_CONVERSION => true,
            JitObjectHydrator::JIT_FLAG_STRICT_TYPES => true,
            JitObjectHydrator::JIT_FLAG_PROPERTY_TYPE_HINT => true,
        ], $flags);

        $this->classWriter = new ClassWriter($className, $namespace, $this->flags[JitObjectHydrator::JIT_FLAG_STRICT_TYPES], $this->flags[JitObjectHydrator::JIT_FLAG_PROPERTY_TYPE_HINT]);
        $this->classWriter->implements(GeneratedObjectHydrator::class);
        $this->debug = $debug;
    }

    private function getClassMetadata(string $class)
    {
        return isset($this->metadataCache[$class]) ? $this->metadataCache[$class] : ($this->metadataCache[$class] = $this->entityManager->getClassMetadata($class));
    }

    public function dump(bool $forEval = false)
    {
        $objectManagerAwareExists = class_exists(ObjectManagerAware::class);
        $rootEntities = array_diff(array_keys($this->rsm->aliasMap), array_keys($this->rsm->parentAliasMap));
        $isLazyGhostProxy = method_exists($this->entityManager->getConfiguration(), 'isLazyGhostObjectEnabled') && $this->entityManager->getConfiguration()->isLazyGhostObjectEnabled();
        $hasPropertyAccessor = property_exists(ClassMetadata::class, 'propertyAccessors');
        $isNativeProxy = method_exists($this->entityManager->getConfiguration(), 'isNativeLazyObjectsEnabled') && $this->entityManager->getConfiguration()->isNativeLazyObjectsEnabled();
        $propertyAccessors = $hasPropertyAccessor ? 'propertyAccessors' : 'reflFields';

        // Doctrine ORM 3.7 (GH-12210) made PARTIAL entities work as native lazy ghosts: only the
        // selected fields are populated, and accessing any other field transparently triggers a
        // full reload. ResultSetMapping::$partialAliases and UnitOfWork::$partialObjectLoadedFields
        // both landed in that exact release, so their presence is used as the feature fingerprint
        // rather than guessing at version numbers. Without native lazy objects (ORM 2.x, 3.0-3.6.x,
        // or 3.7+ with the feature disabled), PARTIAL queries keep working exactly as they always
        // have: unselected fields are simply never written, matching stock Doctrine's older behavior.
        $partialAliasesSupported = property_exists(ResultSetMapping::class, 'partialAliases');
        $partialObjectLoadedFieldsSupported = property_exists(UnitOfWork::class, 'partialObjectLoadedFields');
        $registerManagedProxySupported = method_exists(UnitOfWork::class, 'registerManagedProxy');
        $supportsPartialLazyGhosts = $isNativeProxy && $partialAliasesSupported && $partialObjectLoadedFieldsSupported;

        // Whether THIS query actually has a PARTIAL alias - as opposed to $supportsPartialLazyGhosts,
        // which just says the environment is capable of it. Class-level scaffolding (the partialGhosts
        // property, the extra reflectionUow property name below) is gated on this, not on the coarser
        // environment flag, so that an ordinary non-PARTIAL query run under ORM 3.7 generates the exact
        // same hydrator it always has - not one carrying always-empty, never-used scaffolding.
        $anyPartialAlias = false;
        if ($supportsPartialLazyGhosts) {
            foreach ($this->rsm->aliasMap as $alias => $entityClass) {
                if ($this->rsm->partialAliases[$alias] ?? false) {
                    $anyPartialAlias = true;
                    break;
                }
            }
        }

        $this->classWriter
            ->addUse(Type::class)
            ->addProperty('entityManager', 'private', null, '\\' . EntityManager::class)
            ->addProperty('databasePlatform', 'private', null, '\\' . AbstractPlatform::class)
            ->addProperty('unitOfWork', 'private', null, '\\' . UnitOfWork::class)
            ->addProperty('instantiator', 'private', null, '\\' . Instantiator::class)
            ->addProperty('proxyFactory', 'private', null, '\\' . ProxyFactory::class)
            ->addProperty('identityMap', 'private', '[]', 'array')
            ->addProperty('typeMap', 'private', '[]', 'array', false, false, 'array of ' . Types::class)
            ->addProperty('reflectionUow', 'private', null, '\\ReflectionClass', false, false)
        ;

        if ((true === ($this->hints[Query::HINT_REFRESH] ?? null)) || isset($this->hints[Query::HINT_REFRESH_ENTITY])) {
            $this->classWriter->addProperty('refreshedEntities', 'private', '[]', 'array', false, false, 'array of object');
        }

        if ($anyPartialAlias) {
            // Tracks OIDs of entities WE deliberately created as partial lazy ghosts (as opposed to
            // a to-one association reference stub, which also appears as isUninitializedLazyObject()
            // === true). Without this distinction, re-encountering the same partial entity on a later
            // row (e.g. via a fetch-joined to-many collection) would look identical to "a stub that
            // now has real data to fill in", and get force-initialized with the same (still partial)
            // row data - stranding its other fields as permanently inaccessible instead of leaving
            // them lazily reloadable.
            $this->classWriter->addProperty('partialGhosts', 'private', '[]', 'array', false, false, 'array of true, keyed by spl_object_id');
            // Resolved once here instead of via reflectionUow->getProperty() on every partial-ghost
            // creation, which would otherwise redo the same reflection lookup on every row.
            $this->classWriter->addProperty('partialObjectLoadedFieldsProperty', 'private', null, '\\ReflectionProperty', false, false);
        }

        if (!$isNativeProxy) {
            $this->classWriter->addUse(Proxy::class);
        }

        $classMetadataConstructorMap = [];

        $constructor = $this->classWriter->createMethod('__construct', ['entityManager', '\\' . EntityManager::class])->setVisibility('public');
        $queryString = null;
        if ($this->stmt instanceof Result && method_exists($this->stmt, 'getIterator')) {
            $queryString = $this->stmt->getIterator()->queryString ?? null;
        } elseif ($this->stmt instanceof Statement) {
            $queryString = $this->stmt->queryString ?? null;
        }
        if (class_exists(HydrationCompleteHandler::class)) {
            $this->classWriter
                ->addUse(HydrationCompleteHandler::class)
                ->addUse(ListenersInvoker::class)
                ->addProperty('listenersInvoker', 'private', null, '\\' . ListenersInvoker::class)
                ->addProperty('hydrationCompleteHandler', 'private', null, '\\' . HydrationCompleteHandler::class)
            ;
            $constructor
                ->writeln('$this->listenersInvoker = new ListenersInvoker($entityManager);')
                ->writeln('$this->hydrationCompleteHandler = new HydrationCompleteHandler($this->listenersInvoker, $entityManager);')
            ;
        }
        $constructor->writeln('// Statement: ' . ($queryString ?? 'unknown'));
        $constructor->writeln('$this->entityManager = $entityManager;');
        $constructor->writeln('$this->databasePlatform = $entityManager->getConnection()->getDatabasePlatform();');
        $constructor->writeln('$this->unitOfWork = $entityManager->getUnitOfWork();');
        $constructor->writeln('$this->instantiator = new \\' . Instantiator::class . '();');
        $constructor->writeln('$this->proxyFactory = $entityManager->getProxyFactory();');
        $constructor->writeln('$this->reflectionUow = new \\ReflectionClass(' . var_export(UnitOfWork::class, true) . ');');
        // 'identityMap' (UnitOfWork's own, unrelated to $this->identityMap above) is deliberately not
        // in this list: nothing in the generated class ever reads or writes it via reflection, so
        // making it accessible would be pure wasted work on every construction/cleanup on PHP < 8.1.
        $reflectionUowProperties = ['eagerLoadingEntities'];
        if ($anyPartialAlias) {
            $reflectionUowProperties[] = 'partialObjectLoadedFields';
        }
        // Built as a single-line literal (not var_export(), which line-wraps arrays) so that
        // generated output for versions without partialObjectLoadedFields - i.e. every ORM 2.x
        // and 3.0-3.6.x install - stays byte-identical to before this property was introduced.
        $reflectionUowPropertiesLiteral = '[' . implode(', ', array_map(static fn (string $name) => var_export($name, true), $reflectionUowProperties)) . ']';
        $constructor->writeln('foreach (' . $reflectionUowPropertiesLiteral . ' as $propertyName) {');
        $constructor->indent();
        if (PHP_VERSION_ID < 80100) {
            $constructor->writeln('$this->reflectionUow->getProperty($propertyName)->setAccessible(true);');
        }
        $constructor->outdent();
        $constructor->writeln('}');
        if ($anyPartialAlias) {
            $constructor->writeln('$this->partialObjectLoadedFieldsProperty = $this->reflectionUow->getProperty(\'partialObjectLoadedFields\');');
        }

        $cleanupMethod = $this->classWriter->createMethod('cleanup')->setVisibility('public');
        if (PHP_VERSION_ID < 80100) {
            $cleanupMethod->writeln('foreach (' . $reflectionUowPropertiesLiteral . ' as $propertyName) {')->indent()
                ->writeln('$this->reflectionUow->getProperty($propertyName)->setAccessible(false);')
                ->outdent()->writeln('}')
            ;
        }

        $rowHydrateMethod = $this->classWriter->createMethod('hydrate', ['data', 'array'], ['result', null, null, true])->setVisibility('public');

        /** @var Method[] $hydrateMethods */
        $hydrateMethods = [];
        $classMetadata = [];
        $selectedEntities = [];
        $globalInitializedTypes = [];
        /** @var array<string, array{0: string, 1: string}> propertyName => [entityClass, field] */
        $fastFieldAccessors = [];
        // Whether any deferred-eager-load block below ends up emitted; if so, the ReflectionProperty
        // for UnitOfWork::$eagerLoadingEntities is resolved once in the constructor (see below) instead
        // of being re-fetched via reflectionUow->getProperty() every time a matching row is hydrated.
        $needsEagerLoadingEntitiesProperty = false;
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
        /** @var array<string, bool> alias => whether it's hydrated as a partial native-lazy-ghost */
        $partialAliasFlags = [];

        foreach ($aliasColumnMap as $alias => $fields) {
            $entityClass = $this->rsm->aliasMap[$alias];
            $classMetadata = $this->getClassMetadata($entityClass);
            $identityMap[$entityClass] = [];
            $isPartialAlias = $supportsPartialLazyGhosts && ($this->rsm->partialAliases[$alias] ?? false);
            $partialAliasFlags[$alias] = $isPartialAlias;

            $hydrateMethod = $this->classWriter->createMethod('newEntity_' . $alias);
            $hydrateMethod
                ->addArgument('data', 'array')
                ->addArgument('proxy', $isNativeProxy ? '?object' : '?Proxy', 'null')
                ->setReturnType('\\' . $entityClass)
                ->addThrows('\\' . self::getDbalExceptionClass())
            ;
            $hydrateMethod->writeln(sprintf('$classMetadata = ' . $this->getMetadataPropertyName($entityClass) . ';'));
            $identifierDataKeys = $this->resolveIdentifierFieldDataKeys($classMetadata, $alias, $fields, $aliasMetaMap);
            $idHash = [];
            $identifierPairs = [];
            foreach ($identifierDataKeys as $identifierFieldName => $field) {
                $idHash[] = '$data[' . var_export($field, true) . ']';
                $identifierPairs[] = var_export($identifierFieldName, true) . ' => $data[' . var_export($field, true) . ']';
            }
            $hydrateMethod->writeln(sprintf('$idHash = ' . implode(" . ' ' . ", $idHash) . ';'));
            if ($isPartialAlias) {
                // A partial entity is hydrated into a native lazy ghost instead of a plain instance:
                // Doctrine's own ProxyFactory::getProxy() initializer (the same one used for to-one
                // association references) transparently reloads the full row on first access to any
                // field we don't set below. $assignIdentifiers=false because the generic field-setting
                // loop further down already writes identifier fields like any other selected field.
                $hydrateMethod->writeln('$result = $proxy ?? $this->proxyFactory->getProxy(' . var_export($entityClass, true) . ', [' . implode(', ', $identifierPairs) . '], false);');
            } else {
                $hydrateMethod->writeln(sprintf('$result = $proxy ?? $this->instantiator->instantiate(' . var_export($entityClass, true) . ');'));
            }
            $hydrateMethod->writeln('$oid = spl_object_id($result);');
            if ($objectManagerAwareExists && $classMetadata->reflClass->implementsInterface(ObjectManagerAware::class)) {
                $hydrateMethod->writeln(sprintf('$result->injectObjectManager($this->entityManager, $classMetadata);'));
            }
            if ($classMetadata->reflClass->implementsInterface(NotifyPropertyChanged::class)) {
                $hydrateMethod->writeln(sprintf('$result->addPropertyChangedListener($this->unitOfWork);'));
            }

            $hydrateMethod->writeln('$entityData = [];');
            if (isset($this->flags[JitObjectHydrator::JIT_FLAG_OPTIMIZE_TYPE_CONVERSION]) && $this->flags[JitObjectHydrator::JIT_FLAG_OPTIMIZE_TYPE_CONVERSION]) {
                $initializedTypes = [];
                foreach ($fields as $field => $column) {
                    $type = self::mappingValue($classMetadata->fieldMappings[$field], 'type');
                    $typeVariableName = trim(preg_replace('#[^_\\w]+#', '_', str_replace('\\', '__', $type)), '_');
                    if (!isset($globalInitializedTypes[$type]) && !in_array($type, [Types::TEXT, Types::STRING, Types::BOOLEAN, Types::BIGINT, Types::INTEGER, Types::SMALLINT, Types::DECIMAL, Types::FLOAT, Types::SIMPLE_ARRAY, Types::DATETIME_MUTABLE, Types::DATETIME_IMMUTABLE])) {
                        $globalInitializedTypes[$type] = true;
                        $constructor->writeln('$this->typeMap[' . var_export($type, true) . '] = Type::getType(' . var_export($type, true) . ');');
                    }
                    if (!isset($initializedTypes[$type]) && !in_array($type, [Types::TEXT, Types::STRING, Types::BOOLEAN, Types::BIGINT, Types::INTEGER, Types::SMALLINT, Types::DECIMAL, Types::FLOAT, Types::SIMPLE_ARRAY, Types::DATETIME_MUTABLE, Types::DATETIME_IMMUTABLE])) {
                        $initializedTypes[$type] = true;
                        $hydrateMethod->writeln('$type_' . $typeVariableName . ' = $this->typeMap[' . var_export($type, true) . '];');
                    }
                }
            }

            foreach ($fields as $field => $column) {
                $hydrateMethod->writeln('// hydrate ' . $alias . '.' . $field);
                $exportedKey = var_export($column, true);
                if (isset($this->flags[JitObjectHydrator::JIT_FLAG_OPTIMIZE_TYPE_CONVERSION]) && $this->flags[JitObjectHydrator::JIT_FLAG_OPTIMIZE_TYPE_CONVERSION]) {
                    $type = self::mappingValue($classMetadata->fieldMappings[$field], 'type');
                    $typeVariableName = trim(preg_replace('#[^_\\w]+#', '_', str_replace('\\', '__', $type)), '_');
                    switch ($type) {
                        case Types::TEXT:
                        case Types::STRING:
                            $hydrateMethod->writeln('$value = $entityData[' . var_export($field, true) . '] = $data[' . $exportedKey . '];');
                            break;
                        case Types::BOOLEAN:
                            $hydrateMethod->writeln('$value = $entityData[' . var_export($field, true) . '] = (null === $data[' . $exportedKey . ']) ? null : (bool) $data[' . $exportedKey . '];');
                            break;
                        case Types::BIGINT:
                            $hydrateMethod->writeln('$value = $entityData[' . var_export($field, true) . '] = (null === $data[' . $exportedKey . ']) ? null : (string) $data[' . $exportedKey . '];');
                            break;
                        case Types::INTEGER:
                        case Types::SMALLINT:
                            $hydrateMethod->writeln('$value = $entityData[' . var_export($field, true) . '] = (null === $data[' . $exportedKey . ']) ? null : (int) $data[' . $exportedKey . '];');
                            break;
                        case Types::DECIMAL:
                        case Types::FLOAT:
                            $hydrateMethod->writeln('$value = $entityData[' . var_export($field, true) . '] = (null === $data[' . $exportedKey . ']) ? null : (float) $data[' . $exportedKey . '];');
                            break;
                        case Types::SIMPLE_ARRAY:
                            $hydrateMethod->writeln('$value = $entityData[' . var_export($field, true) . '] = (null === $data[' . $exportedKey . ']) ? [] : (is_resource($data[' . $exportedKey . ']) ? explode(\',\', stream_get_contents($data[' . $exportedKey . '])) : explode(\',\', $data[' . $exportedKey . ']));');
                            break;
                        case Types::DATETIME_MUTABLE:
                        case Types::DATETIME_IMMUTABLE:
                            $dateTimeClass = self::mappingValue($classMetadata->fieldMappings[$field], 'type') === Types::DATETIME_MUTABLE ? \DateTime::class : \DateTimeImmutable::class;
                            $hydrateMethod->writeln('$value = $data[' . $exportedKey . '];');
                            $hydrateMethod->writeln('$value = (null === $value || $value instanceof \\DateTimeInterface) ? $value : \\' . $dateTimeClass . '::createFromFormat(' . var_export($this->entityManager->getConnection()->getDatabasePlatform()->getDateTimeFormatString(), true) . ', $value);');
                            $hydrateMethod->writeln('$value = $entityData[' . var_export($field, true) . '] = $value ?: (null !== $data[' . $exportedKey . '] ? \\date_create($data[' . $exportedKey . ']) : null);');
                            break;
                        default:
                            if (isset($initializedTypes[$type])) {
                                $hydrateMethod->writeln('$value = $entityData[' . var_export($field, true) . '] = $type_' . $typeVariableName . '->convertToPHPValue($data[' . var_export($column, true) . '], $this->databasePlatform);');
                            } else {
                                $hydrateMethod->writeln('$value = $entityData[' . var_export($field, true) . '] = Type::getType(' . var_export(self::mappingValue($classMetadata->fieldMappings[$field], 'type'), true) . ')->convertToPHPValue($data[' . var_export($column, true) . '], $this->databasePlatform);');
                            }
                    }
                } else {
                    $hydrateMethod->writeln('$value = $entityData[' . var_export($field, true) . '] = Type::getType(' . var_export(self::mappingValue($classMetadata->fieldMappings[$field], 'type'), true) . ')->convertToPHPValue($data[' . var_export($column, true) . '], $this->databasePlatform);');
                }
                if ($hasPropertyAccessor && !$isPartialAlias && $this->isFastFieldWriteEligible($classMetadata, $field)) {
                    // $classMetadata->propertyAccessors[$field]->setValue() goes through 1-2 extra
                    // wrapper layers (proxy-safety checks, null-to-typed-property handling) on top of
                    // the underlying ReflectionProperty::setValue() call. Those wrappers only matter
                    // when $result might be an existing (possibly still-uninitialized) lazy object -
                    // i.e. when $proxy !== null. When we've just instantiated a brand new, plain object
                    // ($proxy === null) it can never be a proxy of any kind, so we can call the cached
                    // ReflectionProperty directly and skip the wrapper indirection entirely.
                    // (Not for partial aliases: there, a $proxy === null $result is a freshly-created
                    // lazy ghost - an actual proxy - so the safe accessor is always required.)
                    $reflFieldPropertyName = $this->getReflFieldPropertyName($entityClass, $field, true);
                    $fastFieldAccessors[$reflFieldPropertyName] = [$entityClass, $field];
                    $hydrateMethod->writeIf('$proxy === null');
                    $hydrateMethod->writeln('$this->' . $reflFieldPropertyName . '->setValue($result, $value);');
                    $hydrateMethod->writeElse();
                    $hydrateMethod->writeln(sprintf('$classMetadata->'.$propertyAccessors.'[' . var_export($field, true) . ']->setValue($result, $value);'));
                    $hydrateMethod->writeEndif();
                } else {
                    $hydrateMethod->writeln(sprintf('$classMetadata->'.$propertyAccessors.'[' . var_export($field, true) . ']->setValue($result, $value);'));
                }
                $hydrateMethod->writeln();
            }

            if (count($classMetadata->getAssociationMappings())) {
                foreach ($classMetadata->getAssociationMappings() as $name => $mapping) {
                    $targetEntityClass = self::mappingValue($mapping, 'targetEntity');
                    $targetClassMetadata = $this->getClassMetadata($targetEntityClass);
                    $classMetadataConstructorMap[$targetEntityClass][] = $alias . '_' . $name;
                    switch (self::mappingType($mapping)) {
                        case ClassMetadata::ONE_TO_ONE:
                        case ClassMetadata::MANY_TO_ONE:
                            if (!isset($joinedRelations[$alias][$name])) {
                                $fetchMode = isset($this->hints['fetchMode'][$classMetadata->name][$name])
                                    ? $this->hints['fetchMode'][$classMetadata->name][$name]
                                    : self::mappingValue($mapping, 'fetch')
                                ;
                                $isEagerLoading = $fetchMode === ClassMetadata::FETCH_EAGER;
                                $isDeferredEagerLoading = $isEagerLoading && isset($this->hints[UnitOfWork::HINT_DEFEREAGERLOAD]) && true === $this->hints[UnitOfWork::HINT_DEFEREAGERLOAD];
                                $shouldDeferEagerLoading = $isDeferredEagerLoading && !$targetClassMetadata->isIdentifierComposite;

                                $hydrateMethod->writeln('// hydrate ' . (self::mappingType($mapping) === ClassMetadata::ONE_TO_ONE ? 'one' : 'many') . '-to-one ' . $name);
                                $metaColumnsIncluded = count(array_diff(array_keys(self::mappingValue($mapping, 'sourceToTargetKeyColumns')), array_keys($aliasMetaMap[$alias]))) === 0;
                                $column = $aliasMetaMap[$alias][array_keys(self::mappingValue($mapping, 'sourceToTargetKeyColumns'))[0]];
                                if ($metaColumnsIncluded) {
                                    $ifColumns = [];
                                    foreach (array_keys(self::mappingValue($mapping, 'sourceToTargetKeyColumns')) as $joinColumnName) {
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
                                    $hydrateMethod->writeln('$proxy_' . $alias . '_' . $name . ' = $this->identityMap[' . var_export($targetEntityClass, true) . '][$idHash] =  $this->proxyFactory->getProxy(' . var_export($targetEntityClass, true) . ', $reference);');
                                    if ($registerManagedProxySupported) {
                                        // registerManagedProxy() also records "zero fields loaded" for this OID in
                                        // UnitOfWork's own partialObjectLoadedFields map (ORM 3.7+), which
                                        // computeChangeSet() now treats as the source of truth for which fields to
                                        // diff once this reference proxy gets initialized.
                                        $hydrateMethod->writeln('$this->unitOfWork->registerManagedProxy($proxy_' . $alias . '_' . $name . ', $reference);');
                                    } else {
                                        $hydrateMethod->writeln('$this->unitOfWork->registerManaged($proxy_' . $alias . '_' . $name . ', $reference, []);');
                                    }
                                    $hydrateMethod->writeEndif();
                                    $hydrateMethod->writeln($this->getMetadataPropertyName($classMetadata->name) . '->'.$propertyAccessors.'[' . var_export($name, true) . ']->setValue($result, $proxy_' . $alias . '_' . $name . ');');
                                    if ($shouldDeferEagerLoading) {
                                        $needsEagerLoadingEntitiesProperty = true;
                                        $checkProxyCondition = null;
                                        switch (true) {
                                            case $isNativeProxy:
                                                $checkProxyCondition = $this->getMetadataPropertyName($targetEntityClass).'->reflClass->isUninitializedLazyObject($proxy_' . $alias . '_' . $name . ')';
                                                break;
                                            case $isLazyGhostProxy:
                                                $checkProxyCondition = '($entity_' . $alias . ' instanceof Proxy && !$entity_' . $alias . '->__isInitialized())';
                                                break;
                                            default:
                                                $checkProxyCondition = '($entity_' . $alias . ' instanceof Proxy && !$entity_' . $alias . '->__isInitialized__)';
                                                break;
                                        }
                                        $hydrateMethod->writeln('$singleIdentifier = Type::getType(' . var_export($this->rsm->typeMappings[$column], true) . ')->convertToPHPValue($data[' . var_export($column, true) . '], $this->databasePlatform);');
                                        $hydrateMethod
                                            ->writeIf($checkProxyCondition)
                                            ->writeln('$eagerLoadingEntities = $this->eagerLoadingEntitiesProperty->getValue($this->unitOfWork);')
                                            ->writeln('$eagerLoadingEntities['.var_export($targetEntityClass, true).'][(string) $singleIdentifier] = $singleIdentifier;')
                                            ->writeln('$this->eagerLoadingEntitiesProperty->setValue($this->unitOfWork, $eagerLoadingEntities);')
                                            ->writeEndif()
                                        ;
                                    }
                                    $hydrateMethod->writeEndif();
                                }
                            }
                            break;
                        case ClassMetadata::ONE_TO_MANY:
                        case ClassMetadata::MANY_TO_MANY:
                            $hydrateMethod->writeln('// hydrate ' . (self::mappingType($mapping) === ClassMetadata::ONE_TO_MANY ? 'one' : 'many') . '-to-many ' . $name);
                            $hydrateMethod->writeln('$collection_' . $name . ' = (new \\' . PersistentCollection::class . '($this->entityManager, ' . $this->getMetadataPropertyName($targetEntityClass) . ', new \\' . ArrayCollection::class . '()));');
                            if (!isset($joinedRelations[$alias][$name])) {
                                $hydrateMethod->writeln('$collection_' . $name . '->setInitialized(false);');
                                $hydrateMethod->writeln('$collection_' . $name . '->setDirty(false);');
                            }
                            $hydrateMethod->writeln('$collection_' . $name . '->setOwner($result, ' . $this->getMetadataPropertyName($entityClass) . '->getAssociationMapping(' . var_export($name, true) . '));');
                            $hydrateMethod->writeln('$this->unitOfWork->setOriginalEntityProperty($oid, ' . var_export($name, true) . ', $collection_' . $name . ');');
                            $hydrateMethod->writeln('$classMetadata->'.$propertyAccessors.'[' . var_export($name, true) . ']->setValue($result, $collection_' . $name . ');');
                            if (isset($inverseJoinedRelations[$alias][$name])) {
                            }
                            break;
                    }
                    $hydrateMethod->writeln('');
                }
            }

            $hydrateMethod->writeln('$this->unitOfWork->registerManaged($result, [' . implode(" , ", $identifierPairs) . '], $entityData);');
            if ($isPartialAlias) {
                // Only for a freshly-created ghost ($proxy === null): mark it as a partial ghost (so the
                // isUninitializedLazyObject() re-check on later rows leaves it alone, see $partialGhosts
                // above) and tell UnitOfWork which fields are actually loaded, by poking its private
                // partialObjectLoadedFields map via reflection - the same technique already used for
                // eagerLoadingEntities. Without this, editing a loaded field before the ghost's own
                // lazy-reload initializer fires would be silently discarded once it does fire, since
                // UnitOfWork would have no record that this OID was ever partially (rather than fully)
                // loaded, and would treat the initializer's full-row reload as authoritative for every
                // field. A $proxy that's being refreshed (HINT_REFRESH) is left alone here and simply
                // keeps whatever partial/full status it already had.
                $hydrateMethod
                    ->writeIf('$proxy === null')
                    ->writeln('$this->partialGhosts[$oid] = true;')
                    ->writeln('$partialObjectLoadedFields = $this->partialObjectLoadedFieldsProperty->getValue($this->unitOfWork);')
                    ->writeln('$partialObjectLoadedFields[$oid] = ' . var_export(array_keys($fields), true) . ';')
                    ->writeln('$this->partialObjectLoadedFieldsProperty->setValue($this->unitOfWork, $partialObjectLoadedFields);')
                    ->writeEndif()
                ;
            }
            if ($this->hints[Query::HINT_READ_ONLY] ?? false) {
                $hydrateMethod->writeln('$this->unitOfWork->markReadOnly($result);');
            }
            if (class_exists(HydrationCompleteHandler::class)) {
                $hydrateMethod->writeln('$this->hydrationCompleteHandler->deferPostLoadInvoking($classMetadata, $result);');
            }

            $hydrateMethods[$alias] = $hydrateMethod;
        }
        $constructor->writeln('$this->identityMap = [')->indent();
        foreach (array_keys($identityMap) as $entityClass) {
            $constructor->writeln(var_export($entityClass, true) . ' => [],');
        }
        $constructor->outdent()->writeln('];');

        foreach ($aliasColumnMap as $alias => $fields) {
            $entityClass = $this->rsm->aliasMap[$alias];
            $shouldRefresh = ($this->hints[Query::HINT_REFRESH] ?? false) || (($this->hints[Query::HINT_REFRESH_ENTITY] ?? null) === $entityClass);
            $entityClassEscaped = var_export($entityClass, true);
            $classMetadata = $this->getClassMetadata($entityClass);
            $isPartialAlias = $partialAliasFlags[$alias] ?? false;
            $idHash = [];
            foreach ($this->resolveIdentifierFieldDataKeys($classMetadata, $alias, $fields, $aliasMetaMap) as $field) {
                $idHash[] = '$data[' . var_export($field, true) . ']';
            }
            $rowHydrateMethod->writeln(sprintf('$idHash = ' . implode(" . ' ' . ", $idHash) . ';'));
            $rowHydrateMethod
                ->writeln('$new_entity_' . $alias . ' = false;')
                ->writeIf(implode(' === null && ', $idHash) . ' === null ')
                ->writeln('$entity_' . $alias . ' = null;')
                ->writeElseIf('isset($this->identityMap[' . $entityClassEscaped . '][$idHash])')
                ->writeln('$entity_' . $alias . ' = $this->identityMap[' . $entityClassEscaped . '][$idHash];')
            ;
            if ($isNativeProxy) {
                // For a deliberately-partial ghost (see $partialGhosts above), isUninitializedLazyObject()
                // being true just means "not yet fully loaded", not "needs refreshing from this row's
                // (still partial) data" - so it's excluded from the auto-trigger here. An explicit
                // HINT_REFRESH still forces a reload via the shouldRefresh OR-clause below regardless.
                $rowHydrateMethod
                    ->writeIf('($isUninitialized = '.$this->getMetadataPropertyName($entityClass).'->reflClass->isUninitializedLazyObject($entity_' . $alias . ')' . ($isPartialAlias ? ' && !isset($this->partialGhosts[spl_object_id($entity_' . $alias . ')])' : '') . ')' . ($shouldRefresh ? ' || !isset($this->refreshedEntities[' . $entityClassEscaped . '][spl_object_id($entity_' . $alias . ')])' : ''))
                    ->writeln('$this->newEntity_' . $alias . '($data, $entity_' . $alias . ');')
                ;

                $shouldRefresh ? $rowHydrateMethod->writeIf('$isUninitialized') : null;
                $rowHydrateMethod->writeln($this->getMetadataPropertyName($entityClass).'->reflClass->markLazyObjectAsInitialized($entity_' . $alias . ');');
                $shouldRefresh ? $rowHydrateMethod->writeEndif() : null;

                if ($shouldRefresh) {
                    $rowHydrateMethod->writeln('$this->refreshedEntities[' . $entityClassEscaped . '][spl_object_id($entity_' . $alias . ')] = true;');
                }

                $rowHydrateMethod->writeEndif();
            } elseif ($isLazyGhostProxy) {
                $rowHydrateMethod
                    ->writeIf('($isUninitialized = ($entity_' . $alias . ' instanceof Proxy && !$entity_' . $alias . '->__isInitialized()))' . ($shouldRefresh ? ' || !isset($this->refreshedEntities[' . $entityClassEscaped . '][spl_object_id($entity_' . $alias . ')])' : ''))
                    ->writeln('$this->newEntity_' . $alias . '($data, $entity_' . $alias . ');')
                ;

                $shouldRefresh ? $rowHydrateMethod->writeIf('$isUninitialized') : null;
                $shouldRefresh ? $rowHydrateMethod->writeln('$entity_' . $alias . '->__setInitialized(true);') : null;
                $shouldRefresh ? $rowHydrateMethod->writeEndif() : null;

                if ($shouldRefresh) {
                    $rowHydrateMethod->writeln('$this->refreshedEntities[' . $entityClassEscaped . '][spl_object_id($entity_' . $alias . ')] = true;');
                }
                $rowHydrateMethod->writeEndif();
            } else {
                $rowHydrateMethod
                    ->writeIf('($isUninitialized = ($entity_' . $alias . ' instanceof Proxy && !$entity_' . $alias . '->__isInitialized__))' . ($shouldRefresh ? ' || !isset($this->refreshedEntities[' . $entityClassEscaped . '][spl_object_id($entity_' . $alias . ')])' : ''))
                    ->writeln('$this->newEntity_' . $alias . '($data, $entity_' . $alias . ');')
                ;

                $shouldRefresh ? $rowHydrateMethod->writeIf('$isUninitialized') : null;
                $rowHydrateMethod
                    ->writeln('$entity_' . $alias . '->__isInitialized__ = true;')
                    ->writeln('$entity_' . $alias . '->__initializer__ = $entity_' . $alias .'->__cloner__ = null;')
                ;
                $shouldRefresh ? $rowHydrateMethod->writeEndif() : null;

                if ($shouldRefresh) {
                    $rowHydrateMethod->writeln('$this->refreshedEntities[' . $entityClassEscaped . '][spl_object_id($entity_' . $alias . ')] = true;');
                }

                $rowHydrateMethod->writeEndif();
            }
            $rowHydrateMethod->writeElseIf('$uow_entity_' . $alias . ' = $this->unitOfWork->tryGetByIdHash($idHash, ' . $entityClassEscaped . ')');

            if ($isNativeProxy) {
                $rowHydrateMethod
                    ->writeIf('($isUninitialized = '.$this->getMetadataPropertyName($entityClass).'->reflClass->isUninitializedLazyObject($uow_entity_' . $alias . ')' . ($isPartialAlias ? ' && !isset($this->partialGhosts[spl_object_id($uow_entity_' . $alias . ')])' : '') . ')' . ($shouldRefresh ? ' || !isset($this->refreshedEntities[' . $entityClassEscaped . '][spl_object_id($uow_entity_' . $alias . ')])' : ''))
                    ->writeln('$this->newEntity_' . $alias . '($data, $uow_entity_' . $alias . ');')
                ;

                $shouldRefresh ? $rowHydrateMethod->writeIf('$isUninitialized') : null;
                $rowHydrateMethod->writeln($this->getMetadataPropertyName($entityClass).'->reflClass->markLazyObjectAsInitialized($uow_entity_' . $alias . ');');
                $shouldRefresh ? $rowHydrateMethod->writeEndif() : null;

                if ($shouldRefresh) {
                    $rowHydrateMethod->writeln('$this->refreshedEntities[' . $entityClassEscaped . '][spl_object_id($uow_entity_' . $alias . ')] = true;');
                }

                $rowHydrateMethod->writeEndif();
            } else if ($isLazyGhostProxy) {
                $rowHydrateMethod
                    ->writeIf('($isUninitialized = ($uow_entity_' . $alias . ' instanceof Proxy && !$uow_entity_' . $alias . '->__isInitialized()))' . ($shouldRefresh ? ' || !isset($this->refreshedEntities[' . $entityClassEscaped . '][spl_object_id($uow_entity_' . $alias . ')])' : ''))
                    ->writeln('$this->newEntity_' . $alias . '($data, $uow_entity_' . $alias . ');')
                ;

                $shouldRefresh ? $rowHydrateMethod->writeIf('$isUninitialized') : null;
                $rowHydrateMethod->writeln('$uow_entity_' . $alias . '->__setInitialized(true);');
                $shouldRefresh ? $rowHydrateMethod->writeEndif() : null;

                if ($shouldRefresh) {
                    $rowHydrateMethod->writeln('$this->refreshedEntities[' . $entityClassEscaped . '][spl_object_id($uow_entity_' . $alias . ')] = true;');
                }

                $rowHydrateMethod->writeEndif();
            } else {
                $rowHydrateMethod
                    ->writeIf('($isUninitialized = ($uow_entity_' . $alias . ' instanceof Proxy && !$uow_entity_' . $alias . '->__isInitialized__))' . ($shouldRefresh ? ' || !isset($this->refreshedEntities[' . $entityClassEscaped . '][spl_object_id($uow_entity_' . $alias . ')])' : ''))
                    ->writeln('$this->newEntity_' . $alias . '($data, $uow_entity_' . $alias . ');')
                ;
                $shouldRefresh ? $rowHydrateMethod->writeIf('$isUninitialized') : null;
                $rowHydrateMethod
                    ->writeln('$uow_entity_' . $alias . '->__isInitialized__ = true;')
                    ->writeln('$uow_entity_' . $alias . '->__initializer__ = $uow_entity_' . $alias .'->__cloner__ = null;')
                ;
                $shouldRefresh ? $rowHydrateMethod->writeEndif() : null;

                if ($shouldRefresh) {
                    $rowHydrateMethod->writeln('$this->refreshedEntities[' . $entityClassEscaped . '][spl_object_id($uow_entity_' . $alias . ')] = true;');
                }
                $rowHydrateMethod->writeEndif();
            }
            $rowHydrateMethod
                ->writeln('$entity_' . $alias . ' = $this->identityMap[' . $entityClassEscaped . '][$idHash] = $uow_entity_' . $alias . ';')
                ->writeln('$new_entity_' . $alias . ' = true;')
                ->writeElseIf('!isset($this->identityMap[' . $entityClassEscaped . '][$idHash])')
                ->call($hydrateMethods[$alias], ['data' => '$data'], [
                    'inline' => false,
                    'assign' => '$entity_' . $alias . ' = $this->identityMap[' . $entityClassEscaped . '][$idHash]',
                ])
                ->writeln('$new_entity_' . $alias . ' = true;')
                ->writeElse()
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
                    $targetEntityClass = self::mappingValue($mapping, 'targetEntity');
                    $targetClassMetadata = $this->getClassMetadata($targetEntityClass);
                    switch (self::mappingType($mapping)) {
                        case ClassMetadata::MANY_TO_ONE:
                        case ClassMetadata::ONE_TO_ONE:
                            if (isset($joinedRelations[$alias][$name])) {
                                $rowHydrateMethod->writeIf('$new_entity_' . $alias . ' && $entity_' . $joinedRelations[$alias][$name]);
                                $rowHydrateMethod->writeln(sprintf($this->getMetadataPropertyName($classMetadata->name) . '->'.$propertyAccessors.'[' . var_export($name, true) . ']->setValue($entity_' . $alias . ', $entity_' . $joinedRelations[$alias][$name] . ');'));
                                $rowHydrateMethod->writeEndif();
                            }
                            break;
                        case ClassMetadata::ONE_TO_MANY:
                        case ClassMetadata::MANY_TO_MANY:
                            if (isset($joinedRelations[$alias][$name])) {
                                $rowHydrateMethod->writeIf('$entity_' . $joinedRelations[$alias][$name]);
                                $rowHydrateMethod->writeln('$collection_' . $alias . '_' . $name . ' = ' . $this->getMetadataPropertyName($classMetadata->name) . '->'.$propertyAccessors.'[' . var_export($name, true) . ']->getValue($entity_' . $alias . ');');
                                $rowHydrateMethod->writeln('$collection_' . $alias . '_' . $name . '->hydrateAdd($entity_' . $joinedRelations[$alias][$name] . ');');
                                $rowHydrateMethod->writeEndif();
                            } elseif (isset($inverseJoinedRelations[$alias][$name])) {
                                $rowHydrateMethod->writeIf('$entity_' . $inverseJoinedRelations[$alias][$name]);
                                $rowHydrateMethod->writeln('$collection_' . $alias . '_' . $name . ' = ' . $this->getMetadataPropertyName($classMetadata->name) . '->'.$propertyAccessors.'[' . var_export($name, true) . ']->getValue($entity_' . $alias . ');');
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
            $this->classWriter->addProperty($metadataPropertyName, 'private', null, '\\' . ClassMetadata::class, false, false, 'for ' . $class);
            $constructor->writeln('$this->' . $metadataPropertyName . ' = $this->entityManager->getClassMetadata(' . var_export($class, true) . ');');
        }

        // Must run after the metadata properties above are assigned, since it reads propertyAccessors off of them.
        foreach ($fastFieldAccessors as $reflFieldPropertyName => [$class, $field]) {
            $this->classWriter->addProperty($reflFieldPropertyName, 'private', null, '\\ReflectionProperty', false, false, 'for ' . $class . '::$' . $field);
            $constructor->writeln('$this->' . $reflFieldPropertyName . ' = $this->' . $this->getMetadataPropertyName($class, true) . '->propertyAccessors[' . var_export($field, true) . ']->getUnderlyingReflector();');
        }

        if ($needsEagerLoadingEntitiesProperty) {
            $this->classWriter->addProperty('eagerLoadingEntitiesProperty', 'private', null, '\\ReflectionProperty', false, false);
            $constructor->writeln('$this->eagerLoadingEntitiesProperty = $this->reflectionUow->getProperty(\'eagerLoadingEntities\');');
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

    /**
     * Resolves, for each identifier field of $classMetadata, the $data key that holds its value for
     * $alias's row data - handling the case where the identifier field is itself a to-one owning-side
     * association (the row then carries the value under its join column's meta-mapping key, not under
     * the field's own mapping key).
     *
     * @param array<string, string> $fields Field name => $data key, for $alias's regular column mappings.
     * @param array<string, array<string, string>> $aliasMetaMap Alias => join/meta column name => $data key.
     *
     * @return array<string, string> Identifier field name => $data key.
     */
    private function resolveIdentifierFieldDataKeys(ClassMetadata $classMetadata, string $alias, array $fields, array $aliasMetaMap): array
    {
        $identifierDataKeys = [];
        foreach ($classMetadata->getIdentifierFieldNames() as $identifierFieldName) {
            if (isset($classMetadata->associationMappings[$identifierFieldName]) && self::isToOneOwningSide($classMetadata->associationMappings[$identifierFieldName])) {
                $joinColumns = self::mappingValue($classMetadata->associationMappings[$identifierFieldName], 'joinColumns');
                $column = self::mappingValue($joinColumns[0], 'name');
                $identifierDataKeys[$identifierFieldName] = $aliasMetaMap[$alias][$column];
            } else {
                $identifierDataKeys[$identifierFieldName] = $fields[$identifierFieldName];
            }
        }

        return $identifierDataKeys;
    }

    /**
     * Reads a value out of a field/association mapping regardless of whether it's
     * represented as a plain array (Doctrine ORM 2.x) or a mapping value object
     * (Doctrine ORM 3.x).
     *
     * @param array|object $mapping
     * @return mixed
     */
    private static function mappingValue($mapping, string $key)
    {
        if (is_array($mapping)) {
            return $mapping[$key] ?? null;
        }

        return $mapping->$key ?? null;
    }

    /**
     * Returns the association type constant (see ClassMetadata::*_TO_*) for a mapping,
     * regardless of whether it's an array (ORM 2.x) or a mapping object exposing a
     * type() method (ORM 3.x).
     *
     * @param array|object $mapping
     */
    private static function mappingType($mapping): int
    {
        return is_array($mapping) ? $mapping['type'] : $mapping->type();
    }

    /**
     * @param array|object $mapping
     */
    private static function isToOneOwningSide($mapping): bool
    {
        if (is_object($mapping)) {
            return $mapping->isToOneOwningSide();
        }

        if ($mapping['type'] === ClassMetadata::MANY_TO_ONE) {
            return true;
        }

        return $mapping['type'] === ClassMetadata::ONE_TO_ONE && !empty($mapping['joinColumns']);
    }

    private static function getDbalExceptionClass(): string
    {
        if (class_exists(\Doctrine\DBAL\DBALException::class)) {
            return \Doctrine\DBAL\DBALException::class;
        }

        if (interface_exists(\Doctrine\DBAL\Exception::class)) {
            return \Doctrine\DBAL\Exception::class;
        }

        return \Exception::class;
    }

    /**
     * Whether it's safe to write $field directly through a cached ReflectionProperty
     * instead of $classMetadata->propertyAccessors[$field] when we know $result is a
     * brand new, plain (never-proxied) instance. Unsafe/ineligible for:
     *  - embedded fields (dotted field names): the accessor creates/delegates to a
     *    nested embeddable object, which raw reflection can't replicate;
     *  - enum-backed fields: the accessor converts the raw scalar to/from the enum;
     *  - fields whose PHP property type doesn't allow null while the DB column does:
     *    the accessor "unsets" the property instead of assigning null there (assigning
     *    null directly would throw a TypeError).
     *
     * Only called when $classMetadata->propertyAccessors is available (ORM 3.4+); on
     * older ORM versions the generator already writes through the underlying
     * ReflectionProperty directly via $classMetadata->reflFields, so there is nothing
     * to optimize there.
     */
    private static function isFastFieldWriteEligible(ClassMetadata $classMetadata, string $field): bool
    {
        if (strpos($field, '.') !== false) {
            return false;
        }

        $fieldMapping = $classMetadata->fieldMappings[$field] ?? null;
        if ($fieldMapping !== null && self::mappingValue($fieldMapping, 'enumType') !== null) {
            return false;
        }

        $accessor = $classMetadata->propertyAccessors[$field] ?? null;
        if ($accessor === null) {
            return false;
        }

        $reflField = $accessor->getUnderlyingReflector();
        if (!$reflField->hasType() || $reflField->getType()->allowsNull()) {
            return true;
        }

        return $fieldMapping !== null && self::mappingValue($fieldMapping, 'nullable') !== true;
    }

    private function getReflFieldPropertyName(string $class, string $field, bool $onlyName = false): string
    {
        $safeField = preg_replace('#[^A-Za-z0-9_]#', '_', $field);

        return (!$onlyName ? '$this->' : '') . 'reflField_' . str_replace('\\', '_', strtolower($class)) . '_' . $safeField;
    }
}
