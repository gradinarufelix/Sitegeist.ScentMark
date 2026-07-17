<?php

declare(strict_types=1);

namespace Sitegeist\ScentMark\Tests\Unit\Domain\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sitegeist\ScentMark\Domain\Model\Pack;
use Sitegeist\ScentMark\Domain\Service\LeaderLeaseService;
use Sitegeist\ScentMark\Domain\ValueObject\LeaderLeaseResult;

final class LeaderLeaseServiceTest extends TestCase
{
    public function testAcquiringLeaseUsesPessimisticLockAndDatabaseTime(): void
    {
        $pack = new Pack();
        $query = $this->createPackQuery($pack, true);
        $entityManager = $this->createEntityManager($query, '2026-07-17 12:00:00');

        $result = $this->createService($entityManager)->acquireOrRenew('release-123', 'replica-a', 90);

        self::assertSame(LeaderLeaseResult::ACQUIRED, $result->getOutcome());
        self::assertSame('replica-a', $pack->getLeaderScent());
        self::assertSame('2026-07-17 12:01:30', $pack->getLeadExpiration()?->format('Y-m-d H:i:s'));
    }

    public function testActiveLeaseOwnedByAnotherReplicaIsDenied(): void
    {
        $pack = new Pack();
        $pack->setLeaderLease('replica-a', new \DateTimeImmutable('2026-07-17 12:05:00'));
        $query = $this->createPackQuery($pack, true);
        $entityManager = $this->createEntityManager($query, '2026-07-17 12:00:00', true, false);

        $result = $this->createService($entityManager)->acquireOrRenew('release-123', 'replica-b', 90);

        self::assertSame(LeaderLeaseResult::DENIED, $result->getOutcome());
        self::assertSame('replica-a', $result->getLeaderScent());
        self::assertSame('replica-a', $pack->getLeaderScent());
    }

    public function testCurrentOwnerCanRenewLease(): void
    {
        $pack = new Pack();
        $pack->setLeaderLease('replica-a', new \DateTimeImmutable('2026-07-17 12:00:30'));
        $query = $this->createPackQuery($pack, true);
        $entityManager = $this->createEntityManager($query, '2026-07-17 12:00:00');

        $result = $this->createService($entityManager)->acquireOrRenew('release-123', 'replica-a', 90);

        self::assertSame(LeaderLeaseResult::RENEWED, $result->getOutcome());
        self::assertSame('2026-07-17 12:01:30', $result->getExpiration()?->format('Y-m-d H:i:s'));
    }

    public function testCurrentOwnerCanReleaseLease(): void
    {
        $pack = new Pack();
        $pack->setLeaderLease('replica-a', new \DateTimeImmutable('2026-07-17 12:05:00'));
        $query = $this->createPackQuery($pack, true);
        $entityManager = $this->createEntityManager($query, null);

        $result = $this->createService($entityManager)->release('release-123', 'replica-a');

        self::assertSame(LeaderLeaseResult::RELEASED, $result->getOutcome());
        self::assertNull($pack->getLeaderScent());
        self::assertNull($pack->getLeadExpiration());
    }

    public function testStatusIsReadOnlyAndReportsActiveLease(): void
    {
        $pack = new Pack();
        $pack->setLeaderLease('replica-a', new \DateTimeImmutable('2026-07-17 12:05:00'));
        $query = $this->createPackQuery($pack, false);
        $entityManager = $this->createEntityManager($query, '2026-07-17 12:00:00', false);

        $result = $this->createService($entityManager)->status('release-123');

        self::assertSame(LeaderLeaseResult::ACTIVE, $result->getOutcome());
        self::assertSame('replica-a', $result->getLeaderScent());
    }

    /**
     * @return Query&MockObject
     */
    private function createPackQuery(Pack $pack, bool $expectLock): Query
    {
        $query = $this->createMock(Query::class);
        $query->expects(self::once())
            ->method('setParameter')
            ->with('packScent', 'release-123')
            ->willReturnSelf();
        $query->expects($expectLock ? self::once() : self::never())
            ->method('setLockMode')
            ->with(LockMode::PESSIMISTIC_WRITE)
            ->willReturnSelf();
        $query->expects(self::once())
            ->method('getOneOrNullResult')
            ->willReturn($pack);

        return $query;
    }

    /**
     * @param Query&MockObject $query
     * @return EntityManagerInterface&MockObject
     */
    private function createEntityManager(
        Query $query,
        ?string $databaseNow,
        bool $expectTransaction = true,
        bool $expectPersist = true
    ): EntityManagerInterface {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        if ($expectTransaction) {
            $entityManager->expects(self::once())
                ->method('transactional')
                ->willReturnCallback(
                    static fn (callable $callback): LeaderLeaseResult => $callback($entityManager)
                );
        } else {
            $entityManager->expects(self::never())->method('transactional');
        }
        $entityManager->expects(self::once())
            ->method('createQuery')
            ->willReturn($query);
        $entityManager->expects($expectTransaction && $expectPersist ? self::once() : self::never())
            ->method('persist')
            ->with(self::isInstanceOf(Pack::class));

        if ($databaseNow !== null) {
            $platform = $this->createMock(AbstractPlatform::class);
            $platform->method('getCurrentTimestampSQL')->willReturn('CURRENT_TIMESTAMP');

            $connection = $this->createMock(Connection::class);
            $connection->method('getDatabasePlatform')->willReturn($platform);
            $connection->expects(self::once())
                ->method('fetchOne')
                ->with('SELECT CURRENT_TIMESTAMP')
                ->willReturn($databaseNow);

            $entityManager->expects(self::once())
                ->method('getConnection')
                ->willReturn($connection);
        } else {
            $entityManager->expects(self::never())->method('getConnection');
        }

        return $entityManager;
    }

    private function createService(EntityManagerInterface $entityManager): LeaderLeaseService
    {
        $service = new LeaderLeaseService();
        $property = new \ReflectionProperty(LeaderLeaseService::class, 'entityManager');
        $property->setValue($service, $entityManager);

        return $service;
    }
}
