<?php
/**
 * Montu Affiliate Tracking Extension - Storefront Pretty-URL Router
 *
 * Matches the public affiliate storefront URL (default: /storefront/{code})
 * and forwards it to the internal montu_affiliate/storefront/index action,
 * passing the affiliate code as a request parameter.
 *
 * The URL prefix ("storefront") is admin-configurable via
 * montu_affiliate/storefront/route_path.
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Controller;

use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Action\Forward;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;
use Montu\Affiliate\Model\Config;
use Psr\Log\LoggerInterface;

class Router implements RouterInterface
{
    /**
     * Allowed affiliate code format (mirrors the cookie capture validation).
     */
    private const CODE_PATTERN = '/^[a-zA-Z0-9_\-]{1,100}$/';

    private ActionFactory $actionFactory;
    private Config $config;
    private LoggerInterface $logger;

    /**
     * @param ActionFactory $actionFactory
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        ActionFactory $actionFactory,
        Config $config,
        LoggerInterface $logger
    ) {
        $this->actionFactory = $actionFactory;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Match the affiliate storefront URL and forward to the controller.
     *
     * MUST never throw - a failing router would break front-end routing for
     * the whole store. On any problem we return null and let standard routing
     * (ultimately the 404 handler) take over.
     *
     * @param RequestInterface $request
     * @return ActionInterface|null
     */
    public function match(RequestInterface $request): ?ActionInterface
    {
        try {
            if (!$this->config->isEnabled() || !$this->config->isStorefrontEnabled()) {
                return null;
            }

            $prefix = trim($this->config->getStorefrontRoutePath(), '/');
            if ($prefix === '') {
                return null;
            }

            $pathInfo = trim((string) $request->getPathInfo(), '/');

            // Expect "<prefix>/<code>" (a single trailing segment after the prefix)
            $pattern = '#^' . preg_quote($prefix, '#') . '/([^/]+)/?$#';
            if (!preg_match($pattern, $pathInfo, $matches)) {
                return null;
            }

            $code = rawurldecode($matches[1]);

            // Reject malformed codes early - don't even route them
            if (!preg_match(self::CODE_PATTERN, $code)) {
                $this->logger->debug(
                    '[Montu Affiliate] Storefront router ignored malformed code',
                    ['raw' => substr($matches[1], 0, 120)]
                );
                return null;
            }

            $request->setModuleName('montu_affiliate')
                ->setControllerName('storefront')
                ->setActionName('index')
                ->setParam('code', $code);

            return $this->actionFactory->create(Forward::class);
        } catch (\Exception $e) {
            $this->logger->error(
                '[Montu Affiliate] Storefront router error (suppressed): ' . $e->getMessage()
            );
            return null;
        }
    }
}
