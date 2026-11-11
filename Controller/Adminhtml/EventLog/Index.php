<?php
/**
 * Montu Affiliate Tracking Extension - Admin Event Log Page
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Controller\Adminhtml\EventLog;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Montu_Affiliate::event_log';

    /**
     * @var PageFactory
     */
    private PageFactory $pageFactory;

    /**
     * @param Context $context
     * @param PageFactory $pageFactory
     */
    public function __construct(Context $context, PageFactory $pageFactory)
    {
        parent::__construct($context);
        $this->pageFactory = $pageFactory;
    }

    /**
     * Render the event log grid
     *
     * @return Page
     */
    public function execute(): Page
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Montu_Affiliate::event_log');
        $page->getConfig()->getTitle()->prepend(__('Montu Affiliate Event Log'));

        return $page;
    }
}
