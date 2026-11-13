<?php
/**
 * Montu Affiliate Tracking Extension - Storefront Product Collection Builder
 *
 * Turns the current page's Montu variant ids into a SINGLE, eagerly-loaded
 * product collection - the Magento equivalent of select_related/prefetch_related:
 *
 *   - one query maps every recommended child variant to its configurable parent
 *     (catalog_product_super_link), instead of getParentIdsByChild() per id;
 *   - one product collection loads all card products with addIdFilter +
 *     addAttributeToSelect + addMinimalPrice/addFinalPrice/addTaxPercents +
 *     addUrlRewrite, and batched stock status;
 *   - the Montu-recommended default selection per configurable is resolved in
 *     one extra batched query + one small collection (for the swatch preselect).
 *
 * No per-product repository loads, URL-rewrite lookups or stock calls in a loop.
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model\Storefront;

use Magento\Catalog\Model\Config as CatalogConfig;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Model\ResourceModel\Stock\Status as StockStatusResource;
use Magento\Framework\DB\Sql\Expression;
use Magento\Store\Model\StoreManagerInterface;
use Montu\Affiliate\Model\Catalog\VariantLinks;
use Montu\Affiliate\Model\Config;
use Psr\Log\LoggerInterface;

class CollectionBuilder
{
    private CollectionFactory $collectionFactory;
    private CatalogConfig $catalogConfig;
    private StockStatusResource $stockStatusResource;
    private StoreManagerInterface $storeManager;
    private Config $config;
    private LoggerInterface $logger;
    private VariantLinks $variantLinks;

    /**
     * @param CollectionFactory $collectionFactory
     * @param CatalogConfig $catalogConfig
     * @param StockStatusResource $stockStatusResource
     * @param StoreManagerInterface $storeManager
     * @param Config $config
     * @param LoggerInterface $logger
     * @param VariantLinks $variantLinks
     */
    public function __construct(
        CollectionFactory $collectionFactory,
        CatalogConfig $catalogConfig,
        StockStatusResource $stockStatusResource,
        StoreManagerInterface $storeManager,
        Config $config,
        LoggerInterface $logger,
        VariantLinks $variantLinks
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->catalogConfig = $catalogConfig;
        $this->stockStatusResource = $stockStatusResource;
        $this->storeManager = $storeManager;
        $this->config = $config;
        $this->logger = $logger;
        $this->variantLinks = $variantLinks;
    }

    /**
     * Build the page collection plus the recommended-variant selection map.
     *
     * @param int[] $variantIds Ordered variant ids for the current page
     * @return array{
     *     collection: Collection,
     *     selections: array<int, array<int, int>>
     * } selections: parentProductId => [attributeId => valueIndex]
     */
    public function build(array $variantIds): array
    {
        $variantIds = array_values(array_unique(array_map('intval', $variantIds)));

        $childToParent = $this->mapChildrenToParents($variantIds);

        // Card product = configurable parent if the variant is a child, else the
        // variant itself (standalone simple, or a configurable id sent directly).
        $order = [];
        $seen = [];
        $recommendedChildByParent = [];
        foreach ($variantIds as $variantId) {
            $cardId = $childToParent[$variantId] ?? $variantId;
            if (isset($seen[$cardId])) {
                continue;
            }
            $seen[$cardId] = true;
            $order[] = $cardId;
            if (isset($childToParent[$variantId])) {
                $recommendedChildByParent[$cardId] = $variantId;
            }
        }

        $collection = $this->buildCollection($order);
        $selections = $this->resolveSelections($recommendedChildByParent);

        return [
            'collection' => $collection,
            'selections' => $selections,
        ];
    }

    /**
     * One query: child variant id => parent configurable id.
     *
     * @param int[] $variantIds
     * @return array<int, int>
     */
    private function mapChildrenToParents(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        return $this->variantLinks->parentByChild($variantIds);
    }

    /**
     * Build the eagerly-loaded product collection for the card product ids,
     * preserving the affiliate's order.
     *
     * @param int[] $productIds
     * @return Collection
     */
    private function buildCollection(array $productIds): Collection
    {
        $collection = $this->collectionFactory->create();

        if ($productIds === []) {
            // Force an empty result without a full table scan.
            $collection->addIdFilter([0]);
            return $collection;
        }

        $storeId = (int) $this->storeManager->getStore()->getId();
        $collection->setStoreId($storeId)
            ->addAttributeToSelect($this->catalogConfig->getProductAttributes())
            ->addAttributeToFilter('status', ProductStatus::STATUS_ENABLED)
            ->addIdFilter($productIds)
            ->addMinimalPrice()
            ->addFinalPrice()
            ->addTaxPercents()
            ->addUrlRewrite();

        // Batched stock status (so isSalable() / availability needs no per-row call).
        // The second argument filters out-of-stock products when the "Hide Out-of-Stock
        // Products" setting is on; otherwise the collection is only enriched with stock data.
        // Flag the collection so the catalog's own stock-status plugin does not add a
        // second join under the same `stock_status_index` alias (which would error).
        $collection->setFlag('has_stock_status_filter', true);
        $this->stockStatusResource->addStockDataToCollection(
            $collection,
            $this->config->shouldHideOutOfStock($storeId)
        );

        // Preserve the Montu/affiliate ordering.
        $idList = implode(',', array_map('intval', $productIds));
        $collection->getSelect()->order(new Expression("FIELD(e.entity_id, {$idList})"));

        return $collection;
    }

    /**
     * Resolve the recommended default selection (attributeId => valueIndex) for
     * each configurable card, using two batched queries (no N+1).
     *
     * @param array<int, int> $recommendedChildByParent parentId => childId
     * @return array<int, array<int, int>> parentId => [attributeId => valueIndex]
     */
    private function resolveSelections(array $recommendedChildByParent): array
    {
        if ($recommendedChildByParent === []) {
            return [];
        }

        try {
            $parentIds = array_keys($recommendedChildByParent);

            // 1) Configurable attribute ids + codes for these parents (one query).
            $codeByAttributeId = $this->variantLinks->optionAttributesOf($parentIds);

            if ($codeByAttributeId === []) {
                return [];
            }

            // 2) Child attribute values in one collection.
            $childIds = array_values($recommendedChildByParent);
            $children = $this->collectionFactory->create();
            $children->setStoreId((int) $this->storeManager->getStore()->getId())
                ->addAttributeToSelect(array_values(array_unique($codeByAttributeId)))
                ->addIdFilter($childIds);

            $valuesByChild = [];
            foreach ($children as $child) {
                $childId = (int) $child->getId();
                $values = [];
                foreach ($codeByAttributeId as $attributeId => $code) {
                    $value = $child->getData($code);
                    if ($value !== null && $value !== '') {
                        $values[$attributeId] = (int) $value;
                    }
                }
                $valuesByChild[$childId] = $values;
            }

            // Map back to parentId => selection.
            $selections = [];
            foreach ($recommendedChildByParent as $parentId => $childId) {
                if (isset($valuesByChild[$childId])) {
                    $selections[$parentId] = $valuesByChild[$childId];
                }
            }

            return $selections;
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[Montu Affiliate] Could not resolve recommended swatch selections: ' . $e->getMessage()
            );
            return [];
        }
    }
}
