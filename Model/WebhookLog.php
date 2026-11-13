<?php
/**
 * Montu Affiliate Tracking Extension - Webhook Log Model
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model;

use Magento\Framework\Model\AbstractModel;
use Montu\Affiliate\Model\ResourceModel\WebhookLog as WebhookLogResource;

class WebhookLog extends AbstractModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_MAX_RETRIES = 'max_retries';

    /**
     * A catalog event with nothing to send (see error_message for why)
     */
    public const STATUS_SKIPPED = 'skipped';

    /**
     * Default max retries
     */
    public const DEFAULT_MAX_RETRIES = 5;

    /**
     * Minutes to wait after each failed attempt; the last value repeats
     */
    private const BACKOFF_MINUTES = [1, 5, 15, 30, 60];

    /**
     * @var string
     */
    protected $_eventPrefix = 'montu_affiliate_webhook_log';

    /**
     * Initialize resource model
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(WebhookLogResource::class);
    }

    /**
     * Get entity ID
     *
     * @return int|null
     */
    public function getEntityId(): ?int
    {
        $id = $this->getData('entity_id');
        return $id !== null ? (int) $id : null;
    }

    /**
     * Get order ID
     *
     * @return int
     */
    public function getOrderId(): int
    {
        return (int) $this->getData('order_id');
    }

    /**
     * Set order ID
     *
     * @param int $orderId
     * @return $this
     */
    public function setOrderId(int $orderId): self
    {
        return $this->setData('order_id', $orderId);
    }

    /**
     * Get event UUID
     *
     * @return string
     */
    public function getEventId(): string
    {
        return (string) $this->getData('event_id');
    }

    /**
     * Get event type (order.placed, order.updated, creditmemo.created)
     *
     * @return string
     */
    public function getEventType(): string
    {
        return (string) ($this->getData('event_type') ?: EventType::ORDER_PLACED);
    }

    /**
     * Get the store view of the order (0 when unknown, e.g. legacy rows)
     *
     * @return int
     */
    public function getStoreId(): int
    {
        return (int) $this->getData('store_id');
    }

    /**
     * Get the JSON snapshot, null for legacy rows queued before v2
     *
     * @return string|null
     */
    public function getPayload(): ?string
    {
        $payload = $this->getData('payload');
        return $payload !== null && $payload !== '' ? (string) $payload : null;
    }

    /**
     * Get affiliate code
     *
     * @return string
     */
    public function getAffiliateCode(): string
    {
        return (string) $this->getData('affiliate_code');
    }

    /**
     * Set affiliate code
     *
     * @param string $code
     * @return $this
     */
    public function setAffiliateCode(string $code): self
    {
        return $this->setData('affiliate_code', $code);
    }

    /**
     * Get status
     *
     * @return string
     */
    public function getStatus(): string
    {
        return (string) $this->getData('status');
    }

    /**
     * Set status
     *
     * @param string $status
     * @return $this
     */
    public function setStatus(string $status): self
    {
        return $this->setData('status', $status);
    }

    /**
     * Get retry count
     *
     * @return int
     */
    public function getRetryCount(): int
    {
        return (int) $this->getData('retry_count');
    }

    /**
     * Set retry count
     *
     * @param int $count
     * @return $this
     */
    public function setRetryCount(int $count): self
    {
        return $this->setData('retry_count', $count);
    }

    /**
     * Increment retry count
     *
     * @return $this
     */
    public function incrementRetryCount(): self
    {
        return $this->setRetryCount($this->getRetryCount() + 1);
    }

    /**
     * Get max retries
     *
     * @return int
     */
    public function getMaxRetries(): int
    {
        return (int) ($this->getData('max_retries') ?: self::DEFAULT_MAX_RETRIES);
    }

    /**
     * Set max retries
     *
     * @param int $maxRetries
     * @return $this
     */
    public function setMaxRetries(int $maxRetries): self
    {
        return $this->setData('max_retries', $maxRetries);
    }

    /**
     * Check if can retry
     *
     * @return bool
     */
    public function canRetry(): bool
    {
        return $this->getRetryCount() < $this->getMaxRetries();
    }

    /**
     * Set HTTP status code
     *
     * @param int|null $code
     * @return $this
     */
    public function setHttpStatusCode(?int $code): self
    {
        return $this->setData('http_status_code', $code);
    }

    /**
     * Set response body
     *
     * @param string|null $body
     * @return $this
     */
    public function setResponseBody(?string $body): self
    {
        return $this->setData('response_body', $body);
    }

    /**
     * Set error message
     *
     * @param string|null $message
     * @return $this
     */
    public function setErrorMessage(?string $message): self
    {
        return $this->setData('error_message', $message);
    }

    /**
     * Get next retry at
     *
     * @return string|null
     */
    public function getNextRetryAt(): ?string
    {
        return $this->getData('next_retry_at');
    }

    /**
     * Set next retry at
     *
     * @param string|null $datetime
     * @return $this
     */
    public function setNextRetryAt(?string $datetime): self
    {
        return $this->setData('next_retry_at', $datetime);
    }

    /**
     * Set last attempt at to now
     *
     * @return $this
     */
    public function setLastAttemptAtNow(): self
    {
        return $this->setData('last_attempt_at', date('Y-m-d H:i:s'));
    }

    /**
     * Calculate the next retry time from the backoff list. The wait is picked
     * by the number of attempts that have ALREADY failed (the pre-increment
     * retry_count); past the end of the list the last value repeats.
     *
     * @return string
     */
    public function calculateNextRetryTime(): string
    {
        $delayMinutes = self::BACKOFF_MINUTES[min($this->getRetryCount(), count(self::BACKOFF_MINUTES) - 1)];

        return date('Y-m-d H:i:s', strtotime('+' . $delayMinutes . ' minutes'));
    }

    /**
     * Mark as processing
     *
     * @return $this
     */
    public function markAsProcessing(): self
    {
        return $this->setStatus(self::STATUS_PROCESSING)
                    ->setLastAttemptAtNow();
    }

    /**
     * Mark as success
     *
     * @param int $statusCode
     * @param string|null $responseBody
     * @return $this
     */
    public function markAsSuccess(int $statusCode, ?string $responseBody = null): self
    {
        return $this->setStatus(self::STATUS_SUCCESS)
                    ->setHttpStatusCode($statusCode)
                    ->setResponseBody($responseBody)
                    ->setErrorMessage(null)
                    ->setNextRetryAt(null)
                    ->releaseCatalogKey();
    }

    /**
     * A catalog deletion keeps its collapse key (catalog:...) until it is
     * finished, so changes recorded meanwhile merge into it; release it then.
     * Order events keep theirs: it is what stops an event being recorded twice.
     *
     * @return $this
     */
    private function releaseCatalogKey(): self
    {
        if ($this->getData('subject_id') !== null && str_starts_with((string) $this->getData('dedupe_key'), 'catalog:')) {
            $this->setData('dedupe_key', null);
        }

        return $this;
    }

    /**
     * Mark as failed and schedule retry if possible
     *
     * @param string $errorMessage
     * @param int|null $statusCode
     * @param string|null $responseBody
     * @return $this
     */
    public function markAsFailed(
        string $errorMessage,
        ?int $statusCode = null,
        ?string $responseBody = null
    ): self {
        $this->setHttpStatusCode($statusCode)
             ->setResponseBody($responseBody)
             ->setErrorMessage($errorMessage);

        // Computed before incrementing the count so the first retry gets the
        // first backoff value.
        $nextRetryAt = $this->calculateNextRetryTime();

        $this->incrementRetryCount();

        if ($this->canRetry()) {
            $this->setStatus(self::STATUS_FAILED)
                 ->setNextRetryAt($nextRetryAt);
        } else {
            $this->setStatus(self::STATUS_MAX_RETRIES)
                 ->setNextRetryAt(null)
                 ->releaseCatalogKey();
        }

        return $this;
    }
}
