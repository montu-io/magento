/**
 * Montu Affiliate Tracking Extension - Swatch Preselect
 *
 * Native Magento listing swatches do not preselect a specific child variant, so
 * after the swatch widgets render we apply the Montu-recommended selection by
 * "clicking" the matching swatch option(s) on each card.
 *
 * Reads a productId => { attributeId: valueIndex } map from the
 * #montu-storefront-selections JSON block and, for each card, selects the
 * recommended option in every configurable attribute.
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */
(function () {
    'use strict';

    var MAX_TRIES = 25;
    var INTERVAL_MS = 150;

    /**
     * Read the recommended selections map.
     * @returns {Object}
     */
    function readSelections() {
        var el = document.getElementById('montu-storefront-selections');
        if (!el) {
            return {};
        }
        try {
            return JSON.parse(el.textContent || '{}');
        } catch (e) {
            return {};
        }
    }

    /**
     * Apply the recommended selection to a single card once its swatches exist.
     * @param {Element} card
     * @param {Object} selection attributeId => valueIndex
     * @returns {boolean} true when applied (or nothing to do)
     */
    function applyToCard(card, selection) {
        var attributeIds = Object.keys(selection);
        if (!attributeIds.length) {
            return true;
        }

        var swatchAttributes = card.querySelectorAll('.swatch-attribute');
        if (!swatchAttributes.length) {
            return false; // swatches not rendered yet
        }

        var appliedAny = false;
        attributeIds.forEach(function (attributeId) {
            var valueIndex = selection[attributeId];
            var attr = card.querySelector('.swatch-attribute[data-attribute-id="' + attributeId + '"]');
            if (!attr) {
                return;
            }
            var option = attr.querySelector('.swatch-option[data-option-id="' + valueIndex + '"]');
            if (option && !option.classList.contains('selected')) {
                option.click();
                appliedAny = true;
            }
        });

        return appliedAny || true;
    }

    /**
     * Poll until each card's swatches are ready, then apply the selection.
     */
    function init() {
        var selections = readSelections();
        if (!selections || !Object.keys(selections).length) {
            return;
        }

        var cards = Array.prototype.slice.call(
            document.querySelectorAll('[data-montu-product-id]')
        );

        cards.forEach(function (card) {
            var productId = card.getAttribute('data-montu-product-id');
            var selection = selections[productId];
            if (!selection) {
                return;
            }

            var tries = 0;
            var timer = setInterval(function () {
                tries++;
                var done = applyToCard(card, selection);
                if (done || tries >= MAX_TRIES) {
                    clearInterval(timer);
                }
            }, INTERVAL_MS);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
