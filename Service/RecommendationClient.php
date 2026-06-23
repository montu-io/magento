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
 * with the same HMAC-SHA256 scheme as the webhook); configure its URL in
 * montu_affiliate/storefront/products_endpoint. If the endpoint is unset, errors,
 * or returns nothing, the client degrades gracefully to demo recommendations
 * built from the real catalog by StubRecommendationProvider, so the storefront
 * page never fails. The expected JSON shape is documented on parseResponse().
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Service;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Montu\Affiliate\Model\Config;
use Montu\Affiliate\Model\Data\AffiliateInfo;
use Montu\Affiliate\Model\Data\AffiliateRecommendations;
use Montu\Affiliate\Model\Data\RecommendationList;
use Psr\Log\LoggerInterface;

class RecommendationClient
{
    /**
     * While the Montu endpoint is not ready, always serve demo data.
     * Flip to false (and configure an endpoint) once the API is live.
     */
    private const USE_STUB_DATA = false;

    private Config $config;
    private Curl $curl;
    private Json $json;
    private StubRecommendationProvider $stubProvider;
    private LoggerInterface $logger;

    /**
     * @param Config $config
     * @param Curl $curl
     * @param Json $json
     * @param StubRecommendationProvider $stubProvider
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        Curl $curl,
        Json $json,
        StubRecommendationProvider $stubProvider,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->curl = $curl;
        $this->json = $json;
        $this->stubProvider = $stubProvider;
        $this->logger = $logger;
    }

    /**
     * Get the recommendations for the given affiliate.
     *
     * @param string $affiliateCode
     * @return AffiliateRecommendations
     */
    public function getRecommendations(string $affiliateCode): AffiliateRecommendations
    {
        $endpoint = $this->config->getStorefrontProductsEndpoint();

        if (!self::USE_STUB_DATA && $endpoint !== '') {
            try {
                $recommendations = $this->fetchFromMontu($endpoint, $affiliateCode);
                if ($recommendations->getLists() !== []) {
                    return $recommendations;
                }
            } catch (\Exception $e) {
                // Never let the storefront page fail because Montu is unreachable.
                $this->logger->warning(
                    '[Montu Affiliate] Recommendation fetch failed, using demo data: '
                    . $e->getMessage(),
                    ['affiliate_code' => $affiliateCode]
                );
            }
        }

        return $this->stubProvider->get($affiliateCode);
    }

    /**
     * Call the Montu recommendations endpoint and parse the response.
     *
     * The configured endpoint URL already identifies the store (it ends with the
     * Montu integration / social-network-user id, exactly like the order webhook
     * URL); the affiliate code is appended as the final path segment, giving
     * `.../recommendations/{store_id}/{code}`. The request is signed with the same
     * HMAC-SHA256 scheme the backend uses for the webhook, over `timestamp.code`,
     * so the backend authenticates it identically.
     *
     * @param string $endpoint
     * @param string $affiliateCode
     * @return AffiliateRecommendations
     */
    private function fetchFromMontu(string $endpoint, string $affiliateCode): AffiliateRecommendations
    {
        $url = rtrim($endpoint, '/') . '/' . rawurlencode($affiliateCode);

        $curl = new Curl();
        $curl->setTimeout($this->config->getWebhookTimeout());
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, 10);
        $curl->setOption(CURLOPT_RETURNTRANSFER, true);
        $curl->setOption(CURLOPT_SSL_VERIFYPEER, true);

        $apiKey = $this->config->getApiKey();
        $timestamp = (string) time();
        if ($apiKey !== '') {
            $curl->addHeader('X-Montu-Api-Key', $apiKey);
            // GET has no body, so sign "{timestamp}.{code}" (same scheme as the
            // backend's recommendations endpoint), keyed by the API key.
            $curl->addHeader('X-Montu-Timestamp', $timestamp);
            $curl->addHeader(
                'X-Montu-Signature',
                hash_hmac('sha256', $timestamp . '.' . $affiliateCode, $apiKey)
            );
        }
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
