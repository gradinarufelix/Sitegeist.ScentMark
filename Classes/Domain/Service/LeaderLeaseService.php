<?php

declare(strict_types=1);

namespace Sitegeist\ScentMark\Domain\Service;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Neos\Flow\Annotations as Flow;
use Sitegeist\ScentMark\Domain\Model\Pack;
use Sitegeist\ScentMark\Domain\ValueObject\LeaderLeaseResult;

#[Flow\Scope('singleton')]
final class LeaderLeaseService
{
    #[Flow\Inject]
    protected EntityManagerInterface $entityManager;

    public function acquireOrRenew(
        string $packScent,
        string $leaderScent,
        int $leaseSeconds
    ): LeaderLeaseResult {
        if ($leaseSeconds <= 0) {
            throw new \InvalidArgumentException('The leader lease must be a positive number of seconds.');
        }

        /** @var LeaderLeaseResult $result */
        $result = $this->entityManager->transactional(function (EntityManagerInterface $entityManager) use (
            $packScent,
            $leaderScent,
            $leaseSeconds
        ): LeaderLeaseResult {
            $pack = $this->findPack($entityManager, $packScent, true);
            if (!$pack instanceof Pack) {
                return new LeaderLeaseResult(LeaderLeaseResult::NOT_FOUND);
            }

            $now = $this->databaseNow($entityManager);
            $currentLeaderScent = $pack->getCurrentlyActiveLeaderScent($now);
            if ($currentLeaderScent !== null && $currentLeaderScent !== $leaderScent) {
                return new LeaderLeaseResult(
                    LeaderLeaseResult::DENIED,
                    $currentLeaderScent,
                    $pack->getLeadExpiration()
                );
            }

            $outcome = $currentLeaderScent === $leaderScent
                ? LeaderLeaseResult::RENEWED
                : LeaderLeaseResult::ACQUIRED;
            $expiration = $now->modify(sprintf('+%d seconds', $leaseSeconds));
            $pack->setLeaderLease($leaderScent, $expiration);
            $entityManager->persist($pack);

            return new LeaderLeaseResult($outcome, $leaderScent, $expiration);
        });

        return $result;
    }

    public function release(string $packScent, string $leaderScent): LeaderLeaseResult
    {
        /** @var LeaderLeaseResult $result */
        $result = $this->entityManager->transactional(function (EntityManagerInterface $entityManager) use (
            $packScent,
            $leaderScent
        ): LeaderLeaseResult {
            $pack = $this->findPack($entityManager, $packScent, true);
            if (!$pack instanceof Pack) {
                return new LeaderLeaseResult(LeaderLeaseResult::NOT_FOUND);
            }

            if ($pack->getLeaderScent() !== $leaderScent) {
                return new LeaderLeaseResult(
                    LeaderLeaseResult::DENIED,
                    $pack->getCurrentlyActiveLeaderScent($this->databaseNow($entityManager)),
                    $pack->getLeadExpiration()
                );
            }

            $pack->clearLeaderLease();
            $entityManager->persist($pack);
            return new LeaderLeaseResult(LeaderLeaseResult::RELEASED);
        });

        return $result;
    }

    public function status(string $packScent): LeaderLeaseResult
    {
        $pack = $this->findPack($this->entityManager, $packScent, false);
        if (!$pack instanceof Pack) {
            return new LeaderLeaseResult(LeaderLeaseResult::NOT_FOUND);
        }

        return new LeaderLeaseResult(
            $pack->getCurrentlyActiveLeaderScent($this->databaseNow($this->entityManager)) !== null
                ? LeaderLeaseResult::ACTIVE
                : LeaderLeaseResult::INACTIVE,
            $pack->getLeaderScent(),
            $pack->getLeadExpiration()
        );
    }

    private function findPack(
        EntityManagerInterface $entityManager,
        string $packScent,
        bool $lockForUpdate
    ): ?Pack {
        $query = $entityManager->createQuery(
            'SELECT p FROM Sitegeist\\ScentMark\\Domain\\Model\\Pack p WHERE p.packScent = :packScent'
        );
        $query->setParameter('packScent', $packScent);
        if ($lockForUpdate) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }

        $result = $query->getOneOrNullResult();
        return $result instanceof Pack ? $result : null;
    }

    private function databaseNow(EntityManagerInterface $entityManager): \DateTimeImmutable
    {
        $connection = $entityManager->getConnection();
        $currentTimestampSql = $connection->getDatabasePlatform()->getCurrentTimestampSQL();
        $value = $connection->fetchOne('SELECT ' . $currentTimestampSql);
        if (!is_string($value) || $value === '') {
            throw new \RuntimeException('The database did not return a valid current timestamp.');
        }

        return new \DateTimeImmutable($value);
    }
}
