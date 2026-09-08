<?php

namespace Ovrflo\JitHydrator\Tests;

use Doctrine\ORM\Query;
use Doctrine\ORM\UnitOfWork;
use Ovrflo\JitHydrator\Tests\Fixtures\EagerEdition;
use Ovrflo\JitHydrator\Tests\Fixtures\EagerPublisher;
use PHPUnit\Framework\TestCase;

/**
 * A fetch=EAGER many-to-one association that ISN'T joined in the query is
 * loaded via UnitOfWork's deferred-eager-load mechanism: HydratorGenerator
 * records the missing identifiers into UnitOfWork's private
 * eagerLoadingEntities map (via reflection), and JitObjectHydrator's cleanup()
 * calls UnitOfWork::triggerEagerLoads() to batch-load them afterwards.
 *
 * This exercises the ReflectionProperty caching for eagerLoadingEntities
 * (resolved once in the constructor rather than re-fetched on every row).
 */
class DeferredEagerLoadTest extends TestCase
{
    public function testEagerFetchAssociationNotJoinedIsFullyLoadedViaDeferredEagerLoad(): void
    {
        $em = EntityManagerFactory::create();

        $publisher1 = new EagerPublisher('Gollancz');
        $publisher2 = new EagerPublisher('Tor Books');
        $edition1 = new EagerEdition('Ancillary Justice', $publisher1);
        $edition2 = new EagerEdition('The Fifth Season', $publisher2);
        $edition3 = new EagerEdition('Foundation', $publisher1);
        $em->persist($publisher1);
        $em->persist($publisher2);
        $em->persist($edition1);
        $em->persist($edition2);
        $em->persist($edition3);
        $em->flush();
        $em->clear();

        // Deliberately not joining the publisher: fetch=EAGER means it must still end
        // up fully loaded by the time getResult() returns, without the caller doing
        // anything extra - JitObjectHydrator::cleanup() calls triggerEagerLoads() itself.
        $editions = $em->createQuery('SELECT e FROM ' . EagerEdition::class . ' e')
            ->setHint(Query::HINT_INCLUDE_META_COLUMNS, true)
            ->getResult('jit');

        self::assertCount(3, $editions);

        $uow = $em->getUnitOfWork();
        $publishersByTitle = [];
        $namesByTitle = [];
        foreach ($editions as $edition) {
            $publisher = $edition->getPublisher();
            self::assertFalse(
                $uow->isUninitializedObject($publisher),
                'fetch=EAGER association must be fully loaded once getResult() returns.'
            );
            $publishersByTitle[$edition->getTitle()] = $publisher;
            $namesByTitle[$edition->getTitle()] = $publisher->getName();
        }

        self::assertSame([
            'Ancillary Justice' => 'Gollancz',
            'The Fifth Season' => 'Tor Books',
            'Foundation' => 'Gollancz',
        ], $namesByTitle);

        // The two editions sharing the same publisher must resolve to the SAME instance
        // (proves the deferred-eager-load path went through the identity map correctly).
        self::assertSame($publishersByTitle['Ancillary Justice'], $publishersByTitle['Foundation']);
    }
}
