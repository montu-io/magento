<?php
/**
 * Montu Affiliate Tracking Extension - Webhook Service
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Service;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use Montu\Affiliate\Model\Config;
use Psr\Log\LoggerInterface;

class WebhookService
{
    private const WEBHOOK_VERSION = '1.0';
    private const EVENT_ORDER_PLACED = 'order.placed';

    private Config $config;
    private Curl $curl;
    private Json $json;
    private StoreManagerInterface $storeManager;
    private LoggerInterface $logger;

    public function __construct(
        Config $config,
        Curl $curl,
        Json $json,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->curl = $curl;
        $this->json = $json;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    /**
     * Send order webhook to configured endpoint (simple boolean response)
     *
     * @param Order $order
     * @param string $affiliateCode
     * @return bool
     */
    public function sendOrderWebhook(Order $order, string $affiliateCode): bool
    {
        $result = $this->sendOrderWebhookWithResponse($order, $affiliateCode);
        return $result['success'];
    }

    /**
     * Send order webhook and return detailed response
     *
     * @param Order $order
     * @param string $affiliateCode
     * @return array{success: bool, status_code: int|null, response_body: string|null, error_message: string}
     */
    public function sendOrderWebhookWithResponse(Order $order, string $affiliateCode): array
    {
        $result = [
            'success' => false,
            'status_code' => null,
            'response_body' => null,
            'error_message' => ''
        ];

        $endpointUrl = $this->config->getWebhookEndpointUrl();

        if (empty($endpointUrl)) {
            $result['error_message'] = 'Webhook endpoint URL is not configured';
            $this->logger->warning('[Montu Affiliate] ' . $result['error_message']);
            return $result;
        }

        $payload = $this->buildOrderPayload($order, $affiliateCode);
        $jsonPayload = $this->json->serialize($payload);
        $headers = $this->buildHeaders($jsonPayload);

        return $this->sendWebhook($endpointUrl, $jsonPayload, $headers);
    }

    /**
     * Build order payload for webhook
     *
     * @param Order $order
     * @param string $affiliateCode
     * @return array
     */
    private function buildOrderPayload(Order $order, string $affiliateCode): array
    {
        $store = $order->getStore();

        return [
            'webhook_version' => self::WEBHOOK_VERSION,
            'event' => self::EVENT_ORDER_PLACED,
            'timestamp' => date('c'),
            'store' => [
                'code' => $store->getCode(),
                'name' => $store->getName(),
                'base_url' => $store->getBaseUrl(),
                'currency' => $order->getOrderCurrencyCode(),
            ],
            'order' => [
                'id' => $order->getId(),
                'increment_id' => $order->getIncrementId(),
                'status' => $order->getStatus(),
                'state' => $order->getState(),
                'created_at' => $order->getCreatedAt(),
                'grand_total' => (float) $order->getGrandTotal(),
                'subtotal' => (float) $order->getSubtotal(),
                'tax_amount' => (float) $order->getTaxAmount(),
                'shipping_amount' => (float) $order->getShippingAmount(),
                'discount_amount' => (float) $order->getDiscountAmount(),
                'total_qty_ordered' => (float) $order->getTotalQtyOrdered(),
                'currency_code' => $order->getOrderCurrencyCode(),
                'payment_method' => $order->getPayment() ? $order->getPayment()->getMethod() : null,
                'shipping_method' => $order->getShippingMethod(),
                'coupon_code' => $order->getCouponCode(),
            ],
            'affiliate' => [
                'code' => $affiliateCode,
            ],
            'customer' => [
                'id' => $order->getCustomerId(),
                'email' => $order->getCustomerEmail(),
                'firstname' => $order->getCustomerFirstname(),
                'lastname' => $order->getCustomerLastname(),
                'is_guest' => (bool) $order->getCustomerIsGuest(),
            ],
            'items' => $this->buildItemsPayload($order),
            'billing_address' => $this->buildAddressPayload($order->getBillingAddress()),
            'shipping_address' => $this->buildAddressPayload($order->getShippingAddress()),
        ];
    }

    /**
     * Build items payload
     *
     * @param Order $order
     * @return array
     */
    private function buildItemsPayload(Order $order): array
    {
        $items = [];

        foreach ($order->getAllVisibleItems() as $item) {
            $items[] = [
                'sku' => $item->getSku(),
                'name' => $item->getName(),
                'qty' => (float) $item->getQtyOrdered(),
                'price' => (float) $item->getPrice(),
                'row_total' => (float) $item->getRowTotal(),
                'tax_amount' => (float) $item->getTaxAmount(),
                'discount_amount' => (float) $item->getDiscountAmount(),
                'product_type' => $item->getProductType(),
            ];
        }

        return $items;
    }

    /**
     * Build address payload
     *
     * @param \Magento\Sales\Model\Order\Address|null $address
     * @return array|null
     */
    private function buildAddressPayload($address): ?array
    {
        if (!$address) {
            return null;
        }

        return [
            'firstname' => $address->getFirstname(),
            'lastname' => $address->getLastname(),
            'street' => implode(', ', $address->getStreet() ?: []),
            'city' => $address->getCity(),
            'region' => $address->getRegion(),
            'postcode' => $address->getPostcode(),
            'country_id' => $address->getCountryId(),
            'telephone' => $address->getTelephone(),
        ];
    }

    /**
     * Build headers for webhook request
     *
     * @param string $jsonPayload Serialized JSON body to sign
     * @return array
     */
    private function buildHeaders(string $jsonPayload): array
    {
        $timestamp = (string) time();

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Montu-Webhook-Version' => self::WEBHOOK_VERSION,
            'X-Montu-Event' => self::EVENT_ORDER_PLACED,
            'X-Montu-Timestamp' => $timestamp,
        ];

        // Add API Key for authentication
        $apiKey = $this->config->getApiKey();
        if (!empty($apiKey)) {
            $headers['X-Montu-Api-Key'] = $apiKey;
        }

        // Add store domain for identification
        try {
            $store = $this->storeManager->getStore();
            $headers['X-Montu-Store-Domain'] = $store->getBaseUrl();
            $headers['X-Montu-Store-Code'] = $store->getCode();
        } catch (\Exception $e) {
            $this->logger->warning('[Montu Affiliate] Could not get store information for headers');
        }

        // Sign the full request body for payload integrity verification
        $headers['X-Montu-Signature'] = $this->generateSignature($apiKey, $jsonPayload);

        return $headers;
    }

    /**
     * Generate HMAC-SHA256 signature over the request body
     *
     * @param string $apiKey
     * @param string $payload The raw JSON body
     * @return string
     */
    private function generateSignature(string $apiKey, string $payload): string
    {
        return hash_hmac('sha256', $payload, $apiKey);
    }

    /**
     * Send webhook request with detailed response
     *
     * @param string $url
     * @param string $jsonPayload Pre-serialized JSON body
     * @param array $headers
     * @return array{success: bool, status_code: int|null, response_body: string|null, error_message: string}
     */
    private function sendWebhook(string $url, string $jsonPayload, array $headers): array
    {
        $result = [
            'success' => false,
            'status_code' => null,
            'response_body' => null,
            'error_message' => ''
        ];

        try {
            $timeout = $this->config->getWebhookTimeout();

            // Create new Curl instance for each request to avoid state issues
            $curl = new Curl();

            // Configure curl
            $curl->setTimeout($timeout);
            $curl->setOption(CURLOPT_CONNECTTIMEOUT, 10);
            $curl->setOption(CURLOPT_RETURNTRANSFER, true);
            $curl->setOption(CURLOPT_SSL_VERIFYPEER, true);

            // Set headers
            foreach ($headers as $name => $value) {
                $curl->addHeader($name, $value);
            }

            // Send POST request
            $curl->post($url, $jsonPayload);

            $result['status_code'] = $curl->getStatus();
            $result['response_body'] = $curl->getBody();

            // Log the request
            $this->logger->info(
                '[Montu Affiliate] Webhook sent',
                [
                    'url' => $url,
                    'status_code' => $result['status_code'],
                ]
            );

            // Check for successful response (2xx status codes)
            if ($result['status_code'] >= 200 && $result['status_code'] < 300) {
                $result['success'] = true;
            } else {
                $result['error_message'] = sprintf(
                    'HTTP %d: %s',
                    $result['status_code'],
                    $this->truncateString($result['response_body'], 500)
                );

                $this->logger->error(
                    '[Montu Affiliate] Webhook failed',
                    [
                        'url' => $url,
                        'status_code' => $result['status_code'],
                        'response' => $this->truncateString($result['response_body'], 1000),
                    ]
                );
            }

        } catch (\Exception $e) {
            $result['error_message'] = 'Exception: ' . $e->getMessage();
            
            $this->logger->error(
                '[Montu Affiliate] Webhook exception: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }

        return $result;
    }

    /**
     * Truncate string to max length
     *
     * @param string|null $string
     * @param int $maxLength
     * @return string
     */
    private function truncateString(?string $string, int $maxLength): string
    {
        if ($string === null) {
            return '';
        }

        if (strlen($string) <= $maxLength) {
            return $string;
        }

        return substr($string, 0, $maxLength) . '... [truncated]';
    }
}
