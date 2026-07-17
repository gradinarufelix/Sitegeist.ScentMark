<?php

declare(strict_types=1);

namespace Sitegeist\ScentMark\Domain\Repository;

use Neos\Flow\Persistence\QueryInterface;
use Neos\Flow\Persistence\Repository;
use Neos\Flow\Annotations as Flow;
use Sitegeist\ScentMark\Domain\Model\Pack;

/**
 * @method Pack|null findOneByPackScent(string $packScent)
 */
#[Flow\Scope('singleton')]
class PackRepository extends Repository
{
    /**
     * @var array<string, string>
     */
    protected $defaultOrderings = [
        'dateTime' => QueryInterface::ORDER_DESCENDING
    ];

    public function removeByAge(int $keep): int
    {
        $outdatedScentsQuery = $this->findAll();
        $countRemoved = 0;
        $number = 0;
        foreach ($outdatedScentsQuery as $scent) {
            $number++;
            if ($number > $keep) {
                $this->remove($scent);
                $countRemoved++;
            }
        }
        return $countRemoved;
    }
}
