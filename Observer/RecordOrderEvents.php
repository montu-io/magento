<?php
/**
 * Montu Affiliate Tracking Extension - Record Order Events Observer
 *
 * Registered in etc/events.xml on the two commit-time events:
 * - sales_order_save_commit_after: order.placed / order.updated
 * - sales_order_creditmemo_save_commit_after: creditmemo.created
 *
 * Both run after the order (or credit memo) committed. The observer never
 * throws: a failure here is logged and the Magento flow carries on.
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Montu\Affiliate\Service\EventRecorder;
use Psr\Log\LoggerInterface;

class RecordOrderEvents implements ObserverInterface
{
    /**
     * @var EventRecorder
     */
    private EventRecorder $eventRecorder;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param EventRecorder $eventRecorder
     * @param LoggerInterface $logger
     */
    public function __construct(EventRecorder $eventRecorder, LoggerInterface $logger)
    {
        $this->eventRecorder = $eventRecorder;
        $this->logger = $logger;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $name = (string) $event->getName();

        try {
            // Model save events carry the saved model as "data_object" too.
            $subject = $event->getDataObject();

            if ($subject instanceof Order) {
                $this->eventRecorder->recordOrderSaved($subject, $name);
            } elseif ($subject instanceof Creditmemo) {
                $this->eventRecorder->recordCreditmemoSaved($subject, $name);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                '[Montu Affiliate] Error recording an event from ' . $name . ' (suppressed): ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
