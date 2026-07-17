<?php

declare(strict_types=1);

namespace Sitegeist\ScentMark\Tests\Unit\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use Sitegeist\ScentMark\Domain\ValueObject\LeaderLeaseResult;

final class LeaderLeaseResultTest extends TestCase
{
    public function testKnownOutcomeIsAccepted(): void
    {
        $expiration = new \DateTimeImmutable('2026-07-17T12:00:00+00:00');
        $result = new LeaderLeaseResult(LeaderLeaseResult::ACTIVE, 'replica-a', $expiration);

        self::assertSame(LeaderLeaseResult::ACTIVE, $result->getOutcome());
        self::assertSame('replica-a', $result->getLeaderScent());
        self::assertSame($expiration, $result->getExpiration());
    }

    public function testUnknownOutcomeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new LeaderLeaseResult('unknown');
    }
}
