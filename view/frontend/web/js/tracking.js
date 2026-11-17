/**
 * Montu Affiliate Tracking Extension
 *
 * Captures the affiliate code (and the ambassador's referral link, "ml") from the
 * URL parameters and stores them in cookies. The link only means something next
 * to the code, and both are replaced together (last click wins).
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */
(function () {
    'use strict';

    // Captured now: document.currentScript is only set while the script runs.
    var currentScript = document.currentScript;

    // Same format rule as Model/AffiliateCode.php.
    var CODE_PATTERN = /^[A-Za-z0-9_-]{1,100}$/;

    // Same format rule as Model/ReferralLink.php.
    var LINK_PATTERN = /^[A-Za-z0-9]{1,64}$/;

    /**
     * Read the configuration from the data attribute of this script tag
     * (the template no longer renders an inline script, which a strict CSP blocks).
     * @returns {Object|null}
     */
    function readConfig() {
        var script = currentScript || document.querySelector('script[data-montu-config]');
        var raw = script ? script.getAttribute('data-montu-config') : null;

        if (raw) {
            try {
                return JSON.parse(raw);
            } catch (e) {
                // fall through to the legacy global
            }
        }

        return window.montuAffiliateConfig || null;
    }

    /**
     * Get URL parameter value
     * @param {string} name - Parameter name
     * @returns {string|null}
     */
    function getUrlParameter(name) {
        var urlParams = new URLSearchParams(window.location.search);
        return urlParams.get(name);
    }

    /**
     * Set cookie with expiration
     * @param {string} name - Cookie name
     * @param {string} value - Cookie value
     * @param {number} days - Expiration in days
     */
    function setCookie(name, value, days) {
        var expires = '';

        if (days) {
            var date = new Date();
            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
            expires = '; expires=' + date.toUTCString();
        }

        // Set cookie with secure flags
        var cookieString = name + '=' + encodeURIComponent(value) + expires + '; path=/; SameSite=Lax';

        // Add Secure flag if on HTTPS
        if (window.location.protocol === 'https:') {
            cookieString += '; Secure';
        }

        document.cookie = cookieString;
    }

    /**
     * Delete a cookie (same path and flags it was set with)
     * @param {string} name - Cookie name
     */
    function deleteCookie(name) {
        var cookieString = name + '=; Max-Age=0; path=/; SameSite=Lax';

        if (window.location.protocol === 'https:') {
            cookieString += '; Secure';
        }

        document.cookie = cookieString;
    }

    /**
     * Get cookie value
     * @param {string} name - Cookie name
     * @returns {string|null}
     */
    function getCookie(name) {
        var nameEQ = name + '=';
        var cookies = document.cookie.split(';');

        for (var i = 0; i < cookies.length; i++) {
            var cookie = cookies[i].trim();

            if (cookie.indexOf(nameEQ) === 0) {
                try {
                    return decodeURIComponent(cookie.substring(nameEQ.length));
                } catch (e) {
                    // Malformed cookie: treat it as absent.
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * Initialize affiliate tracking
     * @param {Object} config - Configuration object
     */
    function init(config) {
        if (!config) {
            return;
        }

        var affiliateCode = getUrlParameter(config.urlParameter);

        if (!affiliateCode || !CODE_PATTERN.test(affiliateCode)) {
            return;
        }

        // The link is only read next to a valid code; a plain store link has none.
        var referralLink = config.linkParameter ? getUrlParameter(config.linkParameter) : null;
        if (!referralLink || !LINK_PATTERN.test(referralLink)) {
            referralLink = null;
        }

        // A different link under the same code is a new click too.
        var existingCode = getCookie(config.cookieName);
        var existingLink = config.linkCookieName ? getCookie(config.linkCookieName) : null;

        if (existingCode === affiliateCode && existingLink === referralLink) {
            return;
        }

        setCookie(config.cookieName, affiliateCode, config.cookieLifetimeDays);

        if (config.linkCookieName) {
            if (referralLink) {
                setCookie(config.linkCookieName, referralLink, config.cookieLifetimeDays);
            } else {
                deleteCookie(config.linkCookieName);
            }
        }
    }

    // Initialize when DOM is ready. Must never break the page, so errors are ignored.
    function onReady() {
        try {
            init(readConfig());
        } catch (e) {
            // Ignore: affiliate capture is best effort.
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onReady);
    } else {
        onReady();
    }
})();
