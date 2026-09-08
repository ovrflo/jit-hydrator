<?php

namespace Ovrflo\JitHydrator\Tests;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query;
use Ovrflo\JitHydrator\Tests\Fixtures\Author;
use Ovrflo\JitHydrator\Tests\Fixtures\Book;
use Ovrflo\JitHydrator\Tests\Fixtures\BookStatus;
use PHPUnit\Framework\TestCase;

/**
 * HydratorGenerator writes scalar fields through a cached ReflectionProperty
 * directly (bypassing Doctrine's propertyAccessors wrapper) whenever the
 * entity being hydrated is known to be a brand new, never-proxied instance.
 * These tests target the two situations where that optimization must NOT
 * take effect, or must otherwise remain correct:
 *
 *  - refreshing an already-existing (possibly still lazy/uninitialized)
 *    entity instance found in the identity map, where the object could be
 *    a proxy;
 *  - a field whose DB column is nullable but whose PHP property type is a
 *    non-nullable scalar, which requires special "leave uninitialized"
 *    handling instead of a plain assignment when the value is NULL.
 */
class FastPropertyWriteTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        $this->em = EntityManagerFactory::create();
    }

    public function testRefreshingAnExistingLazyProxyStillPopulatesItCorrectly(): void
    {
        $author = new Author('Iain Banks');
        $book = new Book('Consider Phlebas', new \DateTime('1987-01-01'));
        $author->addBook($book);
        $this->em->persist($author);
        $this->em->persist($book);
        $this->em->flush();
        $this->em->clear();

        // Fetch the book without joining its author: the author is loaded as
        // an uninitialized lazy proxy and registered in the identity map.
        $bookOnly = $this->em->createQuery('SELECT b FROM ' . Book::class . ' b')
            ->setHint(Query::HINT_INCLUDE_META_COLUMNS, true)
            ->getResult('jit')[0];

        $proxyBeforeRefresh = $bookOnly->getAuthor();
        self::assertInstanceOf(Author::class, $proxyBeforeRefresh);
        // Confirm this is genuinely still an uninitialized lazy object - i.e. that
        // the assertions below exercise newEntity_a($data, $proxy) with $proxy !==
        // null, and not a no-op identity-map hit on an already-loaded instance.
        self::assertTrue(
            $this->em->getClassMetadata(Author::class)->reflClass->isUninitializedLazyObject($proxyBeforeRefresh)
        );

        // Now, in the SAME unit of work, fetch the author joined - this must
        // hydrate directly into the existing proxy object (newEntity_a($data,
        // $proxy) with $proxy !== null), not create a second instance.
        $authorJoined = $this->em->createQuery('SELECT a, b FROM ' . Author::class . ' a JOIN a.books b')
            ->setHint(Query::HINT_INCLUDE_META_COLUMNS, true)
            ->getResult('jit')[0];

        self::assertSame($proxyBeforeRefresh, $authorJoined);
        self::assertSame('Iain Banks', $authorJoined->getName());
        self::assertTrue($authorJoined->isActive());
    }

    /**
     * Book::$subtitle is a non-nullable `string` property mapped to a
     * nullable column - a deliberate mismatch (see Book fixture docblock).
     * Assigning NULL to it via a plain ReflectionProperty::setValue() would
     * throw a TypeError; the generator must detect this and keep routing
     * that field through the safe accessor even for brand new instances.
     */
    public function testNullableColumnWithNonNullablePropertyTypeIsHydratedSafely(): void
    {
        $author = new Author('China Mieville');
        $book = new Book('Perdido Street Station', new \DateTime('2000-01-01'));
        $author->addBook($book);
        $this->em->persist($author);
        $this->em->persist($book);
        $this->em->flush();

        // Bypass the entity's own (non-nullable) property type and force the
        // column to NULL directly in the database.
        $this->em->getConnection()->executeStatement('UPDATE books SET subtitle = NULL');
        $this->em->clear();

        $query = $this->em->createQuery('SELECT b FROM ' . Book::class . ' b')
            ->setHint(Query::HINT_INCLUDE_META_COLUMNS, true);

        /** @var Book[] $books */
        $books = $query->getResult('jit');

        self::assertCount(1, $books);
        self::assertNull($books[0]->getSubtitle());
        self::assertSame('Perdido Street Station', $books[0]->getTitle());
    }

    /**
     * Book::$status is a BackedEnum-typed property. Its column stores the raw
     * scalar ('published'), and Doctrine's EnumPropertyAccessor is what
     * converts that scalar to/from the enum instance on read/write. Writing
     * the raw scalar directly into the property via ReflectionProperty (as
     * the fast path does for plain fields) would throw a TypeError, so the
     * generator must always route enum fields through the full accessor.
     */
    public function testEnumBackedFieldIsHydratedThroughTheEnumAccessor(): void
    {
        $author = new Author('N.K. Jemisin');
        $book = new Book('The Fifth Season', new \DateTime('2015-01-01'));
        $book->setStatus(BookStatus::Published);
        $author->addBook($book);
        $this->em->persist($author);
        $this->em->persist($book);
        $this->em->flush();
        $this->em->clear();

        $query = $this->em->createQuery('SELECT b FROM ' . Book::class . ' b')
            ->setHint(Query::HINT_INCLUDE_META_COLUMNS, true);

        /** @var Book[] $books */
        $books = $query->getResult('jit');

        self::assertCount(1, $books);
        self::assertSame(BookStatus::Published, $books[0]->getStatus());
    }
}
