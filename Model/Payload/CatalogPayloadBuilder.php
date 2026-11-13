<?php
/**
 * Montu Affiliate Tracking Extension - Catalog Payload Builder
 *
 * Builds the body of the catalog events (product.*, collection.*) from the
 * products or categories as they are now, loaded in the event's store view, a
 * batch at a time: one collection per batch plus a few set-based queries
 * (websites, variants, option labels, category products), never one load per
 * product. Like the order payload it only reports raw facts (status,
 * visibility, prices with their dates): Montu decides what is active, listed or
 * on sale. Stock and cost are never sent.
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model\Payload;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator;
use Magento\CatalogUrlRewrite\Model\ProductUrlPathGenerator;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Montu\Affiliate\Model\Catalog\ChangeDetector;
use Montu\Affiliate\Model\Catalog\VariantLinks;
use Montu\Affiliate\Service\WebhookService;

class CatalogPayloadBuilder
{
    /**
     * Reason returned for a category outside the store view's tree: Montu is told
     * it is gone from that store (collection.deleted)
     */
    public const OUT_OF_TREE = 'Not in this store view\'s category tree';

    /**
     * At most this many product ids in a collection body (the event must fit the
     * gateway's message size); product_ids_complete says whether all are there
     */
    private const MAX_COLLECTION_PRODUCT_IDS = 10000;

    private const VARIANT_ATTRIBUTES = [
        'name', 'status', 'price', 'special_price', 'special_from_date', 'special_to_date',
    ];

    private const CATEGORY_ATTRIBUTES = [
        'name', 'description', 'image', 'is_active', 'include_in_menu', 'url_key', 'url_path',
    ];

    private const IMAGE_ROLES = ['image', 'small_image', 'thumbnail'];

    /**
     * @var ProductCollectionFactory
     */
    private ProductCollectionFactory $productCollectionFactory;

    /**
     * @var CategoryCollectionFactory
     */
    private CategoryCollectionFactory $categoryCollectionFactory;

    /**
     * @var ProductUrlPathGenerator
     */
    private ProductUrlPathGenerator $productUrlPathGenerator;

    /**
     * @var CategoryUrlPathGenerator
     */
    private CategoryUrlPathGenerator $categoryUrlPathGenerator;

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resourceConnection;

    /**
     * @var EavConfig
     */
    private EavConfig $eavConfig;

    /**
     * @var VariantLinks
     */
    private VariantLinks $variantLinks;

    /**
     * Option labels per "attribute code:store id", read once per run
     *
     * @var array<string, array<string, string>|null>
     */
    private array $optionLabels = [];

    /**
     * @param ProductCollectionFactory $productCollectionFactory
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param ProductUrlPathGenerator $productUrlPathGenerator
     * @param CategoryUrlPathGenerator $categoryUrlPathGenerator
     * @param ResourceConnection $resourceConnection
     * @param EavConfig $eavConfig
     * @param VariantLinks $variantLinks
     */
    public function __construct(
        ProductCollectionFactory $productCollectionFactory,
        CategoryCollectionFactory $categoryCollectionFactory,
        ProductUrlPathGenerator $productUrlPathGenerator,
        CategoryUrlPathGenerator $categoryUrlPathGenerator,
        ResourceConnection $resourceConnection,
        EavConfig $eavConfig,
        VariantLinks $variantLinks
    ) {
        $this->productCollectionFactory = $productCollectionFactory;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->productUrlPathGenerator = $productUrlPathGenerator;
        $this->categoryUrlPathGenerator = $categoryUrlPathGenerator;
        $this->resourceConnection = $resourceConnection;
        $this->eavConfig = $eavConfig;
        $this->variantLinks = $variantLinks;
    }

    /**
     * Common envelope of every catalog event
     *
     * @param string $eventType
     * @param string $eventId
     * @param string $occurredAt
     * @param string $trigger
     * @param Store $store
     * @return array<string, mixed>
     */
    public function envelope(string $eventType, string $eventId, string $occurredAt, string $trigger, Store $store): array
    {
        return [
            'webhook_version' => WebhookService::WEBHOOK_VERSION,
            'event' => $eventType,
            'event_id' => $eventId,
            'occurred_at' => $occurredAt,
            'trigger' => $trigger,
            'store' => [
                'code' => $store->getCode(),
                'name' => $store->getName(),
                'base_url' => $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, true),
                'currency' => $store->getBaseCurrencyCode(),
                'id' => (int) $store->getId(),
                'website_code' => $store->getWebsite()->getCode(),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return string
     * @throws \JsonException
     */
    public function encode(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    /**
     * Current UTC time with microseconds, e.g. 2026-11-12T13:05:00.123456+00:00
     *
     * @return string
     */
    public function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');
    }

    /**
     * Product bodies for a batch, in the store view. Value per id: the body,
     * or the reason it is not sent (a string).
     *
     * @param Store $store
     * @param int[] $ids
     * @return array<int, array<string, mixed>|string>
     */
    public function products(Store $store, array $ids): array
    {
        $storeId = (int) $store->getId();
        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId($storeId)
            ->addIdFilter($ids)
            ->addAttributeToSelect(array_merge(ChangeDetector::PRODUCT_ATTRIBUTES, ['url_path']))
            ->addMediaGalleryData()
            ->addCategoryIds();

        $websites = $this->websiteIds($ids);
        $parents = $this->variantLinks->variantIds($ids);
        $configurableIds = [];
        foreach ($collection as $product) {
            if ($product->getTypeId() === Configurable::TYPE_CODE) {
                $configurableIds[] = (int) $product->getId();
            }
        }
        $variants = $this->variants($configurableIds, $storeId);

        $mediaUrl = $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA, true) . 'catalog/product';
        $result = array_fill_keys($ids, 'The product no longer exists');
        /** @var Product $product */
        foreach ($collection as $product) {
            $id = (int) $product->getId();
            if ((int) $product->getVisibility() === Visibility::VISIBILITY_NOT_VISIBLE && isset($parents[$id])) {
                $result[$id] = 'A variant: it is sent within its configurable product';
                continue;
            }
            $isConfigurable = $product->getTypeId() === Configurable::TYPE_CODE;
            $result[$id] = [
                'id' => $id,
                'sku' => (string) $product->getSku(),
                'type' => (string) $product->getTypeId(),
                'name' => (string) $product->getName(),
                'description' => (string) $product->getData('description'),
                'short_description' => (string) $product->getData('short_description'),
                'url' => $this->productUrl($product, $store),
                'image' => $this->mainImage($product, $mediaUrl),
                'images' => $this->gallery($product, $mediaUrl),
                'status' => (int) $product->getStatus(),
                'visibility' => (int) $product->getVisibility(),
                'in_website' => in_array((int) $store->getWebsiteId(), $websites[$id] ?? [], true),
                // A configurable product has no price of its own: its variants do.
                'price' => $isConfigurable ? null : $this->decimal($product->getData('price')),
                'special_price' => $isConfigurable ? null : $this->decimal($product->getData('special_price')),
                'special_from_date' => $product->getData('special_from_date') ?: null,
                'special_to_date' => $product->getData('special_to_date') ?: null,
                'category_ids' => $this->sortedIds((array) $product->getCategoryIds()),
                'created_at' => $product->getCreatedAt(),
                'updated_at' => $product->getUpdatedAt(),
                'configurable_options' => $variants[$id]['options'] ?? [],
                'variants' => $variants[$id]['variants'] ?? [],
            ];
        }

        return $result;
    }

    /**
     * Category (Montu collection) bodies for a batch, in the store view. Value
     * per id: the body, or the reason it is not sent.
     *
     * @param Store $store
     * @param int[] $ids
     * @return array<int, array<string, mixed>|string>
     */
    public function collections(Store $store, array $ids): array
    {
        $storeId = (int) $store->getId();
        $rootId = (int) $store->getRootCategoryId();
        $collection = $this->categoryCollectionFactory->create();
        $collection->setStoreId($storeId)
            ->addIdFilter($ids)
            ->addAttributeToSelect(self::CATEGORY_ATTRIBUTES);
        $products = $this->categoryProducts($ids);

        $result = array_fill_keys($ids, 'The category no longer exists');
        /** @var Category $category */
        foreach ($collection as $category) {
            $id = (int) $category->getId();
            $pathIds = array_map('intval', explode('/', (string) $category->getPath()));
            if (!in_array($rootId, $pathIds, true)) {
                $result[$id] = self::OUT_OF_TREE;
                continue;
            }
            $isRoot = $id === $rootId;
            $result[$id] = [
                'id' => $id,
                'parent_id' => (int) $category->getParentId(),
                'path' => (string) $category->getPath(),
                'level' => (int) $category->getLevel(),
                'position' => (int) $category->getPosition(),
                'is_root' => $isRoot,
                'name' => (string) $category->getName(),
                'description' => (string) $category->getData('description'),
                'image' => $this->categoryImage($category->getData('image'), $store),
                'url' => $isRoot ? null : $this->categoryUrl($category, $store),
                'is_active' => (bool) $category->getIsActive(),
                'include_in_menu' => (bool) $category->getIncludeInMenu(),
                'product_ids' => array_slice($products[$id] ?? [], 0, self::MAX_COLLECTION_PRODUCT_IDS),
                'product_ids_complete' => count($products[$id] ?? []) <= self::MAX_COLLECTION_PRODUCT_IDS,
                'created_at' => $category->getCreatedAt(),
                'updated_at' => $category->getUpdatedAt(),
            ];
        }

        return $result;
    }

    /**
     * Variants and options of configurable products, keyed by parent id
     *
     * @param int[] $parentIds
     * @param int $storeId
     * @return array<int, array{options: array<int, array{code: string, label: string}>, variants: array<int, array<string, mixed>>}>
     */
    private function variants(array $parentIds, int $storeId): array
    {
        if ($parentIds === []) {
            return [];
        }
        $childrenByParent = $this->variantLinks->childrenByParent($parentIds);
        $optionsByParent = $this->variantLinks->optionsByParent($parentIds, $storeId);

        $result = [];
        $codes = [];
        foreach ($optionsByParent as $parentId => $options) {
            $result[$parentId]['options'] = $options;
            foreach ($options as $option) {
                $codes[$option['code']] = true;
            }
        }

        $childIds = array_values(array_unique(array_merge([], ...array_values($childrenByParent))));
        $children = [];
        if ($childIds !== []) {
            $collection = $this->productCollectionFactory->create();
            $collection->setStoreId($storeId)
                ->addIdFilter($childIds)
                ->addAttributeToSelect(array_merge(self::VARIANT_ATTRIBUTES, array_keys($codes)));
            foreach ($collection as $child) {
                $children[(int) $child->getId()] = $child;
            }
        }

        foreach ($childrenByParent as $parentId => $variantIds) {
            foreach ($variantIds as $childId) {
                if (isset($children[$childId])) {
                    $result[$parentId]['variants'][] = $this->variant(
                        $children[$childId],
                        $result[$parentId]['options'] ?? [],
                        $storeId
                    );
                }
            }
        }

        return $result;
    }

    /**
     * @param Product $child
     * @param array<int, array{code: string, label: string}> $options The parent's options
     * @param int $storeId
     * @return array<string, mixed>
     */
    private function variant(Product $child, array $options, int $storeId): array
    {
        $values = [];
        foreach ($options as $option) {
            $values[$option['code']] = $this->optionText($option['code'], $child->getData($option['code']), $storeId);
        }

        return [
            'id' => (int) $child->getId(),
            'sku' => (string) $child->getSku(),
            'name' => (string) $child->getName(),
            'options' => $values,
            'status' => (int) $child->getStatus(),
            'price' => $this->decimal($child->getData('price')),
            'special_price' => $this->decimal($child->getData('special_price')),
            'special_from_date' => $child->getData('special_from_date') ?: null,
            'special_to_date' => $child->getData('special_to_date') ?: null,
            'created_at' => $child->getCreatedAt(),
            'updated_at' => $child->getUpdatedAt(),
        ];
    }

    /**
     * Website ids per product
     *
     * @param int[] $ids
     * @return array<int, int[]>
     */
    private function websiteIds(array $ids): array
    {
        $connection = $this->resourceConnection->getConnection();
        $result = [];
        foreach ($connection->fetchAll(
            $connection->select()
                ->from($this->resourceConnection->getTableName('catalog_product_website'), ['product_id', 'website_id'])
                ->where('product_id IN (?)', $ids)
        ) as $row) {
            $result[(int) $row['product_id']][] = (int) $row['website_id'];
        }

        return $result;
    }

    /**
     * Products assigned directly to each category, by position
     *
     * @param int[] $ids
     * @return array<int, int[]>
     */
    private function categoryProducts(array $ids): array
    {
        $connection = $this->resourceConnection->getConnection();
        $result = [];
        foreach ($connection->fetchAll(
            $connection->select()
                ->from($this->resourceConnection->getTableName('catalog_category_product'), ['category_id', 'product_id'])
                ->where('category_id IN (?)', $ids)
                ->order(['category_id', 'position', 'product_id'])
        ) as $row) {
            $result[(int) $row['category_id']][] = (int) $row['product_id'];
        }

        return $result;
    }

    /**
     * @param Product $product
     * @param string $mediaUrl
     * @return array{file: string, url: string}|null
     */
    private function mainImage(Product $product, string $mediaUrl): ?array
    {
        $file = $this->imageFile($product->getData('image'));

        return $file !== null ? ['file' => $file, 'url' => $mediaUrl . $file] : null;
    }

    /**
     * Enabled images, by position, with the roles each one has
     *
     * @param Product $product
     * @param string $mediaUrl
     * @return array<int, array<string, mixed>>
     */
    private function gallery(Product $product, string $mediaUrl): array
    {
        $gallery = $product->getData('media_gallery');
        $images = [];
        foreach ((array) (is_array($gallery) ? ($gallery['images'] ?? []) : []) as $image) {
            if (!is_array($image) || empty($image['file']) || !empty($image['disabled'])
                || ($image['media_type'] ?? 'image') !== 'image'
            ) {
                continue;
            }
            $file = (string) $image['file'];
            $roles = [];
            foreach (self::IMAGE_ROLES as $role) {
                if ($this->imageFile($product->getData($role)) === $file) {
                    $roles[] = $role;
                }
            }
            $images[] = [
                'id' => (int) ($image['value_id'] ?? 0),
                'file' => $file,
                'url' => $mediaUrl . $file,
                'position' => (int) ($image['position'] ?? 0),
                'label' => ($image['label'] ?? '') !== '' ? (string) $image['label'] : null,
                'types' => $roles,
            ];
        }
        usort($images, static fn (array $a, array $b): int => [$a['position'], $a['id']] <=> [$b['position'], $b['id']]);

        return $images;
    }

    /**
     * @param Product $product
     * @param Store $store
     * @return string|null
     */
    private function productUrl(Product $product, Store $store): ?string
    {
        if ((string) $product->getUrlKey() === '') {
            return null;
        }

        return $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, true)
            . $this->productUrlPathGenerator->getUrlPathWithSuffix($product, (int) $store->getId());
    }

    /**
     * @param Category $category
     * @param Store $store
     * @return string|null
     */
    private function categoryUrl(Category $category, Store $store): ?string
    {
        if ($this->categoryUrlPathGenerator->getUrlPath($category) === '') {
            return null;
        }

        return $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, true)
            . $this->categoryUrlPathGenerator->getUrlPathWithSuffix($category, (int) $store->getId());
    }

    /**
     * Category image as an absolute URL. Magento stores either a bare file name
     * (catalog/category/<file>) or a path from the web root (/media/...).
     *
     * @param mixed $value
     * @param Store $store
     * @return string|null
     */
    private function categoryImage($value, Store $store): ?string
    {
        $value = (string) $value;
        if ($value === '') {
            return null;
        }
        if (str_starts_with($value, '/')) {
            return rtrim($store->getBaseUrl(UrlInterface::URL_TYPE_WEB, true), '/') . $value;
        }

        return $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA, true) . 'catalog/category/' . $value;
    }

    /**
     * Label of an option value (e.g. "Rojo") in the store view, else the raw value
     *
     * @param string $code
     * @param mixed $value
     * @param int $storeId
     * @return string|null
     */
    private function optionText(string $code, $value, int $storeId): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $labels = $this->optionLabels($code, $storeId);
        if ($labels === null) {
            return (string) $value;
        }
        $texts = [];
        foreach (explode(',', (string) $value) as $id) {
            $texts[] = $labels[$id] ?? $id;
        }

        return implode(', ', $texts);
    }

    /**
     * Option id => label of an attribute in a store view, read once per attribute
     * and store view (null when the attribute has no options)
     *
     * @param string $code
     * @param int $storeId
     * @return array<string, string>|null
     */
    private function optionLabels(string $code, int $storeId): ?array
    {
        $key = $code . ':' . $storeId;
        if (!array_key_exists($key, $this->optionLabels)) {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);
            $labels = null;
            if ($attribute->usesSource()) {
                // The attribute is shared: read the store view's labels, then put its store back.
                $previousStoreId = $attribute->getStoreId();
                $options = $attribute->setStoreId($storeId)->getSource()->getAllOptions(false);
                $attribute->setStoreId($previousStoreId);
                $labels = [];
                foreach ($options as $option) {
                    $labels[(string) $option['value']] = (string) $option['label'];
                }
            }
            $this->optionLabels[$key] = $labels;
        }

        return $this->optionLabels[$key];
    }

    /**
     * Image path, or null for an empty one ("no_selection")
     *
     * @param mixed $file
     * @return string|null
     */
    private function imageFile($file): ?string
    {
        $file = (string) $file;

        return $file !== '' && $file !== 'no_selection' ? $file : null;
    }

    /**
     * Ids as sorted integers (Magento returns category ids in no fixed order, and
     * the content fingerprint must not change with it)
     *
     * @param array<int|string, mixed> $ids
     * @return int[]
     */
    private function sortedIds(array $ids): array
    {
        $ids = array_map('intval', array_values($ids));
        sort($ids);

        return $ids;
    }

    /**
     * @param mixed $value
     * @return float|null
     */
    private function decimal($value): ?float
    {
        return $value !== null && $value !== '' ? (float) $value : null;
    }
}
