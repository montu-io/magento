<?php
/**
 * Montu Affiliate Tracking Extension - Module Log Handler
 *
 * Writes var/log/montu_affiliate.log, dropping records below the minimum level
 * set in Stores > Configuration > Montu > Queue & Logging.
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Logger\Handler;

use Magento\Framework\Filesystem\DriverInterface;
use Magento\Framework\Logger\Handler\Base;
use Montu\Affiliate\Model\Config;

class System extends Base
{
    /**
     * Monolog level numbers (identical in Monolog 2 and 3)
     */
    private const LEVELS = ['debug' => 100, 'info' => 200, 'warning' => 300, 'error' => 400];

    /**
     * @var string
     */
    protected $fileName = '/var/log/montu_affiliate.log';

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var int|null
     */
    private ?int $minLevel = null;

    /**
     * @param DriverInterface $filesystem
     * @param Config $config
     * @param string|null $filePath
     * @param string|null $fileName
     */
    public function __construct(
        DriverInterface $filesystem,
        Config $config,
        ?string $filePath = null,
        ?string $fileName = null
    ) {
        $this->config = $config;
        parent::__construct($filesystem, $filePath, $fileName);
    }

    /**
     * Handle only records at or above the configured level
     *
     * The parameter is untyped on purpose: Monolog 2 passes an array and
     * Monolog 3 a LogRecord.
     *
     * @param array|\Monolog\LogRecord $record
     * @return bool
     */
    public function isHandling($record): bool
    {
        $level = is_array($record) ? $record['level'] : $record->level->value;

        return $level >= $this->getMinLevel();
    }

    /**
     * Minimum level number, read from the settings once per request
     *
     * @return int
     */
    private function getMinLevel(): int
    {
        if ($this->minLevel === null) {
            try {
                $this->minLevel = self::LEVELS[$this->config->getLogLevel()];
            } catch (\Throwable $e) {
                $this->minLevel = self::LEVELS['warning'];
            }
        }

        return $this->minLevel;
    }
}
