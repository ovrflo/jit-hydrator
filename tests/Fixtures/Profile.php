<?php

namespace Ovrflo\JitHydrator\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/**
 * Uses an association (author) as its primary key, exercising the
 * identifier-is-an-association code path in HydratorGenerator, which needs
 * to resolve the owning join column of the identifier's association mapping.
 */
#[ORM\Entity]
#[ORM\Table(name: 'profiles')]
class Profile
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Author::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id')]
    private Author $author;

    #[ORM\Column(type: 'string')]
    private string $bio;

    public function __construct(Author $author, string $bio)
    {
        $this->author = $author;
        $this->bio = $bio;
    }

    public function getAuthor(): Author
    {
        return $this->author;
    }

    public function getBio(): string
    {
        return $this->bio;
    }
}
