<?php

declare(strict_types=1);

namespace Sitegeist\ScentMark\Domain\Model;

use Neos\Flow\Annotations as Flow;
use Doctrine\ORM\Mapping as ORM;

/**
 * @Flow\Entity
 * @ORM\Table(
 *     uniqueConstraints={
 *       @ORM\UniqueConstraint(name="unique_values",columns={"packScent"}),
 *     },
 *     indexes={
 *       @ORM\Index(name="index_values",columns={"packScent"}),
 *     }
 *  )
 */
class Pack
{
    /**
     * @var string
     */
    protected $packScent;

    /**
     * @var string|null
     * @ORM\Column(nullable=true)
     */
    protected $leaderScent;

    /**
     * @var \DateTimeImmutable|null
     * @ORM\Column(nullable=true)
     */
    protected $leadExpiration;

    /**
     * @var \DateTimeImmutable
     */
    protected $dateTime;

    public function __construct()
    {
        $this->dateTime = new \DateTimeImmutable();
        $this->packScent = '';
    }

    public function getPackScent(): string
    {
        return $this->packScent;
    }

    public function setPackScent(string $packScent): void
    {
        $this->packScent = $packScent;
    }

    public function getDateTime(): \DateTimeImmutable
    {
        return $this->dateTime;
    }

    public function setDateTime(\DateTimeImmutable $dateTime): void
    {
        $this->dateTime = $dateTime;
    }

    public function getLeaderScent(): ?string
    {
        return $this->leaderScent;
    }

    public function setLeaderScent(?string $leaderScent): void
    {
        $this->leaderScent = $leaderScent;
    }

    public function getLeadExpiration(): ?\DateTimeImmutable
    {
        return $this->leadExpiration;
    }

    public function setLeadExpiration(?\DateTimeImmutable $leadExpiration): void
    {
        $this->leadExpiration = $leadExpiration;
    }

    public function getCurrentlyActiveLeaderScent(?\DateTimeImmutable $now = null): ?string
    {
        $now = $now ?? new \DateTimeImmutable();
        if (
            $this->leaderScent !== null
            && $this->leadExpiration instanceof \DateTimeImmutable
            && $this->leadExpiration > $now
        ) {
            return $this->leaderScent;
        }
        return null;
    }

    public function setCurrentlyActiveLeaderScent(string $leaderScent, int $leaseSeconds = 3600): void
    {
        if ($leaseSeconds <= 0) {
            throw new \InvalidArgumentException('The leader lease must be a positive number of seconds.');
        }
        if ($this->getCurrentlyActiveLeaderScent() !== null) {
            throw new \Exception('Already has a leader');
        }
        $this->setLeaderLease(
            $leaderScent,
            (new \DateTimeImmutable())->modify(sprintf('+%d seconds', $leaseSeconds))
        );
    }

    public function setLeaderLease(string $leaderScent, \DateTimeImmutable $expiration): void
    {
        $this->leaderScent = $leaderScent;
        $this->leadExpiration = $expiration;
    }

    public function clearLeaderLease(): void
    {
        $this->leaderScent = null;
        $this->leadExpiration = null;
    }
}
