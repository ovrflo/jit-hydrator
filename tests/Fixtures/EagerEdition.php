<?php

namespace Ovrflo\JitHydrator\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/**
 * A fetch=EAGER many-to-one that is deliberately NOT joined in test queries,
 * to exercise HydratorGenerator's deferred-eager-loading code path (the
 * UnitOfWork::HINT_DEFEREAGERLOAD branch that pokes UnitOfWork's private
 * eagerLoadingEntities map via reflection).
 */
#[ORM\Entity]
#[ORM\Table(name: 'eager_editions')]
class EagerEdition
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column(type: 'string')]
    private string $title;

    #[ORM\ManyToOne(targetEntity: EagerPublisher::class, fetch: 'EAGER')]
    #[ORM\JoinColumn(name: 'publisher_id', referencedColumnName: 'id', nullable: false)]
    private EagerPublisher $publisher;

    public function __construct(string $title, EagerPublisher $publisher)
    {
        $this->title = $title;
        $this->publisher = $publisher;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getPublisher(): EagerPublisher
    {
        return $this->publisher;
    }
}
