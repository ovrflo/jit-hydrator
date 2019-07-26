<?php

namespace Ovrflo\JitHydrator;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Internal\Hydration\AbstractHydrator;

/**
 * @author Catalin Dan <dancatalin18@gmail.com>
 */
class JitObjectHydrator extends AbstractHydrator
{
    /**
     * @var string|null
     */
    private $cacheDir;
    /**
     * @var bool
     */
    private $debug;

    public function __construct(EntityManagerInterface $em, bool $debug = false)
    {
        parent::__construct($em);
        $proxyDir = $em->getConfiguration()->getProxyDir();
        if ($proxyDir) {
            $this->cacheDir = $proxyDir . '/../JitHydrator';
        }
        $this->debug = $debug;
    }

    protected function hydrateAllData()
    {
        $serialized = serialize([get_object_vars($this->_rsm), $this->_hints, $this->_stmt->queryString ?? null]);
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

        $cacheFilename = $this->cacheDir . '/' . $className . '.php';
        if ($this->debug || !$this->cacheDir || !file_exists($cacheFilename)) {
            $hydratorGenerator = new HydratorGenerator($className, $namespace, $this->_rsm, $this->_stmt, $this->_hints, $this->_em);
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

        $fqcn = $namespace . '\\' . $className;
        $instance = new $fqcn($this->_em);
        $result = [];
        while ($row = $this->_stmt->fetch(\PDO::FETCH_ASSOC)) {
            $instance->hydrate($row, $result);
        }

        return $result;
    }

    protected function hydrateRowData(array $data, array &$result)
    {
        throw new \Exception(__METHOD__ . ' not implemented.');
    }
}
