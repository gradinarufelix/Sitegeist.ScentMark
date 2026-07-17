<?php

declare(strict_types=1);

namespace Sitegeist\ScentMark\Command;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Sitegeist\ScentMark\Domain\Model\Pack;
use Sitegeist\ScentMark\Domain\Repository\PackRepository;
use Sitegeist\ScentMark\Domain\Service\LeaderLeaseService;
use Sitegeist\ScentMark\Domain\ValueObject\LeaderLeaseResult;

class ScentMarkCommandController extends CommandController
{
    #[Flow\Inject]
    protected PackRepository $scentRepository;

    #[Flow\Inject]
    protected LeaderLeaseService $leaderLeaseService;

    /** @var array{defaultSeconds: int, minimumSeconds: int, maximumSeconds: int} */
    #[Flow\InjectConfiguration(path: 'leaderLease')]
    protected array $leaderLeaseConfiguration;

    /**
     * Mark the current deployment with the pack scent.
     *
     * Returns status code 0 if the pack was new and successfully marked
     * Returns status code 1 if the pack was already known beforehand
     *
     * @param string $packScent
     */
    public function markCommand(string $packScent): void
    {
        $packEntity = $this->scentRepository->findOneByPackScent($packScent);
        if ($packEntity instanceof Pack) {
            $this->outputLine(sprintf('Pack with scent "%s" is already known', $packScent));
            $this->quit(1);
        } else {
            $packEntity = new Pack();
            $packEntity->setPackScent($packScent);
            $this->scentRepository->add($packEntity);
            $this->outputLine(sprintf('New pack with scent "%s" was detected.', $packScent));
        }
    }


    /**
     * Acquire or renew pack leadership.
     *
     * Returns status code 0 if $packScent and $leaderScent match and the current pod is considered leader
     * Returns status code 1 if the current pod is not the leader for now
     *
     * @param string $packScent
     * @param string $leaderScent
     * @param int|null $leaseSeconds Override the configured lease duration
     */
    public function barkCommand(string $packScent, string $leaderScent, ?int $leaseSeconds = null): void
    {
        $leaseSeconds = $this->resolveLeaseSeconds($leaseSeconds);
        $result = $this->leaderLeaseService->acquireOrRenew($packScent, $leaderScent, $leaseSeconds);

        if ($result->getOutcome() === LeaderLeaseResult::NOT_FOUND) {
            $this->outputLine(sprintf('Pack "%s" not found', $packScent));
            $this->quit(1);
        }
        if ($result->getOutcome() === LeaderLeaseResult::RENEWED) {
            $this->outputLine(sprintf(
                'Pack "%s" renewed leader "%s" until %s',
                $packScent,
                $leaderScent,
                $this->formatExpiration($result)
            ));
            $this->quit(0);
        }
        if ($result->getOutcome() === LeaderLeaseResult::ACQUIRED) {
            $this->outputLine(sprintf(
                'Pack "%s" has NEW leader "%s" until %s',
                $packScent,
                $leaderScent,
                $this->formatExpiration($result)
            ));
            $this->quit(0);
        }

        $this->outputLine(sprintf(
            'Pack "%s" has OTHER leader "%s" which is not "%s"',
            $packScent,
            $result->getLeaderScent(),
            $leaderScent
        ));
        $this->quit(1);
    }

    /**
     * Release pack leadership if it is owned by the given leader scent.
     *
     * @param string $packScent
     * @param string $leaderScent
     */
    public function releaseCommand(string $packScent, string $leaderScent): void
    {
        $result = $this->leaderLeaseService->release($packScent, $leaderScent);
        if ($result->getOutcome() === LeaderLeaseResult::RELEASED) {
            $this->outputLine(sprintf('Pack "%s" released leader "%s"', $packScent, $leaderScent));
            return;
        }
        if ($result->getOutcome() === LeaderLeaseResult::NOT_FOUND) {
            $this->outputLine(sprintf('Pack "%s" not found', $packScent));
        } else {
            $this->outputLine(sprintf(
                'Pack "%s" is not owned by leader "%s"',
                $packScent,
                $leaderScent
            ));
        }
        $this->quit(1);
    }

    /**
     * Display current pack leadership without changing it.
     *
     * @param string $packScent
     */
    public function statusCommand(string $packScent): void
    {
        $result = $this->leaderLeaseService->status($packScent);
        if ($result->getOutcome() === LeaderLeaseResult::NOT_FOUND) {
            $this->outputLine(sprintf('Pack "%s" not found', $packScent));
            $this->quit(1);
        }

        $active = $result->getOutcome() === LeaderLeaseResult::ACTIVE;
        $this->outputLine('Pack scent  : %s', [$packScent]);
        $this->outputLine('Leader scent: %s', [$result->getLeaderScent() ?? '-']);
        $this->outputLine('Active      : %s', [$active ? 'yes' : 'no']);
        $this->outputLine('Expires     : %s', [$this->formatExpiration($result)]);
    }

    /**
     * Remove the oldest packs but keep a specified number of items
     *
     * @param int $keep
     */
    public function cleanupCommand(int $keep): void
    {
        $removed = $this->scentRepository->removeByAge($keep);
        $this->outputLine(sprintf('%d packs were removed', $removed));
    }

    private function resolveLeaseSeconds(?int $leaseSeconds): int
    {
        $leaseSeconds = $leaseSeconds ?? (int)$this->leaderLeaseConfiguration['defaultSeconds'];
        $minimumSeconds = (int)$this->leaderLeaseConfiguration['minimumSeconds'];
        $maximumSeconds = (int)$this->leaderLeaseConfiguration['maximumSeconds'];
        if ($leaseSeconds < $minimumSeconds || $leaseSeconds > $maximumSeconds) {
            $this->outputLine(sprintf(
                'Lease duration must be between %d and %d seconds; got %d',
                $minimumSeconds,
                $maximumSeconds,
                $leaseSeconds
            ));
            $this->quit(1);
        }
        return $leaseSeconds;
    }

    private function formatExpiration(LeaderLeaseResult $result): string
    {
        return $result->getExpiration()?->format(DATE_ATOM) ?? '-';
    }
}
