<?php
/**
 * Montu Affiliate Tracking Extension - Affiliate Recommendation Client
 *
 * Fetches an affiliate's recommended variants from Montu, grouped into lists
 * ("categories"). Montu returns only identifiers + list structure; the product
 * data (image, price, stock, options) is loaded from Magento by
 * Model\Storefront\CollectionBuilder and rendered natively by
 * Block\Storefront\ProductList.
 *
 * The live Montu recommendations endpoint is called in fetchFromMontu() (signed
 * with HMAC-SHA256 using the signing secret, like the webhook); configure its URL in
 * montu_affiliate/storefront/products_endpoint. If the endpoint is unset, errors,
 * or returns nothing, the result is empty and the store's "fallback" setting
 * decides what the controller shows: an empty page (default) or a redirect to the
 * home page. The page itself never fails. The expected JSON shape is documented
 * on parseResponse().
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Service;

use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;
use Montu\Affiliate\Model\Config;
use Montu\Affiliate\Model\Data\AffiliateInfo;
use Montu\Affiliate\Model\Data\AffiliateRecommendations;
use Montu\Affiliate\Model\Data\RecommendationList;
use Psr\Log\LoggerInterface;

class RecommendationClient
{
    /**
     * This call runs inside a shopper's page request, so a slow Montu must never
     * hold PHP workers: total request time and connect time are capped here.
     */
    private const REQUEST_TIMEOUT_SECONDS = 2;
    private const CONNECT_TIMEOUT_SECONDS = 1;

    private Config $config;
    private CurlFactory $curlFactory;
    private Json $json;
    private StoreManagerInterface $storeManager;
    private LoggerInterface $logger;

    /**
     * @var array<string, AffiliateRecommendations> Per request, so the controller and the
     *                                               view model share one Montu call
     */
    private array $cache = [];

    /**
     * @param Config $config
     * @param CurlFactory $curlFactory
     * @param Json $json
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        CurlFactory $curlFactory,
        Json $json,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->curlFactory = $curlFactory;
        $this->json = $json;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    /**
     * Get the recommendations for the given affiliate.
     *
     * When Montu is unreachable, errors or has nothing for the affiliate, the
     * result is empty (the controller applies the store's "fallback" setting).
     *
     * @param string $affiliateCode
     * @return AffiliateRecommendations
     */
    public function getRecommendations(string $affiliateCode): AffiliateRecommendations
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        $cacheKey = $storeId . '|' . $affiliateCode;
        if (!isset($this->cache[$cacheKey])) {
            $this->cache[$cacheKey] = $this->load($affiliateCode, $storeId);
        }

        return $this->cache[$cacheKey];
    }

    /**
     * @param string $affiliateCode
     * @param int $storeId
     * @return AffiliateRecommendations
     */
    private function load(string $affiliateCode, int $storeId): AffiliateRecommendations
    {
        $endpoint = $this->config->getStorefrontProductsEndpoint($storeId);
        $recommendations = null;

        if ($endpoint !== '') {
            try {
                $recommendations = $this->fetchFromMontu($endpoint, $affiliateCode, $storeId);
                if ($recommendations->getAllVariantIds() !== []) {
                    return $recommendations;
                }
            } catch (\Throwable $e) {
                // Never let the storefront page fail because Montu is unreachable.
                $this->logger->warning(
                    '[Montu Affiliate] Recommendation fetch failed: ' . $e->getMessage(),
                    ['affiliate_code' => $affiliateCode]
                );
            }
        }

        // Keep the affiliate's identity when Montu answered but had no products.
        return $recommendations ?? new AffiliateRecommendations($affiliateCode, []);
    }

    /**
     * Call the Montu recommendations endpoint and parse the response.
     *
     * The configured endpoint URL already identifies the store (it ends with the
     * Montu integration / social-network-user id, exactly like the order webhook
     * URL); the affiliate code is appended as the final path segment, giving
     * `.../recommendations/{store_id}/{code}`. The request carries the public key
     * id in X-Montu-Key-Id and is signed with HMAC-SHA256 over `timestamp.code`
     * using the signing secret, which is never sent. Throws when the key id or
     * secret is not configured (the caller then returns an empty result).
     *
     * @param string $endpoint
     * @param string $affiliateCode
     * @param int $storeId
     * @return AffiliateRecommendations
     */
    private function fetchFromMontu(string $endpoint, string $affiliateCode, int $storeId): AffiliateRecommendations
    {
        $keyId = $this->config->getKeyId($storeId);
        $signingSecret = $this->config->getSigningSecret($storeId);
        if ($keyId === '' || $signingSecret === '') {
            throw new \RuntimeException('Montu signing key is not configured');
        }

        $url = rtrim($endpoint, '/') . '/' . rawurlencode($affiliateCode);

        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::REQUEST_TIMEOUT_SECONDS);
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SECONDS);
        $curl->setOption(CURLOPT_RETURNTRANSFER, true);
        $curl->setOption(CURLOPT_SSL_VERIFYPEER, true);

        $timestamp = (string) time();
        // GET has no body, so sign "{timestamp}.{code}" (same scheme as the
        // backend's recommendations endpoint) with the secret, which never travels.
        $curl->addHeader('X-Montu-Key-Id', $keyId);
        $curl->addHeader('X-Montu-Timestamp', $timestamp);
        $curl->addHeader(
            'X-Montu-Signature',
            hash_hmac('sha256', $timestamp . '.' . $affiliateCode, $signingSecret)
        );
        $curl->addHeader('Accept', 'application/json');

        $curl->get($url);

        if ($curl->getStatus() < 200 || $curl->getStatus() >= 300) {
            throw new \RuntimeException('HTTP ' . $curl->getStatus() . ' from recommendations endpoint');
        }

        return $this->parseResponse($affiliateCode, $curl->getBody());
    }

    /**
     * Parse the Montu recommendations JSON into the recommendations DTO.
     *
     * Expected shape (when the endpoint exists):
     * {
     *   "affiliate_code": "ABC123",
     *   "products": [
     *     {"variant_id": 123, "list_ids": ["skincare", "makeup"]},
     *     {"variant_id": 456, "list_ids": ["skincare"]},
     *     {"variant_id": 999, "list_ids": []}
     *   ],
     *   "lists": [
     *     {"id": "skincare", "name": "Mi rutina de skincare", "count": 2},
     *     {"id": "makeup", "name": "Maquillaje favorito", "count": 1}
     *   ]
     * }
     *
     * Montu returns a flat product set (variant_id + the list ids it belongs to,
     * including products in no list) plus the list catalogue. We cross the two
     * here so each list gets its ordered variant ids, while "All" is every
     * product (so unlisted ones still appear). variant_ids are Magento product
     * identifiers (child/simple product ids).
     *
     * @param string $affiliateCode
     * @param string $body
     * @return AffiliateRecommendations
     */
    private function parseResponse(string $affiliateCode, string $body): AffiliateRecommendations
    {
        $data = $this->json->unserialize($body);
        $rawProducts = is_array($data) && isset($data['products']) && is_array($data['products'])
            ? $data['products']
            : [];
        $rawLists = is_array($data) && isset($data['lists']) && is_array($data['lists'])
            ? $data['lists']
            : [];

        // Full ordered product set + each product's list memberships.
        $allVariantIds = [];
        $listIdsByVariant = [];
        foreach ($rawProducts as $product) {
            if (!is_array($product) || !isset($product['variant_id'])) {
                continue;
            }
            $variantId = (int) $product['variant_id'];
            $allVariantIds[$variantId] = $variantId;
            $listIdsByVariant[$variantId] = isset($product['list_ids']) && is_array($product['list_ids'])
                ? array_map('strval', $product['list_ids'])
                : [];
        }

        // Cross products into each list (variant order preserved from products).
        $lists = [];
        foreach ($rawLists as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $listId = (string) ($row['id'] ?? ('list-' . $index));
            $variantIds = [];
            foreach ($allVariantIds as $variantId) {
                if (in_array($listId, $listIdsByVariant[$variantId] ?? [], true)) {
                    $variantIds[] = $variantId;
                }
            }
            $lists[] = new RecommendationList(
                $listId,
                (string) ($row['name'] ?? __('Recommended')),
                $variantIds
            );
        }

        return new AffiliateRecommendations(
            $affiliateCode,
            $lists,
            array_values($allVariantIds),
            $this->parseAffiliate($data)
        );
    }

    /**
     * Parse the optional affiliate identity block.
     *
     * @param mixed $data
     * @return AffiliateInfo|null
     */
    private function parseAffiliate($data): ?AffiliateInfo
    {
        if (!is_array($data) || !isset($data['affiliate']) || !is_array($data['affiliate'])) {
            return null;
        }
        $affiliate = $data['affiliate'];
        $profilePicture = $affiliate['profile_picture'] ?? null;

        return new AffiliateInfo(
            (string) ($affiliate['first_name'] ?? ''),
            (string) ($affiliate['last_name'] ?? ''),
            $profilePicture !== null ? (string) $profilePicture : null
        );
    }
}
