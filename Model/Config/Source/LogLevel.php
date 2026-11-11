<?php
/**
 * Montu Affiliate Tracking Extension - Log Level Source
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Montu\Affiliate\Model\Config;

class LogLevel implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        return array_map(
            static fn (string $level): array => ['value' => $level, 'label' => ucfirst($level)],
            Config::LOG_LEVELS
        );
    }
}
