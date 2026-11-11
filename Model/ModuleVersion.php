<?php
/**
 * Montu Affiliate Tracking Extension - Module Version
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model;

use Magento\Framework\Module\PackageInfo;

/**
 * The installed version of the module, read from Composer metadata
 */
class ModuleVersion
{
    private const MODULE_NAME = 'Montu_Affiliate';
    private const PACKAGE_NAME = 'montu/module-affiliate';

    /**
     * @var PackageInfo
     */
    private PackageInfo $packageInfo;

    /**
     * @var string|null
     */
    private ?string $version = null;

    /**
     * @param PackageInfo $packageInfo
     */
    public function __construct(PackageInfo $packageInfo)
    {
        $this->packageInfo = $packageInfo;
    }

    /**
     * Version such as "2.0.0"; "unknown" when Composer cannot tell
     *
     * @return string
     */
    public function get(): string
    {
        if ($this->version === null) {
            $this->version = ltrim($this->detect(), 'vV') ?: 'unknown';
        }

        return $this->version;
    }

    /**
     * @return string
     */
    private function detect(): string
    {
        try {
            $version = (string) $this->packageInfo->getVersion(self::MODULE_NAME);
            if ($version !== '') {
                return $version;
            }

            if (class_exists(\Composer\InstalledVersions::class)
                && \Composer\InstalledVersions::isInstalled(self::PACKAGE_NAME)
            ) {
                return (string) \Composer\InstalledVersions::getPrettyVersion(self::PACKAGE_NAME);
            }
        } catch (\Throwable $e) {
            return '';
        }

        return '';
    }
}
