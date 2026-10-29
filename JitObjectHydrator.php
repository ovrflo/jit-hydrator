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

/*
 * Doctrine ORM 3.x declares `AbstractHydrator::hydrateAllData(): mixed`, while
 * Doctrine ORM 2.x declares it with no return type at all (`mixed` didn't exist
 * before PHP 8.0, which ORM 2.x still has to support). PHP requires an override
 * to match the parent's return type exactly (or omit it if the parent has none),
 * so a single hard-coded signature can't satisfy both major versions - and using
 * `: mixed` unconditionally would break under ORM 2.x + PHP 7.4, since PHP would
 * treat the unrecognized `mixed` type as a (non-existent) class name and fail
 * every call with a TypeError.
 *
 * We inspect the actually installed AbstractHydrator via reflection and eval a
 * tiny trait exposing a `hydrateAllData()` shim with whatever return type (or
 * lack thereof) that installed version requires, forwarding to doHydrateAllData().
 */
if (!trait_exists(JitObjectHydratorReturnTypeCompat::class, false)) {
    $hydrateAllDataReturnType = (new \ReflectionMethod(AbstractHydrator::class, 'hydrateAllData'))->getReturnType();
    $returnTypeDeclaration = $hydrateAllDataReturnType !== null ? ': ' . $hydrateAllDataReturnType : '';

    eval(<<<PHP
namespace Ovrflo\JitHydrator;

trait JitObjectHydratorReturnTypeCompat
{
    protected function hydrateAllData(){$returnTypeDeclaration}
    {
        return \$this->doHydrateAllData();
    }
}
PHP
    );

    unset($hydrateAllDataReturnType, $returnTypeDeclaration);
}

/**
 * @author Catalin Dan <dancatalin18@gmail.com>
 */
class JitObjectHydrator extends AbstractHydrator
{
    use JitObjectHydratorReturnTypeCompat;

    private static ?string $proxyDir = null;

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

    public static function getProxyDir(): ?string
    {
        return self::$proxyDir;
    }

    public static function setProxyDir(?string $proxyDir = null): void
    {
        self::$proxyDir = $proxyDir;
    }

    public function __construct(EntityManagerInterface $em, bool $debug = false)
    {
        parent::__construct($em);
        // The ORM's proxy dir only as the fallback: Configuration::getProxyDir() is deprecated (ORM 3.7, gone in 4), and
        // a hydrator is built for every query, so reading it each time logged a deprecation per query.
        if (self::$proxyDir) {
            $this->cacheDir = self::$proxyDir;
        } elseif ($proxyDir = $em->getConfiguration()->getProxyDir()) {
            $this->cacheDir = $proxyDir . '/../JitHydrator';
        }
        $this->debug = $debug;
        if ($this->_em) {
            $this->em = $this->_em;
            $this->platform = $this->_platform;
            $this->uow = $this->_uow;
        }
    }

    protected function doHydrateAllData()
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
                $classString = $this->generate($className, $namespace, $this->cacheDir === null);
                if ($this->cacheDir) {
                    self::write($cacheFilename, $classString);
                    require_once $cacheFilename;
                } else {
                    eval(substr($classString, 5));
                }
            } else {
                require_once $cacheFilename;
            }
            // Another request's file could still be incomplete, or compiled before it was (an opcode cache that
            // doesn't check timestamps keeps it): the class is then made in memory for this request.
            if (!class_exists($fqcn, false)) {
                eval(substr($this->generate($className, $namespace, true), 5));
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

    private function generate(string $className, string $namespace, bool $forEval): string
    {
        $hydratorGenerator = new HydratorGenerator($className, $namespace, $this->rsm, $this->stmt, $this->hints, $this->em, false, $this->hints[self::HINT_JIT_FLAGS] ?? []);

        return $hydratorGenerator->dump($forEval);
    }

    /**
     * Writes a generated class where concurrent requests may read it: to a temporary file renamed into place (a
     * reader sees the whole file or none), then drops any compiled copy of an earlier one from the opcode cache.
     */
    private static function write(string $filename, string $contents): void
    {
        $temporary = tempnam(\dirname($filename), basename($filename, '.php') . '.');
        if (false === $temporary || false === file_put_contents($temporary, $contents)) {
            throw new \RuntimeException(sprintf('The hydrator "%s" could not be written.', $filename));
        }
        @chmod($temporary, 0666 & ~umask());
        if (!rename($temporary, $filename)) {
            @unlink($temporary);
            throw new \RuntimeException(sprintf('The hydrator "%s" could not be moved into place.', $filename));
        }
        if (\function_exists('opcache_invalidate')) {
            @opcache_invalidate($filename, true);
        }
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
