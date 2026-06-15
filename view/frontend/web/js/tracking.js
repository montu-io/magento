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
                return decodeURIComponent(cookie.substring(nameEQ.length));
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
            console.warn('[Montu Affiliate] No configuration found');
            return;
        }

        var affiliateCode = getUrlParameter(config.urlParameter);

        if (affiliateCode) {
            // Check if already have the same code
            var existingCode = getCookie(config.cookieName);

            if (existingCode !== affiliateCode) {
                // Save affiliate code to cookie
                setCookie(config.cookieName, affiliateCode, config.cookieLifetimeDays);

                // Log for debugging
                if (typeof console !== 'undefined' && console.log) {
                    console.log('[Montu Affiliate] Captured affiliate code:', affiliateCode);
                }
            }
        }
    }

    // Initialize when DOM is ready
    function onReady() {
        var config = window.montuAffiliateConfig;
        init(config);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onReady);
    } else {
        onReady();
    }
})();
