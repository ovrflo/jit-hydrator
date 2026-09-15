<?php

namespace Ovrflo\JitHydrator\Tests;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query;
use Ovrflo\JitHydrator\Tests\Fixtures\Author;
use Ovrflo\JitHydrator\Tests\Fixtures\Note;
use PHPUnit\Framework\TestCase;

/**
 * A many-to-one association whose FK column is selected (via
 * Query::HINT_INCLUDE_META_COLUMNS, or via a fetch-join) but happens to be
 * null for a given row must still be *explicitly* written as null on the
 * entity - not simply left untouched.
 *
 * Left untouched is indistinguishable, on a plain instantiated object, from
 * "the property's declared default (null) applies" - so the bug was
 * invisible there. But when the owning entity is hydrated as a native lazy
 * ghost (a PARTIAL root/joined alias under Doctrine ORM 3.7+, see
 * PartialObjectsTest), skipped properties are genuinely uninitialized at
 * the PHP engine level: the *next* read of that property - regardless of
 * which property - transparently triggers a full reload of the owning
 * entity via its lazy initializer. A torrent whose (null) artwork
 * association was silently skipped would therefore reload itself in full
 * the moment something called getArtwork(), even though the correct,
 * already-known answer was "no artwork".
 */
class NullAssociationTest extends TestCase
{
    private QueryCounter $queryCounter;
    private EntityManager $em;

    protected function setUp(): void
    {
        $this->queryCounter = new QueryCounter();
        $this->em = EntityManagerFactory::create($this->queryCounter);
    }

    private function supportsPartialNativeLazyGhosts(): bool
    {
        return $this->em->getConfiguration()->isNativeLazyObjectsEnabled()
            && property_exists(\Doctrine\ORM\Query\ResultSetMapping::class, 'partialAliases')
            && property_exists(\Doctrine\ORM\UnitOfWork::class, 'partialObjectLoadedFields');
    }

    private function persistNotes(): array
    {
        $author = new Author('Ursula K. Le Guin');
        $noteWithAuthor = new Note('Annotated', $author);
        $noteWithoutAuthor = new Note('Orphan', null);

        $this->em->persist($author);
        $this->em->persist($noteWithAuthor);
        $this->em->persist($noteWithoutAuthor);
        $this->em->flush();

        $ids = [$noteWithAuthor->getId(), $noteWithoutAuthor->getId()];
        $this->em->clear();

        return $ids;
    }

    public function testNullMetaColumnAssociationIsExplicitlyNullNotUninitialized(): void
    {
        [, $orphanId] = $this->persistNotes();

        $note = $this->em->createQuery('SELECT PARTIAL n.{id, title} FROM ' . Note::class . ' n WHERE n.id = :id')
            ->setParameter('id', $orphanId)
            ->setHint(Query::HINT_INCLUDE_META_COLUMNS, true)
            ->getResult('jit')[0];

        self::assertTrue(
            (new \ReflectionProperty(Note::class, 'author'))->isInitialized($note),
            'A selected-but-null association must be written as null, not left uninitialized.'
        );
        self::assertNull($note->getAuthor());
    }

    public function testNonNullMetaColumnAssociationStillWorks(): void
    {
        [$annotatedId] = $this->persistNotes();

        $note = $this->em->createQuery('SELECT PARTIAL n.{id, title} FROM ' . Note::class . ' n WHERE n.id = :id')
            ->setParameter('id', $annotatedId)
            ->setHint(Query::HINT_INCLUDE_META_COLUMNS, true)
            ->getResult('jit')[0];

        self::assertNotNull($note->getAuthor());
        self::assertSame('Ursula K. Le Guin', $note->getAuthor()->getName());
    }

    public function testNullLeftJoinedAssociationIsExplicitlyNullNotUninitialized(): void
    {
        [, $orphanId] = $this->persistNotes();

        $note = $this->em->createQuery(
            'SELECT PARTIAL n.{id, title}, a FROM ' . Note::class . ' n LEFT JOIN n.author a WHERE n.id = :id'
        )
            ->setParameter('id', $orphanId)
            ->setHint(Query::HINT_INCLUDE_META_COLUMNS, true)
            ->getResult('jit')[0];

        self::assertTrue(
            (new \ReflectionProperty(Note::class, 'author'))->isInitialized($note),
            'A LEFT JOINed association with no match must be written as null, not left uninitialized.'
        );
        self::assertNull($note->getAuthor());
    }

    public function testNonNullLeftJoinedAssociationStillWorks(): void
    {
        [$annotatedId] = $this->persistNotes();

        $note = $this->em->createQuery(
            'SELECT PARTIAL n.{id, title}, a FROM ' . Note::class . ' n LEFT JOIN n.author a WHERE n.id = :id'
        )
            ->setParameter('id', $annotatedId)
            ->setHint(Query::HINT_INCLUDE_META_COLUMNS, true)
            ->getResult('jit')[0];

        self::assertNotNull($note->getAuthor());
        self::assertSame('Ursula K. Le Guin', $note->getAuthor()->getName());
    }

    public function testReadingANullAssociationOnAPartialGhostDoesNotTriggerAReload(): void
    {
        if (!$this->supportsPartialNativeLazyGhosts()) {
            self::markTestSkipped('Requires native lazy objects with PARTIAL support (Doctrine ORM 3.7+, GH-12210).');
        }

        [, $orphanId] = $this->persistNotes();

        $note = $this->em->createQuery('SELECT PARTIAL n.{id, title} FROM ' . Note::class . ' n WHERE n.id = :id')
            ->setParameter('id', $orphanId)
            ->setHint(Query::HINT_INCLUDE_META_COLUMNS, true)
            ->getResult('jit')[0];

        self::assertTrue($this->em->getUnitOfWork()->isUninitializedObject($note));

        $this->queryCounter->count = 0;
        self::assertNull($note->getAuthor());
        self::assertSame(
            0,
            $this->queryCounter->count,
            'Reading a null-but-selected association must not reload the owning (partial) entity.'
        );
    }

    public function testReadingANullLeftJoinedAssociationOnAPartialGhostDoesNotTriggerAReload(): void
    {
        if (!$this->supportsPartialNativeLazyGhosts()) {
            self::markTestSkipped('Requires native lazy objects with PARTIAL support (Doctrine ORM 3.7+, GH-12210).');
        }

        [, $orphanId] = $this->persistNotes();

        $note = $this->em->createQuery(
            'SELECT PARTIAL n.{id, title}, a FROM ' . Note::class . ' n LEFT JOIN n.author a WHERE n.id = :id'
        )
            ->setParameter('id', $orphanId)
            ->setHint(Query::HINT_INCLUDE_META_COLUMNS, true)
            ->getResult('jit')[0];

        self::assertTrue($this->em->getUnitOfWork()->isUninitializedObject($note));

        $this->queryCounter->count = 0;
        self::assertNull($note->getAuthor());
        self::assertSame(
            0,
            $this->queryCounter->count,
            'Reading a null LEFT JOINed association must not reload the owning (partial) entity.'
        );
    }
}
