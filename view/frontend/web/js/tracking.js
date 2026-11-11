/**
 * Montu Affiliate Tracking Extension
 *
 * Captures affiliate code from URL parameter and stores in cookie.
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

        if (affiliateCode && CODE_PATTERN.test(affiliateCode)) {
            // Check if already have the same code
            var existingCode = getCookie(config.cookieName);

            if (existingCode !== affiliateCode) {
                // Save affiliate code to cookie
                setCookie(config.cookieName, affiliateCode, config.cookieLifetimeDays);
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
