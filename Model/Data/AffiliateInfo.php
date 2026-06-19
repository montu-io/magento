<?php
/**
 * Montu Affiliate Tracking Extension - Affiliate Info
 *
 * The public identity of the affiliate whose storefront is being viewed
 * (name + profile picture), as returned by Montu in the recommendations payload.
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model\Data;

class AffiliateInfo
{
    private string $firstName;
    private string $lastName;
    private ?string $profilePicture;

    /**
     * @param string $firstName
     * @param string $lastName
     * @param string|null $profilePicture
     */
    public function __construct(
        string $firstName,
        string $lastName,
        ?string $profilePicture
    ) {
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->profilePicture = $profilePicture;
    }

    /**
     * @return string
     */
    public function getFirstName(): string
    {
        return $this->firstName;
    }

    /**
     * @return string
     */
    public function getLastName(): string
    {
        return $this->lastName;
    }

    /**
     * Full display name, e.g. "Jose Pérez" (trimmed; may be empty).
     *
     * @return string
     */
    public function getFullName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }

    /**
     * Absolute URL of the affiliate's profile picture, or null.
     *
     * @return string|null
     */
    public function getProfilePicture(): ?string
    {
        return $this->profilePicture;
    }
}
