<?php

namespace Ovrflo\JitHydrator;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Statement;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Internal\Hydration\AbstractHydrator;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\UnitOfWork;

/**
 * @author Catalin Dan <dancatalin18@gmail.com>
 */
class JitObjectHydrator extends AbstractHydrator
{
    public const HINT_JIT_FLAGS = 'jit_flags';
    public const JIT_FLAG_OPTIMIZE_TYPE_CONVERSION = 1;
    public const JIT_FLAG_STRICT_TYPES = 2;
    public const JIT_FLAG_PROPERTY_TYPE_HINT = 3;

    /**
     * @var string|null
     */
    private $cacheDir;
    /**
     * @var bool
     */
    private $debug;

    protected $_rsm = null;
    protected $_em = null;
    protected $_platform = null;
    protected $_uow = null;
    protected $_stmt = null;
    protected $_hints = null;

    protected ?ResultSetMapping $rsm;
    protected EntityManagerInterface $em;
    protected AbstractPlatform $platform;
    protected UnitOfWork $uow;
    protected ?Result $stmt;
    protected array $hints;
    protected ?GeneratedObjectHydrator $generatedObjectHydrator = null;

    public function __construct(EntityManagerInterface $em, bool $debug = false)
    {
        parent::__construct($em);
        $proxyDir = $em->getConfiguration()->getProxyDir();
        if ($proxyDir) {
            $this->cacheDir = $proxyDir . '/../JitHydrator';
        }
        $this->debug = $debug;
        if ($this->_em) {
            $this->em = $this->_em;
            $this->platform = $this->_platform;
            $this->uow = $this->_uow;
        }
    }

    protected function hydrateAllData(): mixed
    {
        if ($this->_rsm) {
            $this->stmt = $this->_stmt;
            $this->rsm = $this->_rsm;
            $this->hints = $this->_hints;
        }

        if (!isset($this->hints[UnitOfWork::HINT_DEFEREAGERLOAD])) {
            $this->hints[UnitOfWork::HINT_DEFEREAGERLOAD] = true;
        }

        $queryString = null;
        if (method_exists($this->stmt, 'getIterator')) {
            if ($this->stmt instanceof Result) {
                $queryString = $this->stmt->getIterator()->queryString ?? null;
            } elseif ($this->stmt instanceof Statement) {
                $queryString = $this->stmt->queryString ?? null;
            }
        }
        $serialized = serialize([get_object_vars($this->rsm), $this->hints, $queryString]);
        $cacheKey = md5($serialized);
        $className = 'Hydrator_' . $cacheKey;
        $namespace = '__CG__\\Doctrine\\JitHydrator';
        $instance = null;
        if ($this->cacheDir) {
            if (!file_exists($this->cacheDir)) {
                if (!mkdir($this->cacheDir) && !is_dir($this->cacheDir)) {
                    throw new \RuntimeException(sprintf('Directory "%s" was not created', $this->cacheDir));
                }
            }
        }

        /** @var class-string<GeneratedObjectHydrator> $fqcn */
        $fqcn = $namespace . '\\' . $className;
        if (!class_exists($fqcn, false)) {
            $cacheFilename = $this->cacheDir . '/' . $className . '.php';
            if ($this->debug || !$this->cacheDir || !file_exists($cacheFilename)) {
                $hydratorGenerator = new HydratorGenerator($className, $namespace, $this->rsm, $this->stmt, $this->hints, $this->em, false, $this->hints[self::HINT_JIT_FLAGS] ?? []);
                $classString = $hydratorGenerator->dump($this->cacheDir === null);
                if ($this->cacheDir) {
                    file_put_contents($cacheFilename, $classString);
                    require_once $cacheFilename;
                } else {
                    eval(substr($classString, 5));
                }
            } else {
                require_once $cacheFilename;
            }
        }

        /** @var GeneratedObjectHydrator $instance */
        $this->generatedObjectHydrator = $instance = new $fqcn($this->em);
        $result = [];
        while ($row = $this->stmt->fetchAssociative()) {
            $instance->hydrate($row, $result);
        }

        return $result;
    }

    protected function hydrateRowData(array $data, array &$result): void
    {
        throw new \Exception(__METHOD__ . ' not implemented.');
    }

    protected function cleanup(): void
    {
        $eagerLoad = isset($this->hints[UnitOfWork::HINT_DEFEREAGERLOAD]) && $this->hints[UnitOfWork::HINT_DEFEREAGERLOAD] === true;

        parent::cleanup();
        if ($this->generatedObjectHydrator) {
            $this->generatedObjectHydrator->cleanup();
        }

        if ($eagerLoad) {
            $this->uow->triggerEagerLoads();
        }

        $this->uow->hydrationComplete();
        $this->generatedObjectHydrator = null;
    }
}
