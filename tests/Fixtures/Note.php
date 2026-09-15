<?php

namespace Ovrflo\JitHydrator\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/**
 * A nullable many-to-one, dedicated to exercising the "FK column was
 * selected but its value is null" code path in HydratorGenerator - as
 * opposed to Book::$author (never null) or Profile::$author (an
 * identifier association, always present).
 */
#[ORM\Entity]
#[ORM\Table(name: 'notes')]
class Note
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column(type: 'string')]
    private string $title;

    /**
     * Deliberately left unselected by every query in NullAssociationTest, so
     * the owning entity stays a genuinely partial (still-lazy) ghost - the
     * scenario the null-association fix targets. Without a field like this,
     * a query selecting every other field would leave nothing for the ghost
     * to stay lazy about, masking the bug this fixture exists to catch.
     */
    #[ORM\Column(type: 'string')]
    private string $body = '';

    #[ORM\ManyToOne(targetEntity: Author::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Author $author = null;

    public function __construct(string $title, ?Author $author = null)
    {
        $this->title = $title;
        $this->author = $author;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getAuthor(): ?Author
    {
        return $this->author;
    }
}
