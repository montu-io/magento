<?php
/**
 * Montu Affiliate Tracking Extension - Recommendation List
 *
 * One affiliate-curated list ("category") of recommended variants, as returned
 * by Montu. The variant ids are Magento product identifiers (child/simple
 * product ids) that the resolver turns into full product cards.
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model\Data;

class RecommendationList
{
    private string $id;
    private string $name;

    /**
     * @var int[]
     */
    private array $variantIds;

    /**
     * @param string $id
     * @param string $name
     * @param int[] $variantIds
     */
    public function __construct(string $id, string $name, array $variantIds)
    {
        $this->id = $id;
        $this->name = $name;
        $this->variantIds = array_values(array_map('intval', $variantIds));
    }

    /**
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return int[]
     */
    public function getVariantIds(): array
    {
        return $this->variantIds;
    }

    /**
     * @return int
     */
    public function getCount(): int
    {
        return count($this->variantIds);
    }
}
