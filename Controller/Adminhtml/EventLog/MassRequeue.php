<?php
/**
 * Montu Affiliate Tracking Extension - Admin Event Log Mass Requeue
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Controller\Adminhtml\EventLog;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Ui\Component\MassAction\Filter;
use Montu\Affiliate\Model\Catalog\ChangeRecorder;
use Montu\Affiliate\Model\Config;
use Montu\Affiliate\Model\ResourceModel\WebhookLog\CollectionFactory;
use Montu\Affiliate\Model\WebhookLog;
use Montu\Affiliate\Model\WebhookLogRepository;
use Psr\Log\LoggerInterface;

/**
 * Put the selected events back in the delivery queue: status pending, attempts
 * reset, attempt limit = the current setting. Order events and catalog
 * deletions are resent as they are; for other catalog events a new event is
 * queued, built from the current data and sent even if unchanged.
 */
class MassRequeue extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Montu_Affiliate::event_log';

    /**
     * @var Filter
     */
    private Filter $filter;

    /**
     * @var CollectionFactory
     */
    private CollectionFactory $collectionFactory;

    /**
     * @var WebhookLogRepository
     */
    private WebhookLogRepository $webhookLogRepository;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var ChangeRecorder
     */
    private ChangeRecorder $changeRecorder;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Context $context
     * @param Filter $filter
     * @param CollectionFactory $collectionFactory
     * @param WebhookLogRepository $webhookLogRepository
     * @param Config $config
     * @param ChangeRecorder $changeRecorder
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        Filter $filter,
        CollectionFactory $collectionFactory,
        WebhookLogRepository $webhookLogRepository,
        Config $config,
        ChangeRecorder $changeRecorder,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
        $this->webhookLogRepository = $webhookLogRepository;
        $this->config = $config;
        $this->changeRecorder = $changeRecorder;
        $this->logger = $logger;
    }

    /**
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $requeued = 0;
        $skipped = 0;
        $failed = 0;

        try {
            $collection = $this->filter->getCollection($this->collectionFactory->create());

            /** @var WebhookLog $log */
            foreach ($collection as $log) {
                // An event being delivered right now must not be queued a second time.
                if ($log->getStatus() === WebhookLog::STATUS_PROCESSING) {
                    $skipped++;
                    continue;
                }
                try {
                    if ($log->getData('subject_id') !== null && !str_ends_with($log->getEventType(), '.deleted')) {
                        // A catalog event: a new one from the current data (resending an old
                        // snapshot would be ignored by Montu as older than what it has).
                        $this->changeRecorder->resend(
                            $log->getEventType(),
                            (int) $log->getData('subject_id'),
                            $log->getStoreId()
                        );
                    } else {
                        $this->webhookLogRepository->requeue($log, $this->config->getMaxRetries($log->getStoreId()));
                    }
                    $requeued++;
                } catch (\Throwable $e) {
                    // E.g. its store view no longer exists: the other events are still requeued.
                    $failed++;
                    $this->logger->error(
                        '[Montu Affiliate] Could not requeue event ' . $log->getEntityId() . ': ' . $e->getMessage()
                    );
                }
            }

            if ($requeued > 0) {
                $this->messageManager->addSuccessMessage(__('%1 event(s) were requeued.', $requeued));
            }
            if ($failed > 0) {
                $this->messageManager->addErrorMessage(
                    __('%1 event(s) could not be requeued (see var/log/montu_affiliate.log).', $failed)
                );
            }
            if ($skipped > 0) {
                $this->messageManager->addNoticeMessage(
                    __('%1 event(s) are being delivered right now and were not requeued.', $skipped)
                );
            }
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $this->resultRedirectFactory->create()->setPath('*/*/');
    }
}
