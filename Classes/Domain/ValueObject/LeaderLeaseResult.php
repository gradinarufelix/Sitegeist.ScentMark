<?php

declare(strict_types=1);

namespace Sitegeist\ScentMark\Domain\ValueObject;

final class LeaderLeaseResult
{
    public const ACQUIRED = 'acquired';
    public const RENEWED = 'renewed';
    public const DENIED = 'denied';
    public const RELEASED = 'released';
    public const ACTIVE = 'active';
    public const INACTIVE = 'inactive';
    public const NOT_FOUND = 'not_found';

    private const VALID_OUTCOMES = [
        self::ACQUIRED,
        self::RENEWED,
        self::DENIED,
        self::RELEASED,
        self::ACTIVE,
        self::INACTIVE,
        self::NOT_FOUND,
    ];

    private string $outcome;

    private ?string $leaderScent;

    private ?\DateTimeImmutable $expiration;

    public function __construct(
        string $outcome,
        ?string $leaderScent = null,
        ?\DateTimeImmutable $expiration = null
    ) {
        if (!in_array($outcome, self::VALID_OUTCOMES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown leader lease outcome "%s".', $outcome));
        }
        $this->outcome = $outcome;
        $this->leaderScent = $leaderScent;
        $this->expiration = $expiration;
    }

    public function getOutcome(): string
    {
        return $this->outcome;
    }

    public function getLeaderScent(): ?string
    {
        return $this->leaderScent;
    }

    public function getExpiration(): ?\DateTimeImmutable
    {
        return $this->expiration;
    }
}
