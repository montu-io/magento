<?php
/**
 * Montu Affiliate Tracking Extension - Stub Recommendation Provider
 *
 * Builds realistic affiliate recommendations from the REAL Magento catalog while
 * the Montu recommendations endpoint does not exist yet. It picks visible,
 * enabled products and buckets them into a few demo lists, returning their
 * variant ids (a configurable's first child, or a simple product's id) exactly
 * as the live Montu endpoint eventually will - so the storefront, variant
 * switching and add-to-cart all work end to end against true catalog data.
 *
 * Remove/disable once Service\RecommendationClient points at the live endpoint.
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Service;

use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableResource;
use Montu\Affiliate\Model\Data\AffiliateRecommendations;
use Montu\Affiliate\Model\Data\RecommendationList;
use Psr\Log\LoggerInterface;

class StubRecommendationProvider
{
    /**
     * How many catalog products to pull for the demo.
     */
    private const SAMPLE_SIZE = 24;

    /**
     * Demo list definitions: id => display name.
     */
    private const DEMO_LISTS = [
        'imprescindibles' => 'Imprescindibles',
        'para-regalar' => 'Para regalar',
        'lo-mas-nuevo' => 'Lo más nuevo',
    ];

    private CollectionFactory $collectionFactory;
    private ConfigurableResource $configurableResource;
    private LoggerInterface $logger;

    /**
     * @param CollectionFactory $collectionFactory
     * @param ConfigurableResource $configurableResource
     * @param LoggerInterface $logger
     */
    public function __construct(
        CollectionFactory $collectionFactory,
        ConfigurableResource $configurableResource,
        LoggerInterface $logger
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->configurableResource = $configurableResource;
        $this->logger = $logger;
    }

    /**
     * Build demo recommendations for the given affiliate code.
     *
     * @param string $affiliateCode
     * @return AffiliateRecommendations
     */
    public function get(string $affiliateCode): AffiliateRecommendations
    {
        $variantIds = $this->collectVariantIds();

        if ($variantIds === []) {
            $this->logger->debug(
                '[Montu Affiliate] Stub provider found no catalog products to recommend'
            );
            return new AffiliateRecommendations($affiliateCode, []);
        }

        return new AffiliateRecommendations(
            $affiliateCode,
            $this->bucketIntoLists($variantIds)
        );
    }

    /**
     * Pick sample products and reduce each to a purchasable variant id.
     *
     * @return int[]
     */
    private function collectVariantIds(): array
    {
        try {
            $collection = $this->collectionFactory->create();
            $collection->addAttributeToSelect('name')
                ->addAttributeToFilter('status', 1)
                ->addAttributeToFilter(
                    'visibility',
                    ['in' => [Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_IN_SEARCH, Visibility::VISIBILITY_BOTH]]
                )
                ->setPageSize(self::SAMPLE_SIZE)
                ->setOrder('created_at', 'DESC');

            $variantIds = [];
            foreach ($collection as $product) {
                $variantId = $this->resolveVariantId($product);
                if ($variantId > 0) {
                    $variantIds[$variantId] = $variantId;
                }
            }

            return array_values($variantIds);
        } catch (\Exception $e) {
            $this->logger->warning(
                '[Montu Affiliate] Stub provider could not read catalog: ' . $e->getMessage()
            );
            return [];
        }
    }

    /**
     * Reduce a catalog product to a variant id: a configurable's first child,
     * otherwise the product's own id.
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return int
     */
    private function resolveVariantId($product): int
    {
        if ($product->getTypeId() === ConfigurableType::TYPE_CODE) {
            $childGroups = $this->configurableResource->getChildrenIds((int) $product->getId());
            foreach ($childGroups as $ids) {
                foreach ($ids as $childId) {
                    return (int) $childId;
                }
            }
        }

        return (int) $product->getId();
    }

    /**
     * Spread variant ids across the demo lists (chunked, in order).
     *
     * @param int[] $variantIds
     * @return RecommendationList[]
     */
    private function bucketIntoLists(array $variantIds): array
    {
        $listDefs = self::DEMO_LISTS;
        $listCount = count($listDefs);
        $chunkSize = (int) max(1, ceil(count($variantIds) / $listCount));
        $chunks = array_chunk($variantIds, $chunkSize);

        $lists = [];
        $i = 0;
        foreach ($listDefs as $id => $name) {
            $ids = $chunks[$i] ?? [];
            if ($ids !== []) {
                $lists[] = new RecommendationList($id, $name, $ids);
            }
            $i++;
        }

        return $lists;
    }
}
