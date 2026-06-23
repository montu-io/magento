<?php
/**
 * Montu Affiliate Tracking Extension - Storefront Theme Mode Source
 *
 * How the affiliate storefront decides its colours/typography:
 *
 *   - auto   : sample the live merchant theme at runtime (zero config — the page
 *              follows whatever theme the store runs). Any colours configured
 *              below are used as a seed/fallback until sampling resolves.
 *   - manual : use exactly the colours/typography configured in admin; no runtime
 *              sampling. Unset fields fall back to the neutral defaults.
 *   - off    : no theming — neutral built-in defaults only (no sampling, admin
 *              colours ignored).
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ThemeMode implements OptionSourceInterface
{
    public const AUTO = 'auto';
    public const MANUAL = 'manual';
    public const OFF = 'off';

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::AUTO, 'label' => __('Auto — match the store theme automatically')],
            ['value' => self::MANUAL, 'label' => __('Manual — use the colours configured below')],
            ['value' => self::OFF, 'label' => __('Off — neutral default styling')],
        ];
    }
}
