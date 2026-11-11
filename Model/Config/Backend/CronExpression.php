<?php
/**
 * Montu Affiliate Tracking Extension - Cron Expression Backend Model
 *
 * Rejects an invalid delivery schedule when the setting is saved: an invalid
 * expression would otherwise make Magento fail to schedule the delivery job.
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model\Config\Backend;

use Magento\Cron\Model\ScheduleFactory;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

class CronExpression extends Value
{
    /**
     * One field: *, a value or name, a range, optionally with a step, in a comma list
     */
    private const FIELD_PATTERN = '#^(\*|[0-9a-z]+(-[0-9a-z]+)?)(/[0-9]+)?(,(\*|[0-9a-z]+(-[0-9a-z]+)?)(/[0-9]+)?)*$#i';

    /**
     * @var ScheduleFactory
     */
    private ScheduleFactory $scheduleFactory;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param ScheduleFactory $scheduleFactory
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        ScheduleFactory $scheduleFactory,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->scheduleFactory = $scheduleFactory;
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $expression = trim((string) $this->getValue());

        try {
            // Same check Magento runs on every job schedule: 5 or 6 fields.
            $this->scheduleFactory->create()->setCronExpr($expression);
        } catch (LocalizedException $e) {
            throw new LocalizedException(
                __('The delivery schedule "%1" is not a valid cron expression. Example: */5 * * * *', $expression)
            );
        }

        foreach (preg_split('#\s+#', $expression, -1, PREG_SPLIT_NO_EMPTY) as $field) {
            if (preg_match(self::FIELD_PATTERN, $field) !== 1) {
                throw new LocalizedException(
                    __(
                        'The delivery schedule "%1" is not a valid cron expression (bad field "%2"). '
                        . 'Example: */5 * * * *',
                        $expression,
                        $field
                    )
                );
            }
        }

        $this->setValue($expression);

        return parent::beforeSave();
    }
}
