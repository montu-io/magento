<?php
/**
 * Montu Affiliate Tracking Extension - Tracking State
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model;

/**
 * What montu_affiliate_tracking knows about an order: its affiliate code and
 * the snapshot recorded with the last event (the order is "tracked" once
 * order.placed was recorded, which is when the fingerprint is first set).
 */
class TrackingState
{
    /**
     * @var string|null
     */
    private ?string $affiliateCode;

    /**
     * @var string|null
     */
    private ?string $fingerprint;

    /**
     * @var string|null
     */
    private ?string $lastState;

    /**
     * @var string|null
     */
    private ?string $lastStatus;

    /**
     * @param string|null $affiliateCode
     * @param string|null $fingerprint
     * @param string|null $lastState
     * @param string|null $lastStatus
     */
    public function __construct(
        ?string $affiliateCode,
        ?string $fingerprint,
        ?string $lastState,
        ?string $lastStatus
    ) {
        $this->affiliateCode = $affiliateCode;
        $this->fingerprint = $fingerprint;
        $this->lastState = $lastState;
        $this->lastStatus = $lastStatus;
    }

    /**
     * @return string|null
     */
    public function getAffiliateCode(): ?string
    {
        return $this->affiliateCode;
    }

    /**
     * @return string|null
     */
    public function getFingerprint(): ?string
    {
        return $this->fingerprint;
    }

    /**
     * @return string|null
     */
    public function getLastState(): ?string
    {
        return $this->lastState;
    }

    /**
     * @return string|null
     */
    public function getLastStatus(): ?string
    {
        return $this->lastStatus;
    }

    /**
     * @return bool
     */
    public function isTracked(): bool
    {
        return $this->fingerprint !== null && $this->fingerprint !== '';
    }
}
