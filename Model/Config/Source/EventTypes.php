<?php
/**
 * Montu Affiliate Tracking Extension - Event Type Source
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Montu\Affiliate\Model\EventType;

/**
 * Event types, for the Event Log filter
 */
class EventTypes implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        return array_map(
            static fn (string $type): array => ['value' => $type, 'label' => $type],
            EventType::all()
        );
    }
}
