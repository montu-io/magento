/**
 * Montu Affiliate Tracking Extension - Storefront White-Label Theming
 *
 * Makes the affiliate storefront adopt the MERCHANT store's look automatically,
 * instead of any Montu brand colour. It samples the live, theme-compiled colours
 * of the store's own primary button and link, then exposes them as CSS custom
 * properties on the storefront root:
 *
 *   --montu-accent          the store's primary action colour
 *   --montu-accent-contrast readable text colour on that accent
 *   --montu-accent-soft     a translucent tint of the accent (hover / active)
 *
 * Because the values are read from the active theme at runtime, the page follows
 * whatever theme the merchant runs (Luma, custom, or a re-skinned store) with no
 * per-merchant configuration. Runs as an external file so it is CSP-safe.
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */
(function () {
    'use strict';

    /**
     * Parse "rgb(r, g, b)" / "rgba(r, g, b, a)" into [r, g, b].
     * @param {string} value
     * @returns {number[]|null}
     */
    function parseRgb(value) {
        var match = (value || '').match(/(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/);
        return match ? [+match[1], +match[2], +match[3]] : null;
    }

    /**
     * True when a colour is missing or fully transparent. Only a 4-component
     * rgba() with alpha 0 counts — note that rgb(0, 0, 0) (opaque black) must
     * NOT be treated as transparent, so we match the alpha channel explicitly
     * rather than "the last number before the paren".
     * @param {string} value
     * @returns {boolean}
     */
    function isTransparent(value) {
        if (!value) {
            return true;
        }
        if (value === 'transparent') {
            return true;
        }
        var match = value.match(/rgba\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*,\s*([0-9.]+)\s*\)/);
        return !!match && parseFloat(match[1]) === 0;
    }

    /**
     * Read the store's primary action colour (and its text colour), falling back
     * to the link colour, so the accent always matches the live theme.
     * @param {Element} root
     * @returns {{accent: string, contrast: string|null}|null}
     */
    function sampleStoreColors(root) {
        var button = root.querySelector('.action.primary, .action.tocart')
            || document.querySelector('.action.primary, .action.tocart');

        if (button) {
            var style = window.getComputedStyle(button);
            if (!isTransparent(style.backgroundColor)) {
                return { accent: style.backgroundColor, contrast: style.color };
            }
        }

        // Sample a link from the store chrome (nav/footer), never from inside
        // the storefront itself, so we don't read back our own accent variable.
        var link = document.querySelector('.nav-sections a, .footer.content a, .page-header a');
        if (link) {
            var linkColor = window.getComputedStyle(link).color;
            if (!isTransparent(linkColor)) {
                return { accent: linkColor, contrast: '#ffffff' };
            }
        }

        return null;
    }

    /**
     * Apply the sampled colours as CSS custom properties on the storefront root.
     */
    function apply() {
        var root = document.querySelector('.montu-storefront');
        if (!root) {
            return;
        }

        var colors = sampleStoreColors(root);
        if (!colors) {
            return;
        }

        var rgb = parseRgb(colors.accent);
        root.style.setProperty('--montu-accent', colors.accent);
        if (rgb) {
            root.style.setProperty(
                '--montu-accent-soft',
                'rgba(' + rgb[0] + ', ' + rgb[1] + ', ' + rgb[2] + ', 0.12)'
            );
        }
        if (colors.contrast) {
            root.style.setProperty('--montu-accent-contrast', colors.contrast);
        }

        root.setAttribute('data-montu-themed', 'true');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', apply);
    } else {
        apply();
    }
})();
