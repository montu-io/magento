<?php
/**
 * Montu Affiliate Tracking Extension - Webhook Log Resource Model
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model\ResourceModel;

use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class WebhookLog extends AbstractDb
{
    /**
     * @var string
     */
    protected $_eventPrefix = 'montu_affiliate_webhook_log_resource';

    /**
     * Initialize resource model
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('montu_affiliate_webhook_log', 'entity_id');
    }

    /**
     * Insert an outbox row unless its dedupe key is already there
     *
     * @param array<string, mixed> $row
     * @return bool False when the event was already recorded
     * @throws \Exception On any other database error
     */
    public function insertEvent(array $row): bool
    {
        try {
            $this->getConnection()->insert($this->getMainTable(), $row);
        } catch (DuplicateException $e) {
            return false;
        }

        return true;
    }

    /**
     * Check if an event with this dedupe key is already in the outbox
     *
     * @param string $dedupeKey
     * @return bool
     */
    public function hasDedupeKey(string $dedupeKey): bool
    {
        $connection = $this->getConnection();

        return $connection->fetchOne(
            $connection->select()->from($this->getMainTable(), ['entity_id'])->where('dedupe_key = ?', $dedupeKey)
        ) !== false;
    }
}
