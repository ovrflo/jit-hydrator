<?php

namespace Ovrflo\JitHydrator\Tests;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query;
use Ovrflo\JitHydrator\Tests\Fixtures\Author;
use Ovrflo\JitHydrator\Tests\Fixtures\Book;
use PHPUnit\Framework\TestCase;

/**
 * DQL's INDEX BY clause ($queryBuilder->indexBy($alias, $field)) is used by
 * App\Repository\TorrentRepository::loadTorrentTopList() in the consuming
 * application (a plain "WHERE id IN (...) INDEX BY id" list query) - this
 * exercises that root-alias case, plus the analogous INDEX BY on a joined
 * to-many association, and confirms unsupported shapes fail loudly instead
 * of silently returning a plain, unindexed list.
 */
class IndexByTest extends TestCase
{
    private EntityManager $em;
    private int $book1Id;
    private int $book2Id;
    private int $book3Id;

    protected function setUp(): void
    {
        $this->em = EntityManagerFactory::create();

        $author1 = new Author('Terry Pratchett');
        $author2 = new Author('Neil Gaiman');

        $book1 = new Book('Guards! Guards!', new \DateTime('1989-01-01'));
        $book2 = new Book('Small Gods', new \DateTime('1992-01-01'));
        $book3 = new Book('American Gods', new \DateTime('2001-01-01'));
        $author1->addBook($book1);
        $author1->addBook($book2);
        $author2->addBook($book3);

        $this->em->persist($author1);
        $this->em->persist($author2);
        $this->em->persist($book1);
        $this->em->persist($book2);
        $this->em->persist($book3);
        $this->em->flush();

        $this->book1Id = $book1->getId();
        $this->book2Id = $book2->getId();
        $this->book3Id = $book3->getId();

        $this->em->clear();
    }

    public function testRootAliasIndexByKeysTheResultArray(): void
    {
        $query = $this->em->createQueryBuilder()
            ->select('b')
            ->from(Book::class, 'b')
            ->indexBy('b', 'b.id')
            ->getQuery()
            ->setHint(Query::HINT_INCLUDE_META_COLUMNS, true)
        ;

        /** @var Book[] $books */
        $books = $query->getResult('jit');

        self::assertSame([$this->book1Id, $this->book2Id, $this->book3Id], array_keys($books));
        self::assertSame('Guards! Guards!', $books[$this->book1Id]->getTitle());
        self::assertSame('Small Gods', $books[$this->book2Id]->getTitle());
        self::assertSame('American Gods', $books[$this->book3Id]->getTitle());
    }

    public function testRootAliasIndexByWithWhereInClauseMatchesStockObjectHydrator(): void
    {
        // Mirrors TorrentRepository::loadTorrentTopList()'s actual query shape.
        $ids = [$this->book2Id, $this->book1Id];

        $queryBuilder = $this->em->createQueryBuilder()
            ->select('b')
            ->from(Book::class, 'b')
            ->indexBy('b', 'b.id')
            ->where('b.id IN (:ids)')
            ->setParameter('ids', $ids)
        ;

        $jitResult = (clone $queryBuilder)->getQuery()
            ->setHint(Query::HINT_INCLUDE_META_COLUMNS, true)
            ->getResult('jit');

        $this->em->clear();

        $objectHydratorResult = (clone $queryBuilder)->getQuery()->getResult();

        self::assertSame(array_keys($objectHydratorResult), array_keys($jitResult));
    }

    public function testJoinedToManyIndexByKeysTheCollection(): void
    {
        // QueryBuilder::indexBy() only supports root aliases; a joined alias
        // needs the raw "INDEX BY" DQL syntax.
        $query = $this->em->createQuery(
            'SELECT a, b FROM ' . Author::class . ' a LEFT JOIN a.books b INDEX BY b.id WHERE a.name = :name'
        )
            ->setParameter('name', 'Terry Pratchett')
            ->setHint(Query::HINT_INCLUDE_META_COLUMNS, true)
        ;

        /** @var Author[] $authors */
        $authors = $query->getResult('jit');

        self::assertCount(1, $authors);
        $books = $authors[0]->getBooks();

        self::assertSame([$this->book1Id, $this->book2Id], array_keys($books->toArray()));
        self::assertSame('Guards! Guards!', $books[$this->book1Id]->getTitle());
        self::assertSame('Small Gods', $books[$this->book2Id]->getTitle());
    }

    public function testIndexByOnMixedEntityAndScalarResultThrowsInsteadOfSilentlyIgnoringIt(): void
    {
        $query = $this->em->createQueryBuilder()
            ->select('b', 'b.title AS titleUpper')
            ->from(Book::class, 'b')
            ->indexBy('b', 'b.id')
            ->getQuery()
            ->setHint(Query::HINT_INCLUDE_META_COLUMNS, true)
        ;

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('INDEX BY');

        $query->getResult('jit');
    }
}
