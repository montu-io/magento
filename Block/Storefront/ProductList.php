<?php
/**
 * Montu Affiliate Tracking Extension - Storefront Product List Block
 *
 * Extends Magento's native ListProduct so the affiliate storefront reuses the
 * store's own product rendering: image, price, configurable SWATCHES (via the
 * details.renderers child declared in layout), availability and AJAX add-to-cart
 * all behave exactly like a category page.
 *
 * The product collection comes from CollectionBuilder (one eagerly-loaded
 * collection for the current page's variant ids - no N+1). The Montu-recommended
 * variant per configurable is exposed as a selection map for the swatch
 * preselect JS. List filters and pagination are delegated to ViewModel\Storefront.
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Block\Storefront;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Block\Product\Context;
use Magento\Catalog\Block\Product\ListProduct;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Framework\Data\Helper\PostHelper;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Url\Helper\Data as UrlHelper;
use Montu\Affiliate\Model\Storefront\CollectionBuilder;
use Montu\Affiliate\ViewModel\Storefront as StorefrontViewModel;

class ProductList extends ListProduct
{
    private CollectionBuilder $collectionBuilder;
    private StorefrontViewModel $storefrontViewModel;
    private Json $json;

    /**
     * @var array<int, array<int, int>> parentProductId => [attributeId => valueIndex]
     */
    private array $recommendedSelections = [];

    /**
     * @param Context $context
     * @param PostHelper $postDataHelper
     * @param LayerResolver $layerResolver
     * @param CategoryRepositoryInterface $categoryRepository
     * @param UrlHelper $urlHelper
     * @param CollectionBuilder $collectionBuilder
     * @param StorefrontViewModel $storefrontViewModel
     * @param Json $json
     * @param array $data
     */
    public function __construct(
        Context $context,
        PostHelper $postDataHelper,
        LayerResolver $layerResolver,
        CategoryRepositoryInterface $categoryRepository,
        UrlHelper $urlHelper,
        CollectionBuilder $collectionBuilder,
        StorefrontViewModel $storefrontViewModel,
        Json $json,
        array $data = []
    ) {
        $this->collectionBuilder = $collectionBuilder;
        $this->storefrontViewModel = $storefrontViewModel;
        $this->json = $json;
        parent::__construct(
            $context,
            $postDataHelper,
            $layerResolver,
            $categoryRepository,
            $urlHelper,
            $data
        );
    }

    /**
     * Provide the eagerly-loaded collection for the current page's variants.
     *
     * Overrides ListProduct so all native rendering (price, swatches, stock,
     * add-to-cart) reads from our affiliate-scoped collection instead of the
     * catalog layer.
     *
     * @return \Magento\Catalog\Model\ResourceModel\Product\Collection
     */
    protected function _getProductCollection()
    {
        if ($this->_productCollection === null) {
            $result = $this->collectionBuilder->build(
                $this->storefrontViewModel->getPageVariantIds()
            );
            $this->_productCollection = $result['collection'];
            $this->recommendedSelections = $result['selections'];
        }
        return $this->_productCollection;
    }

    /**
     * The ViewModel powering filters, pagination and page metadata.
     *
     * @return StorefrontViewModel
     */
    public function getViewModel(): StorefrontViewModel
    {
        return $this->storefrontViewModel;
    }

    /**
     * Montu-recommended default swatch selection for a product (or [] for none).
     * Ensures the collection is built first.
     *
     * @param int $productId
     * @return array<int, int> attributeId => valueIndex
     */
    public function getRecommendedSelection(int $productId): array
    {
        $this->getLoadedProductCollection();
        return $this->recommendedSelections[$productId] ?? [];
    }

    /**
     * JSON of all recommended selections (productId => {attributeId: valueIndex})
     * for the swatch preselect script.
     *
     * @return string
     */
    public function getRecommendedSelectionsJson(): string
    {
        $this->getLoadedProductCollection();
        return $this->json->serialize($this->recommendedSelections);
    }

    /**
     * URL of the swatch preselect script.
     *
     * @return string
     */
    public function getPreselectScriptUrl(): string
    {
        return $this->getViewFileUrl('Montu_Affiliate::js/storefront-preselect.js');
    }
}
