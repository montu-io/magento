<?php
/**
 * Montu Affiliate Tracking Extension - Affiliate Recommendations
 *
 * The full Montu response for one affiliate: a set of curated lists, each
 * holding the variant ids that affiliate recommends.
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model\Data;

class AffiliateRecommendations
{
    private string $code;

    /**
     * @var RecommendationList[]
     */
    private array $lists;

    /**
     * @param string $code
     * @param RecommendationList[] $lists
     */
    public function __construct(string $code, array $lists)
    {
        $this->code = $code;
        $this->lists = $lists;
    }

    /**
     * @return string
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * @return RecommendationList[]
     */
    public function getLists(): array
    {
        return $this->lists;
    }

    /**
     * Find a list by id.
     *
     * @param string $listId
     * @return RecommendationList|null
     */
    public function getListById(string $listId): ?RecommendationList
    {
        foreach ($this->lists as $list) {
            if ($list->getId() === $listId) {
                return $list;
            }
        }
        return null;
    }

    /**
     * All variant ids across every list, de-duplicated, order preserved.
     *
     * @return int[]
     */
    public function getAllVariantIds(): array
    {
        $ids = [];
        foreach ($this->lists as $list) {
            foreach ($list->getVariantIds() as $id) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }
}
