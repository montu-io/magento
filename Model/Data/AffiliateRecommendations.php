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
     * Full ordered set of recommended variant ids (including products in no
     * list). Null means "derive from the lists" (used by the stub provider).
     *
     * @var int[]|null
     */
    private ?array $allVariantIds;

    private ?AffiliateInfo $affiliate;

    /**
     * @param string $code
     * @param RecommendationList[] $lists
     * @param int[]|null $allVariantIds
     * @param AffiliateInfo|null $affiliate
     */
    public function __construct(
        string $code,
        array $lists,
        ?array $allVariantIds = null,
        ?AffiliateInfo $affiliate = null
    ) {
        $this->code = $code;
        $this->lists = $lists;
        $this->allVariantIds = $allVariantIds;
        $this->affiliate = $affiliate;
    }

    /**
     * The affiliate's public identity (name + picture), or null if unknown.
     *
     * @return AffiliateInfo|null
     */
    public function getAffiliate(): ?AffiliateInfo
    {
        return $this->affiliate;
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
     * All recommended variant ids, de-duplicated, order preserved.
     *
     * When the response provided an explicit product set (the live endpoint),
     * that is used verbatim so products in no list still appear under "All".
     * Otherwise it falls back to the union of the lists (the stub provider).
     *
     * @return int[]
     */
    public function getAllVariantIds(): array
    {
        if ($this->allVariantIds !== null) {
            return $this->allVariantIds;
        }
        $ids = [];
        foreach ($this->lists as $list) {
            foreach ($list->getVariantIds() as $id) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }
}
