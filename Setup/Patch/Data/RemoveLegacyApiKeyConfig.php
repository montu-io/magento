<?php
/**
 * Montu Affiliate Tracking Extension - Remove Legacy API Key Config
 *
 * Requests used to carry the shared secret in an X-Montu-Api-Key header, stored
 * at montu_affiliate/webhook/api_key. They are now signed with a key id +
 * signing secret pair (webhook/key_id, webhook/signing_secret) and the secret
 * never travels, so the old encrypted value is dead config: delete it on
 * setup:upgrade instead of leaving a stale secret in core_config_data.
 *
 * Only this module's own config path is touched, at every scope.
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class RemoveLegacyApiKeyConfig implements DataPatchInterface
{
    private const LEGACY_PATH = 'montu_affiliate/webhook/api_key';

    /**
     * @var ModuleDataSetupInterface
     */
    private ModuleDataSetupInterface $moduleDataSetup;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(ModuleDataSetupInterface $moduleDataSetup)
    {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    /**
     * Delete the legacy API key value from every scope
     *
     * @return $this
     */
    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->delete(
            $this->moduleDataSetup->getTable('core_config_data'),
            ['path = ?' => self::LEGACY_PATH]
        );

        return $this;
    }

    /**
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @return string[]
     */
    public function getAliases(): array
    {
        return [];
    }
}
