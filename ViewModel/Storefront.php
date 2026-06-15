<?php
/**
 * Montu Affiliate Tracking Extension - Storefront ViewModel
 *
 * Drives the non-product chrome of the affiliate storefront: affiliate code,
 * list filters (from the Montu response), and pagination. Product rendering is
 * handled natively by Block\Storefront\ProductList; this ViewModel only decides
 * which variant ids belong on the current page (getPageVariantIds), which the
 * block then loads as one collection.
 *
 * Filtering and counts work off the Montu response alone (no product loads).
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\ViewModel;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use Montu\Affiliate\Model\Config;
use Montu\Affiliate\Model\Data\AffiliateRecommendations;
use Montu\Affiliate\Service\RecommendationClient;

class Storefront implements ArgumentInterface
{
    /**
     * Allowed affiliate code format (mirrors the cookie capture validation).
     */
    private const CODE_PATTERN = '/^[a-zA-Z0-9_\-]{1,100}$/';

    /**
     * Pseudo-list id for the "all lists" filter.
     */
    private const ALL_LISTS = '';

    /**
     * How many page links to show around the current page in the pager.
     */
    private const PAGER_WINDOW = 2;

    private RequestInterface $request;
    private Config $config;
    private RecommendationClient $recommendationClient;
    private StoreManagerInterface $storeManager;

    private ?AffiliateRecommendations $recommendations = null;

    /**
     * @var int[]|null Selected (filtered) variant ids for the active list
     */
    private ?array $selectedVariantIds = null;

    /**
     * @param RequestInterface $request
     * @param Config $config
     * @param RecommendationClient $recommendationClient
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        RequestInterface $request,
        Config $config,
        RecommendationClient $recommendationClient,
        StoreManagerInterface $storeManager
    ) {
        $this->request = $request;
        $this->config = $config;
        $this->recommendationClient = $recommendationClient;
        $this->storeManager = $storeManager;
    }

    /**
     * Affiliate code for the current request (validated; "" if absent/invalid).
     *
     * @return string
     */
    public function getAffiliateCode(): string
    {
        $code = (string) $this->request->getParam('code', '');
        return preg_match(self::CODE_PATTERN, $code) ? $code : '';
    }

    /**
     * Configured page heading.
     *
     * @return string
     */
    public function getTitle(): string
    {
        return $this->config->getStorefrontTitle();
    }

    /**
     * Base store URL, for the "keep shopping" call to action.
     *
     * @return string
     */
    public function getStoreUrl(): string
    {
        try {
            return $this->storeManager->getStore()->getBaseUrl();
        } catch (\Exception $e) {
            return '/';
        }
    }

    /**
     * The affiliate's recommendations (cached for this request).
     *
     * @return AffiliateRecommendations
     */
    private function getRecommendations(): AffiliateRecommendations
    {
        if ($this->recommendations === null) {
            $this->recommendations = $this->recommendationClient->getRecommendations(
                $this->getAffiliateCode()
            );
        }
        return $this->recommendations;
    }

    /**
     * Currently active list id ("" = all lists).
     *
     * @return string
     */
    public function getActiveListId(): string
    {
        return (string) $this->request->getParam('list', self::ALL_LISTS);
    }

    /**
     * Active list display name (for the results heading).
     *
     * @return string
     */
    public function getActiveListName(): string
    {
        $activeId = $this->getActiveListId();
        if ($activeId === self::ALL_LISTS) {
            return (string) __('All recommendations');
        }
        $list = $this->getRecommendations()->getListById($activeId);
        return $list !== null ? $list->getName() : (string) __('All recommendations');
    }

    /**
     * List filters for the sidebar: each ['id','name','count','active','url'].
     * Includes the synthetic "All" entry first.
     *
     * @return array<int, array{id: string, name: string, count: int, active: bool, url: string}>
     */
    public function getListFilters(): array
    {
        $activeId = $this->getActiveListId();

        $filters = [];
        $filters[] = [
            'id' => self::ALL_LISTS,
            'name' => (string) __('All'),
            'count' => count($this->getRecommendations()->getAllVariantIds()),
            'active' => $activeId === self::ALL_LISTS,
            'url' => $this->buildUrl(self::ALL_LISTS, 1),
        ];

        foreach ($this->getRecommendations()->getLists() as $list) {
            $filters[] = [
                'id' => $list->getId(),
                'name' => $list->getName(),
                'count' => $list->getCount(),
                'active' => $activeId === $list->getId(),
                'url' => $this->buildUrl($list->getId(), 1),
            ];
        }

        return $filters;
    }

    /**
     * Variant ids selected by the active list filter (de-duped for "all").
     *
     * @return int[]
     */
    private function getSelectedVariantIds(): array
    {
        if ($this->selectedVariantIds !== null) {
            return $this->selectedVariantIds;
        }

        $activeId = $this->getActiveListId();
        if ($activeId === self::ALL_LISTS) {
            $this->selectedVariantIds = $this->getRecommendations()->getAllVariantIds();
        } else {
            $list = $this->getRecommendations()->getListById($activeId);
            $this->selectedVariantIds = $list !== null ? $list->getVariantIds() : [];
        }

        return $this->selectedVariantIds;
    }

    /**
     * Variant ids for the current page only (the block loads these).
     *
     * @return int[]
     */
    public function getPageVariantIds(): array
    {
        return array_slice($this->getSelectedVariantIds(), $this->getOffset(), $this->getPageSize());
    }

    /**
     * @return bool
     */
    public function hasProducts(): bool
    {
        return $this->getTotalCount() > 0;
    }

    // ----- Pagination ---------------------------------------------------------

    /**
     * @return int
     */
    public function getPageSize(): int
    {
        return max(1, $this->config->getStorefrontProductsLimit());
    }

    /**
     * @return int
     */
    public function getCurrentPage(): int
    {
        $page = max(1, (int) $this->request->getParam('p', 1));
        return min($page, $this->getTotalPages());
    }

    /**
     * @return int
     */
    private function getOffset(): int
    {
        return ($this->getCurrentPage() - 1) * $this->getPageSize();
    }

    /**
     * @return int
     */
    public function getTotalCount(): int
    {
        return count($this->getSelectedVariantIds());
    }

    /**
     * @return int
     */
    public function getTotalPages(): int
    {
        return (int) max(1, (int) ceil($this->getTotalCount() / $this->getPageSize()));
    }

    /**
     * First result index shown (1-based) for the "showing X-Y of Z" line.
     *
     * @return int
     */
    public function getShowingFrom(): int
    {
        return $this->getTotalCount() === 0 ? 0 : $this->getOffset() + 1;
    }

    /**
     * Last result index shown for the "showing X-Y of Z" line.
     *
     * @return int
     */
    public function getShowingTo(): int
    {
        return min($this->getOffset() + $this->getPageSize(), $this->getTotalCount());
    }

    /**
     * @return bool
     */
    public function hasPrev(): bool
    {
        return $this->getCurrentPage() > 1;
    }

    /**
     * @return bool
     */
    public function hasNext(): bool
    {
        return $this->getCurrentPage() < $this->getTotalPages();
    }

    /**
     * @return string
     */
    public function getPrevUrl(): string
    {
        return $this->buildUrl($this->getActiveListId(), max(1, $this->getCurrentPage() - 1));
    }

    /**
     * @return string
     */
    public function getNextUrl(): string
    {
        return $this->buildUrl($this->getActiveListId(), min($this->getTotalPages(), $this->getCurrentPage() + 1));
    }

    /**
     * Window of page links around the current page.
     *
     * @return array<int, array{num: int, url: string, current: bool}>
     */
    public function getPageWindow(): array
    {
        $current = $this->getCurrentPage();
        $total = $this->getTotalPages();
        $start = max(1, $current - self::PAGER_WINDOW);
        $end = min($total, $current + self::PAGER_WINDOW);

        $pages = [];
        for ($i = $start; $i <= $end; $i++) {
            $pages[] = [
                'num' => $i,
                'url' => $this->buildUrl($this->getActiveListId(), $i),
                'current' => $i === $current,
            ];
        }
        return $pages;
    }

    /**
     * Build a canonical storefront URL for a given list filter and page.
     *
     * @param string $listId
     * @param int $page
     * @return string
     */
    private function buildUrl(string $listId, int $page): string
    {
        $base = rtrim($this->getStoreUrl(), '/')
            . '/' . trim($this->config->getStorefrontRoutePath(), '/')
            . '/' . rawurlencode($this->getAffiliateCode());

        $query = [];
        if ($listId !== self::ALL_LISTS) {
            $query['list'] = $listId;
        }
        if ($page > 1) {
            $query['p'] = $page;
        }

        return $query === [] ? $base : $base . '?' . http_build_query($query);
    }
}
