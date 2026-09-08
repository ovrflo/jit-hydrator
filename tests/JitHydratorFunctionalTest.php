<?php

namespace Ovrflo\JitHydrator\Tests;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query;
use Ovrflo\JitHydrator\Tests\Fixtures\Author;
use Ovrflo\JitHydrator\Tests\Fixtures\Book;
use Ovrflo\JitHydrator\Tests\Fixtures\Profile;
use PHPUnit\Framework\TestCase;

class JitHydratorFunctionalTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        $this->em = EntityManagerFactory::create();

        $author = new Author('Terry Pratchett');
        $author->setActive(true);
        $author->setTags(['fantasy', 'satire']);

        $book1 = new Book('Guards! Guards!', new \DateTime('1989-01-01'));
        $book2 = new Book('Small Gods', new \DateTime('1992-01-01'));
        $author->addBook($book1);
        $author->addBook($book2);

        $this->em->persist($author);
        $this->em->persist($book1);
        $this->em->persist($book2);
        $this->em->flush();
        $this->em->clear();
    }

    public function testHydratesScalarFieldsWithTypeConversion(): void
    {
        $query = $this->em->createQuery('SELECT a FROM ' . Author::class . ' a')
            ->setHint(Query::HINT_INCLUDE_META_COLUMNS, true);

        /** @var Author[] $authors */
        $authors = $query->getResult('jit');

        self::assertCount(1, $authors);
        $author = $authors[0];
        self::assertInstanceOf(Author::class, $author);
        self::assertSame('Terry Pratchett', $author->getName());
        self::assertTrue($author->isActive());
        self::assertSame(['fantasy', 'satire'], $author->getTags());
    }

    public function testHydratesManyToOneJoin(): void
    {
        $query = $this->em->createQuery(
            'SELECT b, a FROM ' . Book::class . ' b JOIN b.author a'
        )->setHint(Query::HINT_INCLUDE_META_COLUMNS, true);

        /** @var Book[] $books */
        $books = $query->getResult('jit');

        self::assertCount(2, $books);
        $titles = array_map(static fn (Book $b) => $b->getTitle(), $books);
        sort($titles);
        self::assertSame(['Guards! Guards!', 'Small Gods'], $titles);

        foreach ($books as $book) {
            self::assertSame('Terry Pratchett', $book->getAuthor()->getName());
            self::assertInstanceOf(\DateTimeInterface::class, $book->getPublishedAt());
        }
    }

    /**
     * Unlike testHydratesManyToOneJoin, the author association here is NOT
     * joined in the DQL, so it takes the lazy-proxy branch of the generator
     * (proxyFactory->getProxy() + sourceToTargetKeyColumns resolution)
     * instead of the joined-entity branch.
     */
    public function testHydratesManyToOneAsLazyProxyWhenNotJoined(): void
    {
        $query = $this->em->createQuery('SELECT b FROM ' . Book::class . ' b')
            ->setHint(Query::HINT_INCLUDE_META_COLUMNS, true);

        /** @var Book[] $books */
        $books = $query->getResult('jit');

        self::assertCount(2, $books);
        foreach ($books as $book) {
            self::assertSame('Terry Pratchett', $book->getAuthor()->getName());
        }
    }

    public function testHydratesOneToManyJoin(): void
    {
        $query = $this->em->createQuery(
            'SELECT a, b FROM ' . Author::class . ' a JOIN a.books b'
        )->setHint(Query::HINT_INCLUDE_META_COLUMNS, true);

        /** @var Author[] $authors */
        $authors = $query->getResult('jit');

        self::assertCount(1, $authors);
        $author = $authors[0];
        self::assertCount(2, $author->getBooks());

        foreach ($author->getBooks() as $book) {
            self::assertInstanceOf(Book::class, $book);
            self::assertSame($author, $book->getAuthor());
        }
    }

    /**
     * Exercises hydration of an entity whose identifier is itself a
     * to-one association (a shared/foreign primary key), which requires
     * resolving the identifier field through the association's join column
     * rather than a plain scalar column.
     */
    public function testHydratesEntityWithAssociationAsIdentifier(): void
    {
        $author = $this->em->getRepository(Author::class)->findOneBy(['name' => 'Terry Pratchett']);
        $profile = new Profile($author, 'Fantasy & satire author');
        $this->em->persist($profile);
        $this->em->flush();
        $this->em->clear();

        $query = $this->em->createQuery('SELECT p, a FROM ' . Profile::class . ' p JOIN p.author a')
            ->setHint(Query::HINT_INCLUDE_META_COLUMNS, true);

        /** @var Profile[] $profiles */
        $profiles = $query->getResult('jit');

        self::assertCount(1, $profiles);
        self::assertSame('Fantasy & satire author', $profiles[0]->getBio());
        self::assertSame('Terry Pratchett', $profiles[0]->getAuthor()->getName());
    }

    public function testIdentityMapReturnsSameInstanceAcrossRows(): void
    {
        $query = $this->em->createQuery(
            'SELECT a, b FROM ' . Author::class . ' a JOIN a.books b'
        )->setHint(Query::HINT_INCLUDE_META_COLUMNS, true);

        /** @var Author[] $authors */
        $authors = $query->getResult('jit');

        self::assertCount(1, $authors);
        $author = $authors[0];

        $books = array_values($author->getBooks()->toArray());
        self::assertNotSame($books[0], $books[1]);
        self::assertSame($author, $books[0]->getAuthor());
        self::assertSame($author, $books[1]->getAuthor());
    }
}
