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
use Montu\Affiliate\Model\Config;
use Montu\Affiliate\Model\ResourceModel\WebhookLog\CollectionFactory;
use Montu\Affiliate\Model\WebhookLog;
use Montu\Affiliate\Model\WebhookLogRepository;

/**
 * Put the selected events back in the delivery queue: status pending, attempts
 * reset, attempt limit = the current setting. The payload is never touched.
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
     * @param Context $context
     * @param Filter $filter
     * @param CollectionFactory $collectionFactory
     * @param WebhookLogRepository $webhookLogRepository
     * @param Config $config
     */
    public function __construct(
        Context $context,
        Filter $filter,
        CollectionFactory $collectionFactory,
        WebhookLogRepository $webhookLogRepository,
        Config $config
    ) {
        parent::__construct($context);
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
        $this->webhookLogRepository = $webhookLogRepository;
        $this->config = $config;
    }

    /**
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $requeued = 0;
        $skipped = 0;

        try {
            $collection = $this->filter->getCollection($this->collectionFactory->create());

            /** @var WebhookLog $log */
            foreach ($collection as $log) {
                // An event being delivered right now must not be queued a second time.
                if ($log->getStatus() === WebhookLog::STATUS_PROCESSING) {
                    $skipped++;
                    continue;
                }
                $log->requeue($this->config->getMaxRetries($log->getStoreId()));
                $this->webhookLogRepository->save($log);
                $requeued++;
            }

            if ($requeued > 0) {
                $this->messageManager->addSuccessMessage(__('%1 event(s) were requeued.', $requeued));
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
