<?php
/**
 * Montu Affiliate Tracking Extension - Webhook Service
 *
 * Transport only: takes the payload snapshot recorded with an event, adds the
 * send-time timestamp, signs it and POSTs it. What goes into the payload is
 * decided when the event is recorded (Service\EventRecorder).
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Service;

use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Store\Model\StoreManagerInterface;
use Montu\Affiliate\Model\Config;
use Montu\Affiliate\Model\ModuleVersion;
use Montu\Affiliate\Model\WebhookLog;
use Psr\Log\LoggerInterface;

class WebhookService
{
    public const WEBHOOK_VERSION = '2.0';

    /**
     * Seconds to open the connection to Montu
     */
    private const CONNECT_TIMEOUT_SECONDS = 10;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var CurlFactory
     */
    private CurlFactory $curlFactory;

    /**
     * @var ModuleVersion
     */
    private ModuleVersion $moduleVersion;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Config $config
     * @param CurlFactory $curlFactory
     * @param ModuleVersion $moduleVersion
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        CurlFactory $curlFactory,
        ModuleVersion $moduleVersion,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->curlFactory = $curlFactory;
        $this->moduleVersion = $moduleVersion;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    /**
     * Deliver one recorded event
     *
     * Settings (endpoint, key, secret, timeouts) and the X-Montu-Store-*
     * headers come from the store view of the order the event belongs to.
     *
     * @param WebhookLog $log
     * @param string $payload JSON snapshot stored with the event
     * @return array{success: bool, status_code: int|null, response_body: string|null, error_message: string, not_configured?: bool}
     */
    public function send(WebhookLog $log, string $payload): array
    {
        $result = [
            'success' => false,
            'status_code' => null,
            'response_body' => null,
            'error_message' => ''
        ];

        $storeId = $log->getStoreId();
        $endpointUrl = $this->config->getWebhookEndpointUrl($storeId);

        if (empty($endpointUrl)) {
            $result['error_message'] = 'Webhook endpoint URL is not configured';
            $result['not_configured'] = true;
            $this->logger->warning('[Montu Affiliate] ' . $result['error_message'], ['store_id' => $storeId]);
            return $result;
        }

        $keyId = $this->config->getKeyId($storeId);
        $signingSecret = $this->config->getSigningSecret($storeId);

        if ($keyId === '' || $signingSecret === '') {
            $result['error_message'] = 'Webhook signing key is not configured';
            $result['not_configured'] = true;
            $this->logger->warning('[Montu Affiliate] ' . $result['error_message'], ['store_id' => $storeId]);
            return $result;
        }

        // The timestamp is set on every attempt (the backend rejects bodies older
        // than 5 minutes); everything else is the snapshot taken when recorded.
        $body = '{"timestamp":' . json_encode(gmdate('c')) . ',' . substr(ltrim($payload), 1);

        return $this->post(
            $endpointUrl,
            $body,
            $this->buildHeaders($log, $body, $keyId, $signingSecret),
            $storeId
        );
    }

    /**
     * Build headers for webhook request
     *
     * @param WebhookLog $log
     * @param string $body Serialized JSON body to sign
     * @param string $keyId Public key id, sent as X-Montu-Key-Id
     * @param string $signingSecret Secret used to sign the body; never sent
     * @return array<string, string>
     */
    private function buildHeaders(WebhookLog $log, string $body, string $keyId, string $signingSecret): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Montu-Webhook-Version' => self::WEBHOOK_VERSION,
            'X-Montu-Event' => $log->getEventType(),
            'X-Montu-Event-Id' => $log->getEventId(),
            'X-Montu-Module-Version' => $this->moduleVersion->get(),
            'X-Montu-Timestamp' => (string) time(),
            'X-Montu-Key-Id' => $keyId,
        ];

        try {
            $store = $this->storeManager->getStore($log->getStoreId());
            $headers['X-Montu-Store-Domain'] = $store->getBaseUrl();
            $headers['X-Montu-Store-Code'] = $store->getCode();
        } catch (\Exception $e) {
            $this->logger->warning('[Montu Affiliate] Could not get store information for headers');
        }

        // Sign the full request body for authentication and payload integrity
        $headers['X-Montu-Signature'] = hash_hmac('sha256', $body, $signingSecret);

        return $headers;
    }

    /**
     * Send webhook request with detailed response
     *
     * @param string $url
     * @param string $body Pre-serialized JSON body
     * @param array<string, string> $headers
     * @param int $storeId
     * @return array{success: bool, status_code: int|null, response_body: string|null, error_message: string}
     */
    private function post(string $url, string $body, array $headers, int $storeId): array
    {
        $result = [
            'success' => false,
            'status_code' => null,
            'response_body' => null,
            'error_message' => ''
        ];

        try {
            // Create new Curl instance for each request to avoid state issues
            $curl = $this->curlFactory->create();

            $curl->setTimeout($this->config->getWebhookTimeout($storeId));
            $curl->setOption(CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SECONDS);
            $curl->setOption(CURLOPT_RETURNTRANSFER, true);
            $curl->setOption(CURLOPT_SSL_VERIFYPEER, true);

            foreach ($headers as $name => $value) {
                $curl->addHeader($name, $value);
            }

            $curl->post($url, $body);

            $result['status_code'] = $curl->getStatus();
            $result['response_body'] = $curl->getBody();

            $this->logger->debug(
                '[Montu Affiliate] Webhook sent',
                ['url' => $url, 'status_code' => $result['status_code']]
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
        } catch (\Throwable $e) {
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
