<?php
/**
 * Montu Affiliate Tracking Extension - Cleanup Events Cron
 *
 * Deletes finished events from the outbox once a day (etc/crontab.xml), apart
 * from delivery so a large cleanup never delays sending:
 * - delivered order events after "Retention (days)" (default 30, 0 = keep);
 * - skipped catalog events after 7 days, and delivered ones after 7 days once a
 *   newer delivered event of the same product / category replaced them (the
 *   newest is kept without its payload: it is the reference that stops
 *   unchanged products from being sent again);
 * - events that used up their attempts after 90 days.
 * Deletes in batches of 1000 by primary key.
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Cron;

use Montu\Affiliate\Model\Config;
use Montu\Affiliate\Model\WebhookLogRepository;
use Psr\Log\LoggerInterface;

class CleanupEvents
{
    private const CATALOG_RETENTION_DAYS = 7;
    private const DEAD_RETENTION_DAYS = 90;

    /**
     * Batches of 1000 rows per rule and run: up to 500,000 rows a day per rule
     */
    private const MAX_BATCHES = 500;

    private Config $config;
    private WebhookLogRepository $webhookLogRepository;
    private LoggerInterface $logger;

    /**
     * @param Config $config
     * @param WebhookLogRepository $webhookLogRepository
     * @param LoggerInterface $logger
     */
    public function __construct(Config $config, WebhookLogRepository $webhookLogRepository, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->webhookLogRepository = $webhookLogRepository;
        $this->logger = $logger;
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        try {
            $deleted = $this->webhookLogRepository->purge(
                $this->config->getRetentionDays(),
                self::CATALOG_RETENTION_DAYS,
                self::DEAD_RETENTION_DAYS,
                self::MAX_BATCHES
            );
            if ($deleted > 0) {
                $this->logger->info('[Montu Affiliate] Deleted old events', ['count' => $deleted]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[Montu Affiliate] Cleanup error: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
