<?php

namespace Ovrflo\JitHydrator\Tests;

use Doctrine\ORM\Query;
use Ovrflo\JitHydrator\JitObjectHydrator;
use Ovrflo\JitHydrator\Tests\Fixtures\Author;
use PHPUnit\Framework\TestCase;

/**
 * The PARTIAL-native-lazy-ghost machinery in HydratorGenerator (partial ghosts,
 * partialObjectLoadedFields reflection poke, ProxyFactory::getProxy() with a
 * third argument) is gated entirely behind feature-detecting
 * ResultSetMapping::$partialAliases / UnitOfWork::$partialObjectLoadedFields
 * (both introduced together in ORM 3.7 / GH-12210) and, per-alias, whether the
 * query actually used PARTIAL. This test asserts that machinery leaves no
 * trace at all in the generated hydrator for an ordinary (non-PARTIAL) query -
 * which is the *only* kind of query ORM 2.x and 3.0-3.6.x installs, and most
 * ORM 3.7 installs, will ever generate.
 */
class GeneratedCodeFingerprintTest extends TestCase
{
    public function testNonPartialQueryGeneratesNoPartialGhostMachinery(): void
    {
        $em = EntityManagerFactory::create();

        $author = new Author('Kim Stanley Robinson');
        $em->persist($author);
        $em->flush();
        $em->clear();

        $em->createQuery('SELECT a FROM ' . Author::class . ' a')
            ->setHint(Query::HINT_INCLUDE_META_COLUMNS, true)
            ->getResult('jit');

        $proxyDir = JitObjectHydrator::getProxyDir();
        self::assertNotNull($proxyDir);
        $generatedFiles = glob($proxyDir . '/*.php');
        self::assertNotEmpty($generatedFiles, 'Expected the jit hydrator to have generated and cached a class file.');

        $source = file_get_contents($generatedFiles[0]);

        foreach (['partialGhosts', 'partialObjectLoadedFields', 'getProxy(', 'proxyFactory->getProxy'] as $marker) {
            self::assertStringNotContainsString(
                $marker,
                $source,
                sprintf('Non-partial query must not generate any "%s" reference.', $marker)
            );
        }
    }
}
