<?php
/**
 * Montu Affiliate Tracking Extension - Referral Link
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model;

class ReferralLink
{
    /**
     * Allowed format: the slug of an ambassador's link (alphanumeric, max 64 chars).
     * The D modifier keeps a trailing newline from passing.
     */
    private const PATTERN = '/^[A-Za-z0-9]{1,64}$/D';

    /**
     * Decode a raw cookie value (the frontend script URL-encodes it) and
     * validate it
     *
     * @param string|null $raw
     * @return string|null The link slug, or null when empty or malformed
     */
    public static function fromCookie(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $link = urldecode($raw);

        return preg_match(self::PATTERN, $link) === 1 ? $link : null;
    }
}
