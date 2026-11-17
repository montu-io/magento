<?php
/**
 * Montu Affiliate Tracking Extension - Catalog Change Detector
 *
 * Decides, in memory, whether a save changed something Montu mirrors. Stock,
 * cost and every other attribute are ignored, so a stock update never becomes
 * an event. Products and categories loaded by Magento carry their values as
 * loaded in origData until the save commits, which is what is compared.
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model\Catalog;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Montu\Affiliate\Model\Config;

class ChangeDetector
{
    /**
     * Product attributes Montu mirrors (catalog_product_save_commit_after and
     * the grid "Update attributes")
     */
    public const PRODUCT_ATTRIBUTES = [
        'name', 'description', 'short_description', 'url_key', 'status', 'visibility',
        'price', 'special_price', 'special_from_date', 'special_to_date',
        'image', 'small_image', 'thumbnail',
    ];

    /**
     * Static product fields Montu mirrors (also compared on a save)
     */
    private const PRODUCT_FIELDS = ['sku', 'type_id'];

    /**
     * Columns of an import row that carry those attributes (CSV names and the
     * importer's own normalized names). A column without a value leaves the
     * product untouched, so only columns with a value count.
     */
    private const IMPORT_COLUMNS = [
        'name', 'description', 'short_description', 'url_key', 'status', 'product_online', 'visibility',
        'price', 'special_price', 'special_from_date', 'special_price_from_date', 'special_to_date',
        'special_price_to_date', 'image', 'base_image', 'small_image', 'thumbnail', 'thumbnail_image',
        'additional_images', 'base_image_label', 'small_image_label', 'thumbnail_image_label',
        'additional_image_labels', 'categories', 'product_websites', '_product_websites',
        'configurable_variations', 'configurable_variation_labels',
    ];

    /**
     * Category attributes Montu mirrors
     */
    private const CATEGORY_ATTRIBUTES = [
        'name', 'description', 'image', 'url_key', 'is_active', 'include_in_menu', 'position', 'parent_id',
    ];

    /**
     * @var VariantLinks
     */
    private VariantLinks $variantLinks;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * Codes of the attributes products are configured by (e.g. color; a change
     * renames a variant) and of the brand attributes of every store view. Read
     * once per request, only when needed.
     *
     * @var string[]|null
     */
    private ?array $optionAttributeCodes = null;

    /**
     * @param VariantLinks $variantLinks
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(VariantLinks $variantLinks, Config $config, StoreManagerInterface $storeManager)
    {
        $this->variantLinks = $variantLinks;
        $this->config = $config;
        $this->storeManager = $storeManager;
    }

    /**
     * A saved product changed something Montu mirrors
     *
     * @param Product $product As passed to catalog_product_save_commit_after
     * @return bool
     */
    public function productChanged(Product $product): bool
    {
        // Saved in a store view, an attribute set to null (false for url_key) means
        // "use the default value": core does it to every untouched attribute
        // (ProductRepository::save, the admin "Use Default Value" box), so it is
        // not a change. (Removing a real store-view value this way is therefore
        // only seen with the product's next change.)
        $storeScoped = (int) $product->getStoreId() !== Store::DEFAULT_STORE_ID;
        foreach (array_merge(self::PRODUCT_ATTRIBUTES, $this->optionAttributeCodes()) as $code) {
            if (!$product->hasData($code)) {
                continue;
            }
            $after = $product->getData($code);
            if ($storeScoped && ($after === null || $after === false)) {
                continue;
            }
            if ($this->differs($product->getOrigData($code), $after)) {
                return true;
            }
        }
        foreach (self::PRODUCT_FIELDS as $field) {
            if ($product->hasData($field) && $product->getOrigData($field) !== null
                && $this->differs($product->getOrigData($field), $product->getData($field))
            ) {
                return true;
            }
        }

        return $product->getIsChangedCategories()
            || $this->listDiffers($product, 'category_ids')
            || $this->websitesChanged($product)
            || $this->galleryChanged($product)
            || $this->variantLinksChanged($product);
    }

    /**
     * Variants added to or removed from a configurable product. The links are in
     * the extension attributes: the loaded ones stay in origData unless the same
     * object was edited in place (the admin form does that, then the save counts
     * as a change, as no comparison is possible).
     *
     * @param Product $product
     * @return bool
     */
    private function variantLinksChanged(Product $product): bool
    {
        if ($product->getTypeId() !== Configurable::TYPE_CODE) {
            return false;
        }
        $after = $this->links($product->getExtensionAttributes());
        if ($after === null) {
            return false;
        }
        $before = $product->getOrigData('extension_attributes');
        if (!is_object($before) || $before === $product->getExtensionAttributes()) {
            return true;
        }

        return $this->links($before) !== $after;
    }

    /**
     * Sorted variant ids of a configurable product's extension attributes, or
     * null when they carry none
     *
     * @param mixed $extension
     * @return string|null
     */
    private function links($extension): ?string
    {
        if (!is_object($extension) || !method_exists($extension, 'getConfigurableProductLinks')) {
            return null;
        }
        $links = $extension->getConfigurableProductLinks();

        return $links === null ? null : $this->ids((array) $links);
    }

    /**
     * A saved category changed something Montu mirrors (its own fields; product
     * assignments are reported by Magento as affected product ids)
     *
     * @param Category $category
     * @return bool
     */
    public function categoryChanged(Category $category): bool
    {
        foreach (self::CATEGORY_ATTRIBUTES as $code) {
            if ($this->categoryValueChanged($category, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The category's URL key changed (its subcategories' URLs change too)
     *
     * @param Category $category
     * @return bool
     */
    public function categoryUrlChanged(Category $category): bool
    {
        return $this->categoryValueChanged($category, 'url_key');
    }

    /**
     * Same "Use Default Value" rule as products: in a store view, null / false
     * means the default value, not a change
     *
     * @param Category $category
     * @param string $code
     * @return bool
     */
    private function categoryValueChanged(Category $category, string $code): bool
    {
        if (!$category->hasData($code)) {
            return false;
        }
        $after = $category->getData($code);
        if ((int) $category->getStoreId() !== Store::DEFAULT_STORE_ID && ($after === null || $after === false)) {
            return false;
        }

        return $this->differs($category->getOrigData($code), $after);
    }

    /**
     * The grid "Update attributes" edited something Montu mirrors
     *
     * @param string[] $attributeCodes
     * @return bool
     */
    public function attributesTracked(array $attributeCodes): bool
    {
        if (array_intersect($attributeCodes, array_merge(self::PRODUCT_ATTRIBUTES, self::PRODUCT_FIELDS)) !== []) {
            return true;
        }

        return array_intersect($attributeCodes, $this->optionAttributeCodes()) !== [];
    }

    /**
     * An import row sets something Montu mirrors
     *
     * @param array<string, mixed> $row
     * @return bool
     */
    public function importRowTracked(array $row): bool
    {
        foreach (self::IMPORT_COLUMNS as $column) {
            if (isset($row[$column]) && $row[$column] !== '') {
                return true;
            }
        }
        // The standard CSV format puts every non-system attribute (manufacturer=REDKEN,
        // color=Rojo) into additional_attributes, still unparsed in the saved bunch.
        // A loose match is fine: content-hash dedupe drops an event that changed nothing.
        $additional = ',' . (string) ($row['additional_attributes'] ?? '');
        foreach ($this->optionAttributeCodes() as $code) {
            if ((isset($row[$code]) && $row[$code] !== '') || str_contains($additional, ',' . $code . '=')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Websites changed, through the product data (admin) or the extension
     * attributes (REST extension_attributes.website_ids)
     *
     * @param Product $product
     * @return bool
     */
    private function websitesChanged(Product $product): bool
    {
        if ($this->listDiffers($product, 'website_ids')) {
            return true;
        }
        $before = $product->getOrigData('website_ids');
        $extension = $product->getExtensionAttributes();
        $after = $extension !== null && method_exists($extension, 'getWebsiteIds') ? $extension->getWebsiteIds() : null;

        return is_array($before) && is_array($after) && $this->ids($before) !== $this->ids($after);
    }

    /**
     * Images added, removed, disabled, re-ordered or relabeled
     *
     * @param Product $product
     * @return bool
     */
    private function galleryChanged(Product $product): bool
    {
        if (!$product->hasData('media_gallery')) {
            return false;
        }

        return $this->gallerySignature($product->getOrigData('media_gallery'))
            !== $this->gallerySignature($product->getData('media_gallery'));
    }

    /**
     * @param mixed $gallery
     * @return string
     */
    private function gallerySignature($gallery): string
    {
        $images = [];
        foreach ((array) (is_array($gallery) ? ($gallery['images'] ?? []) : []) as $image) {
            if (!is_array($image) || !empty($image['removed']) || empty($image['file'])) {
                continue;
            }
            $images[] = implode('|', [
                (string) $image['file'],
                (int) ($image['disabled'] ?? 0),
                (int) ($image['position'] ?? 0),
                (string) ($image['label'] ?? ''),
            ]);
        }
        sort($images);

        return implode("\n", $images);
    }

    /**
     * A list of ids (categories, websites) changed, when both values are known
     *
     * @param Product $product
     * @param string $key
     * @return bool
     */
    private function listDiffers(Product $product, string $key): bool
    {
        $before = $product->getOrigData($key);
        if (!$product->hasData($key) || !is_array($before)) {
            return false;
        }

        return $this->ids($before) !== $this->ids((array) $product->getData($key));
    }

    /**
     * @param array<int|string, mixed> $values
     * @return string
     */
    private function ids(array $values): string
    {
        $ids = array_map('intval', array_values($values));
        sort($ids);

        return implode(',', array_unique($ids));
    }

    /**
     * Loose comparison: "10" equals "10.000000", "" equals null, a date equals
     * the same date with a 00:00:00 time
     *
     * @param mixed $before
     * @param mixed $after
     * @return bool
     */
    private function differs($before, $after): bool
    {
        return $this->normalize($before) !== $this->normalize($after);
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function normalize($value): string
    {
        if (is_array($value) || is_object($value)) {
            return (string) json_encode($value);
        }
        $value = trim((string) $value);
        if (is_numeric($value)) {
            return (string) (float) $value;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2}) 00:00:00$/', $value, $m)) {
            return $m[1];
        }

        return $value;
    }

    /**
     * Option attributes plus the brand attribute of every store view (each store
     * view may name its own)
     *
     * @return string[]
     */
    private function optionAttributeCodes(): array
    {
        if ($this->optionAttributeCodes === null) {
            $codes = $this->variantLinks->optionAttributeCodes();
            foreach ($this->storeManager->getStores() as $store) {
                $brandCode = $this->config->getBrandAttributeCode((int) $store->getId());
                if ($brandCode !== '') {
                    $codes[] = $brandCode;
                }
            }
            $this->optionAttributeCodes = array_values(array_unique($codes));
        }

        return $this->optionAttributeCodes;
    }
}
