<?php
/**
 * Montu Affiliate Tracking Extension - Event Types
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model;

/**
 * The event types the module sends (value of the payload's "event" field)
 */
class EventType
{
    public const ORDER_PLACED = 'order.placed';
    public const ORDER_UPDATED = 'order.updated';
    public const CREDITMEMO_CREATED = 'creditmemo.created';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [self::ORDER_PLACED, self::ORDER_UPDATED, self::CREDITMEMO_CREATED];
    }
}
