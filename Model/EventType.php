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

    public const PRODUCT_CREATED = 'product.created';
    public const PRODUCT_UPDATED = 'product.updated';
    public const PRODUCT_DELETED = 'product.deleted';
    public const COLLECTION_CREATED = 'collection.created';
    public const COLLECTION_UPDATED = 'collection.updated';
    public const COLLECTION_DELETED = 'collection.deleted';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return array_merge([self::ORDER_PLACED, self::ORDER_UPDATED, self::CREDITMEMO_CREATED], self::catalog());
    }

    /**
     * The catalog event types (Magento categories are Montu collections)
     *
     * @return string[]
     */
    public static function catalog(): array
    {
        return [
            self::PRODUCT_CREATED,
            self::PRODUCT_UPDATED,
            self::PRODUCT_DELETED,
            self::COLLECTION_CREATED,
            self::COLLECTION_UPDATED,
            self::COLLECTION_DELETED,
        ];
    }
}
