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
 * STATUS: the Montu recommendations endpoint does not exist yet. Until it does,
 * this client returns demo recommendations built from the real catalog by
 * StubRecommendationProvider, so the storefront works end to end. The live call
 * is scaffolded in fetchFromMontu(): once the endpoint is ready, set
 * montu_affiliate/storefront/products_endpoint in admin and flip USE_STUB_DATA
 * to false. The expected JSON shape is documented on parseResponse().
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Service;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Montu\Affiliate\Model\Config;
use Montu\Affiliate\Model\Data\AffiliateRecommendations;
use Montu\Affiliate\Model\Data\RecommendationList;
use Psr\Log\LoggerInterface;

class RecommendationClient
{
    /**
     * While the Montu endpoint is not ready, always serve demo data.
     * Flip to false (and configure an endpoint) once the API is live.
     */
    private const USE_STUB_DATA = true;

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
     * Signs the request with the same API key used for the order webhook so the
     * backend can authenticate it identically.
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
        if ($apiKey !== '') {
            $curl->addHeader('X-Montu-Api-Key', $apiKey);
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
     *   "lists": [
     *     {
     *       "id": "skincare",
     *       "name": "Mi rutina de skincare",
     *       "variant_ids": [123, 456, 789]
     *     },
     *     {
     *       "id": "makeup",
     *       "name": "Maquillaje favorito",
     *       "variant_ids": [222, 333]
     *     }
     *   ]
     * }
     *
     * variant_ids are Magento product identifiers (child/simple product ids).
     *
     * @param string $affiliateCode
     * @param string $body
     * @return AffiliateRecommendations
     */
    private function parseResponse(string $affiliateCode, string $body): AffiliateRecommendations
    {
        $data = $this->json->unserialize($body);
        $rawLists = is_array($data) && isset($data['lists']) && is_array($data['lists'])
            ? $data['lists']
            : [];

        $lists = [];
        foreach ($rawLists as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $variantIds = isset($row['variant_ids']) && is_array($row['variant_ids'])
                ? $row['variant_ids']
                : [];
            if ($variantIds === []) {
                continue;
            }
            $lists[] = new RecommendationList(
                (string) ($row['id'] ?? ('list-' . $index)),
                (string) ($row['name'] ?? __('Recommended')),
                $variantIds
            );
        }

        return new AffiliateRecommendations($affiliateCode, $lists);
    }
}
