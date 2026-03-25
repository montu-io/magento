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
     * Default max retries
     */
    public const DEFAULT_MAX_RETRIES = 5;

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
     * Get HTTP status code
     *
     * @return int|null
     */
    public function getHttpStatusCode(): ?int
    {
        $code = $this->getData('http_status_code');
        return $code !== null ? (int) $code : null;
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
     * Get response body
     *
     * @return string|null
     */
    public function getResponseBody(): ?string
    {
        return $this->getData('response_body');
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
     * Get error message
     *
     * @return string|null
     */
    public function getErrorMessage(): ?string
    {
        return $this->getData('error_message');
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
     * Calculate next retry time using exponential backoff
     * Retry delays: 1min, 5min, 15min, 30min, 1hour
     *
     * @return string
     */
    public function calculateNextRetryTime(): string
    {
        $retryCount = $this->getRetryCount();
        
        // Exponential backoff with max cap
        $delayMinutes = match ($retryCount) {
            0 => 1,      // First retry: 1 minute
            1 => 5,      // Second retry: 5 minutes
            2 => 15,     // Third retry: 15 minutes
            3 => 30,     // Fourth retry: 30 minutes
            default => 60, // Fifth+ retry: 1 hour
        };

        $nextRetry = strtotime("+{$delayMinutes} minutes");
        return date('Y-m-d H:i:s', $nextRetry);
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
                    ->setNextRetryAt(null);
    }

    /**
     * Mark as failed and schedule retry if possible
     *
     * @param string $errorMessage
     * @param int|null $statusCode
     * @param string|null $responseBody
     * @return $this
     */
    public function markAsFailed(string $errorMessage, ?int $statusCode = null, ?string $responseBody = null): self
    {
        $this->incrementRetryCount()
             ->setHttpStatusCode($statusCode)
             ->setResponseBody($responseBody)
             ->setErrorMessage($errorMessage);

        if ($this->canRetry()) {
            $this->setStatus(self::STATUS_FAILED)
                 ->setNextRetryAt($this->calculateNextRetryTime());
        } else {
            $this->setStatus(self::STATUS_MAX_RETRIES)
                 ->setNextRetryAt(null);
        }

        return $this;
    }
}

