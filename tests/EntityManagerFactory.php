<?php

namespace Ovrflo\JitHydrator\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Ovrflo\JitHydrator\JitObjectHydrator;

final class EntityManagerFactory
{
    public static function create(): EntityManager
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            [__DIR__ . '/Fixtures'],
            true
        );
        $config->addCustomHydrationMode('jit', JitObjectHydrator::class);
        $config->enableNativeLazyObjects(true);

        // Each EntityManager gets its own throwaway hydrator cache dir so that a
        // stale generated hydrator from a previous test run (or a previous version
        // of HydratorGenerator) never masks a regression by being require_once'd.
        JitObjectHydrator::setProxyDir(sys_get_temp_dir() . '/jit-hydrator-tests-' . bin2hex(random_bytes(8)));

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ], $config);

        $em = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($em);
        $schemaTool->createSchema($em->getMetadataFactory()->getAllMetadata());

        return $em;
    }
}
