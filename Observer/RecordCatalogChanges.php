<?php
/**
 * Montu Affiliate Tracking Extension - Record Catalog Changes Observer
 *
 * Registered in etc/events.xml on the Magento events fired AFTER a product or
 * category changed. Each is reduced to "these products / categories changed"
 * and handed to Model\Catalog\ChangeRecorder; nothing is built or sent here,
 * and nothing is read beyond what the event carries (plus one query for the
 * configurable parents of changed products). What each event means (verified
 * on Magento 2.4.8):
 *
 * - catalog_product_save_commit_after: every product model save (admin, REST,
 *   async bulk API), after commit. Stock-only saves fire it too, so the saved
 *   values are compared with the loaded ones (Model\Catalog\ChangeDetector).
 * - catalog_product_delete_commit_after: a product was deleted.
 * - catalog_product_attribute_update_before: grid "Update attributes" and
 *   enable / disable. Magento fires no event after this update, so this one
 *   only registers a callback that records the products once the update
 *   committed (dropped if it rolls back). Inventory edits never fire it.
 * - catalog_product_to_website_change: website assignment, after commit.
 * - catalog_product_import_bunch_save_after / _bunch_delete_after /
 *   _finish_before: CSV import (no model saves), each after its writes. Rows
 *   with a mirrored column set are recorded per bunch; configurable products
 *   again once the import finished, when their variant links are saved.
 * - catalog_category_save_commit_after, category_move,
 *   catalog_category_delete_commit_after: categories, with the products added
 *   to or removed from them.
 *
 * A deleted variant or category is sent as product.deleted / collection.deleted
 * only; Montu removes it from the products that referenced it. Stock (stock
 * items, MSI source items, reservations) fires none of these events, or is
 * filtered out by the comparison. Nothing is recorded unless a store view sends
 * a catalog event. The observer never throws: an exception from a
 * *_commit_after observer would make Magento report a committed save as failed.
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Observer;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\CatalogImportExport\Model\Import\Product as ImportProduct;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Montu\Affiliate\Model\Catalog\ChangeDetector;
use Montu\Affiliate\Model\Catalog\ChangeRecorder;
use Montu\Affiliate\Model\Catalog\VariantLinks;
use Montu\Affiliate\Model\ResourceModel\WebhookLog as WebhookLogResource;
use Psr\Log\LoggerInterface;

class RecordCatalogChanges implements ObserverInterface
{
    /**
     * @var ChangeRecorder
     */
    private ChangeRecorder $recorder;

    /**
     * @var ChangeDetector
     */
    private ChangeDetector $detector;

    /**
     * @var VariantLinks
     */
    private VariantLinks $variantLinks;

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resourceConnection;

    /**
     * @var WebhookLogResource
     */
    private WebhookLogResource $webhookLogResource;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Configurable products of the running import (sent again once it finished)
     *
     * @var array<int, true>
     */
    private array $importConfigurableIds = [];

    /**
     * @param ChangeRecorder $recorder
     * @param ChangeDetector $detector
     * @param VariantLinks $variantLinks
     * @param ResourceConnection $resourceConnection
     * @param WebhookLogResource $webhookLogResource
     * @param LoggerInterface $logger
     */
    public function __construct(
        ChangeRecorder $recorder,
        ChangeDetector $detector,
        VariantLinks $variantLinks,
        ResourceConnection $resourceConnection,
        WebhookLogResource $webhookLogResource,
        LoggerInterface $logger
    ) {
        $this->recorder = $recorder;
        $this->detector = $detector;
        $this->variantLinks = $variantLinks;
        $this->resourceConnection = $resourceConnection;
        $this->webhookLogResource = $webhookLogResource;
        $this->logger = $logger;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $name = (string) $event->getName();

        try {
            if (!$this->recorder->isEnabled()) {
                return;
            }
            $this->handle($event, $name);
        } catch (\Throwable $e) {
            $this->logger->error(
                '[Montu Affiliate] Error recording a catalog change from ' . $name . ' (suppressed): '
                . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }

    /**
     * @param Event $event
     * @param string $name
     * @return void
     */
    private function handle(Event $event, string $name): void
    {
        switch ($name) {
            case 'catalog_product_save_commit_after':
                $this->productSaved($event->getDataObject(), $name);
                return;

            case 'catalog_product_delete_commit_after':
                $product = $event->getDataObject();
                if ($product instanceof Product && $product->getId()) {
                    $this->recorder->productsDeleted([(int) $product->getId() => (string) $product->getSku()], $name);
                }
                return;

            case 'catalog_product_attribute_update_before':
                $ids = array_map('intval', (array) $event->getProductIds());
                if ($ids !== [] && $this->detector->attributesTracked(array_keys((array) $event->getAttributesData()))) {
                    // Fired before the write: record once the update committed (dropped if it
                    // rolls back). Should the update throw before opening its transaction,
                    // the callback runs on the next commit and records an update that did
                    // not happen: harmless, the event is built from the current data.
                    $this->webhookLogResource->addCommitCallback(function () use ($ids, $name): void {
                        $this->safely($name, fn () => $this->recordProducts([], $ids, $name));
                    });
                }
                return;

            case 'catalog_product_to_website_change':
                $this->recordProducts([], array_map('intval', (array) $event->getProducts()), $name);
                return;

            case 'catalog_product_import_bunch_save_after':
                $this->importBunchSaved($event, $name);
                return;

            case 'catalog_product_import_bunch_delete_after':
                $this->recorder->productsDeleted($this->deletedSkus($event), $name);
                return;

            case 'catalog_product_import_finish_before':
                // Variant links are saved after every bunch: send configurable products again.
                $ids = array_keys($this->importConfigurableIds);
                $this->importConfigurableIds = [];
                $this->recorder->productsChanged([], $ids, $name);
                return;

            case 'catalog_category_save_commit_after':
                $this->categorySaved($event->getDataObject(), $name);
                return;

            case 'category_move':
                $category = $event->getCategory();
                if ($category instanceof Category && $category->getId()) {
                    // The subtree's paths and URLs change; Magento also shifts the
                    // positions of the categories next to it, in both parents.
                    $this->recorder->categoriesChanged([], array_merge(
                        $this->withChildren($category),
                        $this->childCategoryIds([(int) $event->getPrevParentId(), (int) $event->getParentId()])
                    ), $name);
                }
                return;

            case 'catalog_category_delete_commit_after':
                $category = $event->getCategory();
                if ($category instanceof Category && $category->getId()) {
                    $this->recorder->categoriesDeleted([(int) $category->getId()], $name);
                }
                return;
        }
    }

    /**
     * @param mixed $product
     * @param string $name
     * @return void
     */
    private function productSaved($product, string $name): void
    {
        if (!$product instanceof Product || !$product->getId()) {
            return;
        }
        $id = (int) $product->getId();

        // A product that was never loaded has no original id: it was just created.
        if ($product->getOrigData('entity_id') === null) {
            $this->recordProducts([$id], [], $name);
        } elseif ($this->detector->productChanged($product)) {
            $this->recordProducts([], [$id], $name);
        }
    }

    /**
     * @param mixed $category
     * @param string $name
     * @return void
     */
    private function categorySaved($category, string $name): void
    {
        if (!$category instanceof Category || !$category->getId()) {
            return;
        }
        $id = (int) $category->getId();

        // Products added to or removed from the category (not the ones only
        // re-sorted): their category_ids changed.
        $this->recorder->productsChanged([], array_map('intval', (array) $category->getChangedProductIds()), $name);

        if ($category->getOrigData('entity_id') === null) {
            $this->recorder->categoriesChanged([$id], [], $name);
        } elseif ($this->detector->categoryUrlChanged($category)) {
            $this->recorder->categoriesChanged([], $this->withChildren($category), $name);
        } elseif ($category->getIsChangedProductList() || $this->detector->categoryChanged($category)) {
            $this->recorder->categoriesChanged([], [$id], $name);
        }
    }

    /**
     * Products of an import bunch with a mirrored column set. Rows of a
     * stock-only CSV have none and are skipped.
     *
     * @param Event $event
     * @param string $name
     * @return void
     */
    private function importBunchSaved(Event $event, string $name): void
    {
        $adapter = $event->getAdapter();
        if (!is_object($adapter) || !method_exists($adapter, 'getNewSku')) {
            return;
        }

        $ids = [];
        foreach ((array) $event->getBunch() as $row) {
            if (!is_array($row) || !$this->detector->importRowTracked($row)) {
                continue;
            }
            $known = $adapter->getNewSku((string) ($row['sku'] ?? ''));
            if (!is_array($known) || empty($known['entity_id'])) {
                continue;
            }
            $id = (int) $known['entity_id'];
            $ids[] = $id;
            if (($known['type_id'] ?? '') === Configurable::TYPE_CODE) {
                $this->importConfigurableIds[$id] = true;
            }
        }
        if ($ids === []) {
            return;
        }

        // The importer inserts new products without touching updated_at: a
        // product whose updated_at still equals created_at was created now.
        $connection = $this->resourceConnection->getConnection();
        $created = array_map('intval', $connection->fetchCol(
            $connection->select()
                ->from($this->resourceConnection->getTableName('catalog_product_entity'), ['entity_id'])
                ->where('entity_id IN (?)', $ids)
                ->where('created_at = updated_at')
        ));

        $this->recordProducts($created, array_diff($ids, $created), $name);
    }

    /**
     * Record products, and the configurable products they are variants of
     * (a variant is sent within its parent)
     *
     * @param int[] $createdIds
     * @param int[] $updatedIds
     * @param string $name
     * @return void
     */
    private function recordProducts(array $createdIds, array $updatedIds, string $name): void
    {
        $parents = $this->parentIds(array_merge($createdIds, $updatedIds));
        $this->recorder->productsChanged($createdIds, array_merge($updatedIds, $parents), $name);
    }

    /**
     * Configurable products the given products are variants of (one query)
     *
     * @param int[] $childIds
     * @return int[]
     */
    private function parentIds(array $childIds): array
    {
        return $childIds === [] ? [] : $this->variantLinks->parentIds($childIds);
    }

    /**
     * Direct subcategories of the given categories (one query)
     *
     * @param int[] $parentIds
     * @return int[]
     */
    private function childCategoryIds(array $parentIds): array
    {
        $parentIds = array_values(array_filter($parentIds));
        if ($parentIds === []) {
            return [];
        }
        $connection = $this->resourceConnection->getConnection();

        return array_map('intval', $connection->fetchCol(
            $connection->select()
                ->from($this->resourceConnection->getTableName('catalog_category_entity'), ['entity_id'])
                ->where('parent_id IN (?)', $parentIds)
        ));
    }

    /**
     * Deleted product id => SKU for an import delete. The importer deletes the
     * valid default-scope rows of the bunch, in order; should the counts ever
     * differ, the SKUs are left unknown (null).
     *
     * @param Event $event
     * @return array<int, string|null>
     */
    private function deletedSkus(Event $event): array
    {
        $ids = array_values(array_map('intval', (array) $event->getIdsToDelete()));
        $adapter = $event->getAdapter();
        $skus = [];
        if (is_object($adapter) && method_exists($adapter, 'validateRow') && method_exists($adapter, 'getRowScope')) {
            // The importer's own filter for the ids it deleted (validateRow is cached
            // per row, so this repeats no work).
            foreach ((array) $event->getBunch() as $rowNum => $row) {
                if (is_array($row) && $adapter->validateRow($row, $rowNum)
                    && $adapter->getRowScope($row) === ImportProduct::SCOPE_DEFAULT
                ) {
                    $skus[] = (string) ($row['sku'] ?? '');
                }
            }
        }
        $map = array_fill_keys($ids, null);
        if (count($skus) === count($ids)) {
            foreach ($ids as $i => $id) {
                $map[$id] = $skus[$i] !== '' ? $skus[$i] : null;
            }
        }

        return $map;
    }

    /**
     * The category and its subcategories (their paths and URLs change with it)
     *
     * @param Category $category
     * @return int[]
     */
    private function withChildren(Category $category): array
    {
        return array_map('intval', (array) $category->getAllChildren(true));
    }

    /**
     * Run deferred work with the same "never throw" rule as the observer
     *
     * @param string $name
     * @param callable $work
     * @return void
     */
    private function safely(string $name, callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            $this->logger->error(
                '[Montu Affiliate] Error recording a catalog change from ' . $name . ' (suppressed): '
                . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
