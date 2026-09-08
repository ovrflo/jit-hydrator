<?php

namespace Ovrflo\JitHydrator\Tests;

use Doctrine\ORM\Mapping\ClassMetadata;
use Ovrflo\JitHydrator\HydratorGenerator;
use Ovrflo\JitHydrator\Tests\Fixtures\Author;
use Ovrflo\JitHydrator\Tests\Fixtures\Book;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * These tests exercise HydratorGenerator's mapping-access helpers directly.
 * They matter because Doctrine ORM 2.x represents field/association mappings
 * as plain arrays, while ORM 3.x represents them as value objects
 * (FieldMapping/AssociationMapping and subclasses). The helpers must behave
 * identically for both shapes so the generated hydrator works unmodified on
 * either major version.
 */
class MappingCompatTest extends TestCase
{
    private static function call(string $method, ...$args)
    {
        $ref = new ReflectionMethod(HydratorGenerator::class, $method);

        return $ref->invoke(null, ...$args);
    }

    public function testMappingValueReadsFromArray(): void
    {
        $mapping = ['type' => 'string', 'columnName' => 'name'];

        self::assertSame('string', self::call('mappingValue', $mapping, 'type'));
        self::assertSame('name', self::call('mappingValue', $mapping, 'columnName'));
        self::assertNull(self::call('mappingValue', $mapping, 'missing'));
    }

    public function testMappingValueReadsFromObject(): void
    {
        $mapping = new class {
            public string $type = 'integer';
        };

        self::assertSame('integer', self::call('mappingValue', $mapping, 'type'));
        self::assertNull(self::call('mappingValue', $mapping, 'missing'));
    }

    public function testMappingValueReadsRealFieldMappingObject(): void
    {
        $em = EntityManagerFactory::create();
        $fieldMapping = $em->getClassMetadata(Author::class)->fieldMappings['name'];

        self::assertSame('string', self::call('mappingValue', $fieldMapping, 'type'));
    }

    public function testMappingTypeFromOrm2StyleArray(): void
    {
        $mapping = ['type' => ClassMetadata::MANY_TO_ONE];

        self::assertSame(ClassMetadata::MANY_TO_ONE, self::call('mappingType', $mapping));
    }

    public function testMappingTypeFromRealOrm3AssociationObject(): void
    {
        $em = EntityManagerFactory::create();
        $mapping = $em->getClassMetadata(Book::class)->associationMappings['author'];

        self::assertSame(ClassMetadata::MANY_TO_ONE, self::call('mappingType', $mapping));
    }

    public function testIsToOneOwningSideForOrm2StyleManyToOneArray(): void
    {
        $mapping = ['type' => ClassMetadata::MANY_TO_ONE];

        self::assertTrue(self::call('isToOneOwningSide', $mapping));
    }

    public function testIsToOneOwningSideForOrm2StyleOwningOneToOneArray(): void
    {
        $mapping = [
            'type' => ClassMetadata::ONE_TO_ONE,
            'joinColumns' => [['name' => 'other_id', 'referencedColumnName' => 'id']],
        ];

        self::assertTrue(self::call('isToOneOwningSide', $mapping));
    }

    public function testIsToOneOwningSideForOrm2StyleInverseOneToOneArray(): void
    {
        $mapping = [
            'type' => ClassMetadata::ONE_TO_ONE,
            'mappedBy' => 'other',
        ];

        self::assertFalse(self::call('isToOneOwningSide', $mapping));
    }

    public function testIsToOneOwningSideForOrm2StyleOneToManyArray(): void
    {
        $mapping = ['type' => ClassMetadata::ONE_TO_MANY, 'mappedBy' => 'author'];

        self::assertFalse(self::call('isToOneOwningSide', $mapping));
    }

    public function testIsToOneOwningSideForRealOrm3ManyToOneObject(): void
    {
        $em = EntityManagerFactory::create();
        $mapping = $em->getClassMetadata(Book::class)->associationMappings['author'];

        self::assertTrue(self::call('isToOneOwningSide', $mapping));
    }

    public function testIsToOneOwningSideForRealOrm3OneToManyObject(): void
    {
        $em = EntityManagerFactory::create();
        $mapping = $em->getClassMetadata(Author::class)->associationMappings['books'];

        self::assertFalse(self::call('isToOneOwningSide', $mapping));
    }
}
