<?php

namespace Ovrflo\JitHydrator\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'books')]
class Book
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column(type: 'string')]
    private string $title;

    /**
     * Deliberately mismatched mapping: the DB column is nullable, but the PHP
     * property type is a non-nullable `string`. Doctrine tolerates this by
     * leaving the property "uninitialized" (via its property-accessor's
     * unset-on-null handling) rather than assigning null, which the plain
     * property type would reject with a TypeError. This fixture exists to
     * exercise that exact edge case against HydratorGenerator's fast
     * (raw-reflection) property-write path, which must recognize this case
     * and fall back to the safe accessor instead of assigning null directly.
     */
    #[ORM\Column(type: 'string', nullable: true)]
    private string $subtitle = '';

    #[ORM\Column(type: 'string', enumType: BookStatus::class)]
    private BookStatus $status = BookStatus::Draft;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $publishedAt;

    #[ORM\ManyToOne(targetEntity: Author::class, inversedBy: 'books')]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: false)]
    private Author $author;

    public function __construct(string $title, \DateTime $publishedAt)
    {
        $this->title = $title;
        $this->publishedAt = $publishedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getSubtitle(): ?string
    {
        return isset($this->subtitle) ? $this->subtitle : null;
    }

    public function getStatus(): BookStatus
    {
        return $this->status;
    }

    public function setStatus(BookStatus $status): void
    {
        $this->status = $status;
    }

    public function getPublishedAt(): \DateTime
    {
        return $this->publishedAt;
    }

    public function getAuthor(): Author
    {
        return $this->author;
    }

    public function setAuthor(Author $author): void
    {
        $this->author = $author;
    }
}
