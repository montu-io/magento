/**
 * Montu Affiliate Tracking Extension - Storefront White-Label Theming
 *
 * Makes the affiliate storefront adopt the MERCHANT store's look automatically,
 * instead of any Montu brand identity. When the storefront is in "auto" theme
 * mode it samples the live, theme-compiled values of the store's own primary
 * button, body text and font, then exposes them as CSS custom properties on the
 * storefront root:
 *
 *   --montu-accent          the store's primary action colour
 *   --montu-accent-contrast readable text colour on that accent
 *   --montu-accent-soft     a translucent tint of the accent (hover / active)
 *   --montu-text            the store's body text colour
 *   --montu-font            the store's font-family stack
 *
 * Because the values are read from the active theme at runtime, the page follows
 * whatever theme the merchant runs (Luma, custom, or a re-skinned store) with no
 * per-merchant configuration. In "manual"/"off" mode it does nothing, so the
 * admin-configured (or neutral default) styling is respected. Runs as an
 * external file so it is CSP-safe.
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
     * Read the store's body text colour and font-family from the page chrome,
     * so the storefront's typography matches the live theme. Sampled from the
     * main column / body (never from inside the storefront, to avoid reading our
     * own inherited values back).
     * @returns {{text: string|null, font: string|null}}
     */
    function sampleStoreTypography() {
        var source = document.querySelector('.page-main, .column.main, main')
            || document.body;
        if (!source) {
            return { text: null, font: null };
        }
        var style = window.getComputedStyle(source);
        return {
            text: isTransparent(style.color) ? null : style.color,
            font: style.fontFamily || null
        };
    }

    /**
     * Apply the sampled colours/typography as CSS custom properties on the
     * storefront root. Only runs in "auto" theme mode.
     */
    function apply() {
        var root = document.querySelector('.montu-storefront');
        if (!root) {
            return;
        }

        // Respect the admin theme mode: only auto-sample when explicitly "auto"
        // (default). In manual/off mode the server-rendered variables win.
        var mode = root.getAttribute('data-montu-theme-mode') || 'auto';
        if (mode !== 'auto') {
            return;
        }

        var colors = sampleStoreColors(root);
        if (colors) {
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
        }

        var typography = sampleStoreTypography();
        if (typography.text) {
            root.style.setProperty('--montu-text', typography.text);
        }
        if (typography.font) {
            root.style.setProperty('--montu-font', typography.font);
        }

        root.setAttribute('data-montu-themed', 'true');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', apply);
    } else {
        apply();
    }
})();
