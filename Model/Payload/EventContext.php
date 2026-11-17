<?php
/**
 * Montu Affiliate Tracking Extension - Payload Event Context
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model\Payload;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;

/**
 * Everything a payload section may need about the event being recorded
 */
class EventContext
{
    /**
     * @var string
     */
    public string $eventType;

    /**
     * @var string
     */
    public string $trigger;

    /**
     * @var string
     */
    public string $eventId;

    /**
     * @var string
     */
    public string $occurredAt;

    /**
     * @var Order
     */
    public Order $order;

    /**
     * @var string
     */
    public string $affiliateCode;

    /**
     * @var string|null
     */
    public ?string $referralLink;

    /**
     * @var string|null
     */
    public ?string $previousState;

    /**
     * @var string|null
     */
    public ?string $previousStatus;

    /**
     * @var Creditmemo|null
     */
    public ?Creditmemo $creditmemo;

    /**
     * @param string $eventType One of the EventType constants
     * @param string $trigger Magento event that recorded it
     * @param string $eventId
     * @param string $occurredAt ISO-8601
     * @param Order $order
     * @param string $affiliateCode Empty when the order only has a coupon
     * @param string|null $referralLink Link slug of the same click as the code (null when none)
     * @param string|null $previousState State at the last recorded event (null on order.placed)
     * @param string|null $previousStatus Status at the last recorded event (null on order.placed)
     * @param Creditmemo|null $creditmemo Only for creditmemo.created
     */
    public function __construct(
        string $eventType,
        string $trigger,
        string $eventId,
        string $occurredAt,
        Order $order,
        string $affiliateCode,
        ?string $referralLink,
        ?string $previousState = null,
        ?string $previousStatus = null,
        ?Creditmemo $creditmemo = null
    ) {
        $this->eventType = $eventType;
        $this->trigger = $trigger;
        $this->eventId = $eventId;
        $this->occurredAt = $occurredAt;
        $this->order = $order;
        $this->affiliateCode = $affiliateCode;
        $this->referralLink = $referralLink;
        $this->previousState = $previousState;
        $this->previousStatus = $previousStatus;
        $this->creditmemo = $creditmemo;
    }

    /**
     * Store view of the order, the scope every setting is read from
     *
     * @return int
     */
    public function getStoreId(): int
    {
        return (int) $this->order->getStoreId();
    }
}
