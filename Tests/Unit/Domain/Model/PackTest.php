<?php

declare(strict_types=1);

namespace Sitegeist\ScentMark\Tests\Unit\Domain\Model;

use PHPUnit\Framework\TestCase;
use Sitegeist\ScentMark\Domain\Model\Pack;

final class PackTest extends TestCase
{
    public function testLeaderIsActiveUntilItsExpiration(): void
    {
        $pack = new Pack();
        $expiration = new \DateTimeImmutable('2026-07-17T12:00:00+00:00');
        $pack->setLeaderLease('replica-a', $expiration);

        self::assertSame(
            'replica-a',
            $pack->getCurrentlyActiveLeaderScent($expiration->modify('-1 second'))
        );
        self::assertNull($pack->getCurrentlyActiveLeaderScent($expiration));
    }

    public function testLeaderLeaseCanBeCleared(): void
    {
        $pack = new Pack();
        $pack->setLeaderLease(
            'replica-a',
            new \DateTimeImmutable('2026-07-17T12:00:00+00:00')
        );

        $pack->clearLeaderLease();

        self::assertNull($pack->getLeaderScent());
        self::assertNull($pack->getLeadExpiration());
    }

    public function testLegacySetterRejectsNonPositiveLeaseDuration(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Pack())->setCurrentlyActiveLeaderScent('replica-a', 0);
    }
}
