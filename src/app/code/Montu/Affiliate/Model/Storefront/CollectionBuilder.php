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
use Magento\CatalogInventory\Helper\Stock as StockHelper;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class CollectionBuilder
{
    private CollectionFactory $collectionFactory;
    private CatalogConfig $catalogConfig;
    private StockHelper $stockHelper;
    private ResourceConnection $resource;
    private StoreManagerInterface $storeManager;
    private LoggerInterface $logger;

    /**
     * @param CollectionFactory $collectionFactory
     * @param CatalogConfig $catalogConfig
     * @param StockHelper $stockHelper
     * @param ResourceConnection $resource
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        CollectionFactory $collectionFactory,
        CatalogConfig $catalogConfig,
        StockHelper $stockHelper,
        ResourceConnection $resource,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->catalogConfig = $catalogConfig;
        $this->stockHelper = $stockHelper;
        $this->resource = $resource;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
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

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                $this->resource->getTableName('catalog_product_super_link'),
                ['product_id', 'parent_id']
            )
            ->where('product_id IN (?)', $variantIds);

        $map = [];
        foreach ($connection->fetchAll($select) as $row) {
            $map[(int) $row['product_id']] = (int) $row['parent_id'];
        }
        return $map;
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

        $collection->setStoreId((int) $this->storeManager->getStore()->getId())
            ->addAttributeToSelect($this->catalogConfig->getProductAttributes())
            ->addAttributeToFilter('status', ProductStatus::STATUS_ENABLED)
            ->addIdFilter($productIds)
            ->addMinimalPrice()
            ->addFinalPrice()
            ->addTaxPercents()
            ->addUrlRewrite();

        // Batched stock status (so isSalable() / availability needs no per-row call).
        $this->stockHelper->addStockStatusToCollection($collection);

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
            $connection = $this->resource->getConnection();
            $select = $connection->select()
                ->from(
                    ['sa' => $this->resource->getTableName('catalog_product_super_attribute')],
                    ['attribute_id']
                )
                ->join(
                    ['ea' => $this->resource->getTableName('eav_attribute')],
                    'ea.attribute_id = sa.attribute_id',
                    ['attribute_code']
                )
                ->where('sa.product_id IN (?)', $parentIds);

            $codeByAttributeId = [];
            foreach ($connection->fetchAll($select) as $row) {
                $codeByAttributeId[(int) $row['attribute_id']] = (string) $row['attribute_code'];
            }

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
        } catch (\Exception $e) {
            $this->logger->warning(
                '[Montu Affiliate] Could not resolve recommended swatch selections: ' . $e->getMessage()
            );
            return [];
        }
    }
}
