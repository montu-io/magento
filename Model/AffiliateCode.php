<?php
/**
 * Montu Affiliate Tracking Extension - Affiliate Code
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model;

class AffiliateCode
{
    /**
     * Allowed format: alphanumeric, hyphens, underscores, max 100 chars
     */
    private const PATTERN = '/^[a-zA-Z0-9_\-]{1,100}$/';

    /**
     * Decode a raw cookie value (the frontend script URL-encodes it) and
     * validate it
     *
     * @param string|null $raw
     * @return string|null The code, or null when empty or malformed
     */
    public static function fromCookie(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $code = urldecode($raw);

        return preg_match(self::PATTERN, $code) === 1 ? $code : null;
    }
}
