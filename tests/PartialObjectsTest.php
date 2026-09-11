<?php

namespace Ovrflo\JitHydrator\Tests;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query;
use Ovrflo\JitHydrator\Tests\Fixtures\Author;
use Ovrflo\JitHydrator\Tests\Fixtures\Book;
use PHPUnit\Framework\TestCase;

/**
 * Doctrine ORM 3.7 (GH-12210) made PARTIAL query results behave as native lazy
 * ghosts: only the selected fields are populated eagerly, and accessing any
 * other field transparently triggers a full reload. HydratorGenerator now
 * replicates this by creating partial root/joined entities via
 * ProxyFactory::getProxy() instead of a plain Instantiator instance.
 *
 * On ORM versions/configurations where this isn't available (ORM 2.x, ORM
 * 3.0-3.6.x, or native lazy objects disabled), these tests are skipped: the
 * old behavior there (unselected fields are simply never written) needs no
 * new code path and is already exercised implicitly by every other test in
 * this suite, none of which use PARTIAL.
 */
class PartialObjectsTest extends TestCase
{
    private QueryCounter $queryCounter;
    private EntityManager $em;

    protected function setUp(): void
    {
        $this->queryCounter = new QueryCounter();
        $this->em = EntityManagerFactory::create($this->queryCounter);

        // Matches HydratorGenerator's own $supportsPartialLazyGhosts fingerprint: native lazy
        // objects alone (available since ORM 3.6) aren't enough - PARTIAL-as-lazy-ghost support
        // needs the GH-12210 properties that only landed in 3.7. isNativeLazyObjectsEnabled()
        // alone returns true on 3.6 too, which would let these tests run there and fail against
        // the (correct, expected) old PARTIAL behavior instead of being skipped.
        if (
            !$this->em->getConfiguration()->isNativeLazyObjectsEnabled()
            || !property_exists(\Doctrine\ORM\Query\ResultSetMapping::class, 'partialAliases')
            || !property_exists(\Doctrine\ORM\UnitOfWork::class, 'partialObjectLoadedFields')
        ) {
            self::markTestSkipped('Requires native lazy objects with PARTIAL support (Doctrine ORM 3.7+, GH-12210).');
        }
    }

    private function persistAuthorWithBooks(): Author
    {
        $author = new Author('Octavia Butler');
        $author->setTags(['scifi']);
        $book1 = new Book('Kindred', new \DateTime('1979-01-01'));
        $book2 = new Book('Wild Seed', new \DateTime('1980-01-01'));
        $author->addBook($book1);
        $author->addBook($book2);
        $this->em->persist($author);
        $this->em->persist($book1);
        $this->em->persist($book2);
        $this->em->flush();
        $this->em->clear();

        return $author;
    }

    public function testLoadedFieldIsReadableWithoutTriggeringAReload(): void
    {
        $this->persistAuthorWithBooks();

        $author = $this->em->createQuery('SELECT PARTIAL a.{id, name} FROM ' . Author::class . ' a')
            ->setHint(Query::HINT_INCLUDE_META_COLUMNS, true)
            ->getResult('jit')[0];

        self::assertTrue($this->em->getUnitOfWork()->isUninitializedObject($author));

        $this->queryCounter->count = 0;
        self::assertSame('Octavia Butler', $author->getName());
        self::assertSame(0, $this->queryCounter->count, 'Reading an already-loaded field must not issue a query.');
        self::assertTrue($this->em->getUnitOfWork()->isUninitializedObject($author));
    }

    public function testUnloadedFieldTriggersExactlyOneReloadAndReturnsTheRightValue(): void
    {
        $this->persistAuthorWithBooks();

        $author = $this->em->createQuery('SELECT PARTIAL a.{id, name} FROM ' . Author::class . ' a')
            ->setHint(Query::HINT_INCLUDE_META_COLUMNS, true)
            ->getResult('jit')[0];

        $this->queryCounter->count = 0;
        self::assertTrue($author->isActive());
        self::assertSame(1, $this->queryCounter->count, 'Reading an unloaded field must trigger exactly one reload query.');
        self::assertFalse($this->em->getUnitOfWork()->isUninitializedObject($author));

        // Now fully initialized: further field access issues no more queries.
        $this->queryCounter->count = 0;
        self::assertSame(['scifi'], $author->getTags());
        self::assertSame(0, $this->queryCounter->count);
    }

    /**
     * The discriminating test for the $partialGhosts guard: without it, row 2 of
     * the fan-out (the second book) would see the author as "an uninitialized lazy
     * object that now has data to fill in" and force-initialize it using the SAME
     * partial data, permanently stranding 'active'/'tags' as inaccessible instead
     * of leaving the ghost lazily reloadable.
     */
    public function testPartialRootStaysLazyAcrossToManyJoinFanOut(): void
    {
        $this->persistAuthorWithBooks();

        $rows = $this->em->createQuery(
            'SELECT PARTIAL a.{id, name}, b FROM ' . Author::class . ' a JOIN a.books b'
        )->setHint(Query::HINT_INCLUDE_META_COLUMNS, true)->getResult('jit');

        self::assertCount(1, $rows);
        $author = $rows[0];
        self::assertCount(2, $author->getBooks());

        // Still lazy after processing BOTH underlying SQL rows for this one author.
        self::assertTrue($this->em->getUnitOfWork()->isUninitializedObject($author));

        $this->queryCounter->count = 0;
        self::assertTrue($author->isActive());
        self::assertSame(1, $this->queryCounter->count);
        self::assertFalse($this->em->getUnitOfWork()->isUninitializedObject($author));
    }

    public function testEditingALoadedFieldBeforeTouchingAnUnloadedOneIsPreserved(): void
    {
        $this->persistAuthorWithBooks();

        $author = $this->em->createQuery('SELECT PARTIAL a.{id, name} FROM ' . Author::class . ' a')
            ->setHint(Query::HINT_INCLUDE_META_COLUMNS, true)
            ->getResult('jit')[0];

        // Mutate the already-loaded 'name' field directly (no public setter exists on the
        // fixture). Since 'name' was already populated during the partial hydration, this
        // is a normal write to an already-initialized property - it must NOT itself trigger
        // the ghost's lazy initializer (only accessing a still-unloaded property does that).
        (new \ReflectionProperty(Author::class, 'name'))->setValue($author, 'Renamed In Memory');
        self::assertTrue(
            $this->em->getUnitOfWork()->isUninitializedObject($author),
            'Writing to an already-loaded field must not itself trigger the reload.'
        );

        // Accessing an unloaded field now triggers the full reload.
        self::assertSame(['scifi'], $author->getTags());

        // The in-memory edit to the already-loaded field must survive the reload - the
        // reload must not blindly overwrite it with the DB's original value ('Octavia Butler').
        self::assertSame('Renamed In Memory', $author->getName());
    }
}
