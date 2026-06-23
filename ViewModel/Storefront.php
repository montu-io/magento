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
use Montu\Affiliate\Model\Config\Source\ThemeMode;
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

    // ----- White-label appearance ---------------------------------------------

    /**
     * Storefront theme mode: "auto" (sample the store at runtime), "manual"
     * (use the admin colours), or "off" (neutral defaults). Drives both the
     * server-rendered variable block and the runtime sampling script.
     *
     * @return string
     */
    public function getThemeMode(): string
    {
        $mode = $this->config->getThemeMode();
        return in_array($mode, [ThemeMode::AUTO, ThemeMode::MANUAL, ThemeMode::OFF], true)
            ? $mode
            : ThemeMode::AUTO;
    }

    /**
     * CSS custom properties to emit server-side from the admin appearance config.
     *
     * Returns a map of `--montu-*` variable => value for every colour/typography
     * field the merchant has set (sanitised to safe CSS tokens). In "manual" mode
     * these ARE the theme; in "auto" they seed the page until runtime sampling
     * resolves; in "off" nothing is emitted (neutral defaults win). Empty/invalid
     * values are skipped so the template default applies.
     *
     * @return array<string, string>
     */
    public function getAppearanceVars(): array
    {
        if ($this->getThemeMode() === ThemeMode::OFF) {
            return [];
        }

        $vars = [];

        $accent = $this->sanitizeColor($this->config->getAccentColor());
        if ($accent !== null) {
            $vars['--montu-accent'] = $accent;
            $soft = $this->colorToSoft($accent);
            if ($soft !== null) {
                $vars['--montu-accent-soft'] = $soft;
            }
        }

        $map = [
            '--montu-accent-contrast' => $this->sanitizeColor($this->config->getAccentTextColor()),
            '--montu-text' => $this->sanitizeColor($this->config->getTextColor()),
            '--montu-muted' => $this->sanitizeColor($this->config->getMutedColor()),
            '--montu-border' => $this->sanitizeColor($this->config->getBorderColor()),
            '--montu-surface' => $this->sanitizeColor($this->config->getSurfaceColor()),
            '--montu-radius' => $this->sanitizeLength($this->config->getCardRadius()),
            '--montu-font' => $this->sanitizeFontFamily($this->config->getFontFamily()),
        ];
        foreach ($map as $name => $value) {
            if ($value !== null) {
                $vars[$name] = $value;
            }
        }

        return $vars;
    }

    /**
     * Validate a CSS colour token (hex, rgb/rgba, hsl/hsla, or a named colour),
     * returning it unchanged or null if it is not a safe, recognised colour.
     * Guards the inline <style> block against CSS injection from admin input.
     *
     * @param string $value
     * @return string|null
     */
    private function sanitizeColor(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $hex = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/';
        $func = '/^(?:rgb|rgba|hsl|hsla)\(\s*[0-9.,%\/\s]+\)$/i';
        $named = '/^[a-zA-Z]{1,30}$/';
        if (preg_match($hex, $value) || preg_match($func, $value) || preg_match($named, $value)) {
            return $value;
        }
        return null;
    }

    /**
     * Validate a CSS length (e.g. "10px", "0", "0.5rem", "50%"), or null.
     *
     * @param string $value
     * @return string|null
     */
    private function sanitizeLength(string $value): ?string
    {
        $value = trim($value);
        return preg_match('/^[0-9]+(?:\.[0-9]+)?(?:px|rem|em|%)?$/', $value) ? $value : null;
    }

    /**
     * Validate a font-family stack (letters, digits, spaces, commas, quotes,
     * hyphens only), or null. Prevents breaking out of the declaration.
     *
     * @param string $value
     * @return string|null
     */
    private function sanitizeFontFamily(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        return preg_match('/^[a-zA-Z0-9 ,"\'\-]+$/', $value) ? $value : null;
    }

    /**
     * Derive a translucent "soft" tint from an accent colour for hover/active
     * backgrounds. Hex accents become rgba(...,0.12); anything else falls back to
     * a colour-mix so the tint always tracks the accent. Null if not derivable.
     *
     * @param string $accent
     * @return string|null
     */
    private function colorToSoft(string $accent): ?string
    {
        if (preg_match('/^#([0-9a-fA-F]{6})$/', $accent, $m)) {
            $r = hexdec(substr($m[1], 0, 2));
            $g = hexdec(substr($m[1], 2, 2));
            $b = hexdec(substr($m[1], 4, 2));
            return "rgba($r, $g, $b, 0.12)";
        }
        if (preg_match('/^#([0-9a-fA-F]{3})$/', $accent, $m)) {
            $r = hexdec(str_repeat($m[1][0], 2));
            $g = hexdec(str_repeat($m[1][1], 2));
            $b = hexdec(str_repeat($m[1][2], 2));
            return "rgba($r, $g, $b, 0.12)";
        }
        // rgb()/hsl()/named: let CSS mix it with transparent at render time.
        return "color-mix(in srgb, $accent 12%, transparent)";
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
     * The affiliate's full name (first + last), or "" if unknown.
     *
     * @return string
     */
    public function getAffiliateName(): string
    {
        $affiliate = $this->getRecommendations()->getAffiliate();
        return $affiliate !== null ? $affiliate->getFullName() : '';
    }

    /**
     * The affiliate's profile picture URL, or null if none.
     *
     * @return string|null
     */
    public function getAffiliateProfilePicture(): ?string
    {
        $affiliate = $this->getRecommendations()->getAffiliate();
        return $affiliate !== null ? $affiliate->getProfilePicture() : null;
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
