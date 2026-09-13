<?php
/**
 * Authorize.Net Accept.js Payments -- the checkout script.
 *
 * Emitted inside the payment block by authorizenet_accept::checkoutScript(),
 * which sets $anaScriptConfig. Everything the script needs (keys, the SDK URL
 * for the current mode, the field messages) arrives through that one object,
 * so this file has no PHP in the JavaScript itself beyond the json_encode.
 *
 * How the hand-off works
 * ----------------------
 * The card number and security code inputs have NO name attribute, so a form
 * post can never carry them. When the customer clicks Continue (or One Page
 * Checkout's Confirm), a capture-phase click listener runs before anything
 * Zen Cart attached: it validates the card fields, asks Accept.js for a
 * one-time nonce, writes the nonce into the named hidden fields, and only then
 * re-issues the click so Zen Cart's own submit handling runs as usual.
 *
 * The Accept.js SDK is loaded from Authorize.Net on demand. It is the only
 * script that may touch the card fields, and Authorize.Net requires it to be
 * served from their host; there is no local copy by design.
 *
 * @package  AuthorizeNetAccept
 * @license  https://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU Public License V2.0
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}
if (!isset($anaScriptConfig) || !is_array($anaScriptConfig)) {
    return;
}
?>
<script>
(function () {
    'use strict';

    var cfg = <?= json_encode($anaScriptConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var code = cfg.code;
    var state = { pending: false, tokenizedAt: 0 };

    function byId(id) {
        return document.getElementById(id);
    }

    function formField(name) {
        var form = document.forms['checkout_payment'];
        return form ? form.elements[name] : null;
    }

    function isSelected() {
        var radio = byId('pmt-' + code);
        if (!radio) {
            return false;
        }
        // Zen Cart renders a hidden input instead of a radio when this is the only enabled module.
        return radio.type === 'hidden' || radio.checked === true;
    }

    function showMessage(text) {
        var box = byId(code + '-error');
        if (box) {
            box.textContent = text || '';
            box.style.display = text ? 'block' : 'none';
        } else if (text) {
            window.alert(text);
        }
    }

    function digitsOf(value) {
        return String(value || '').replace(/\D/g, '');
    }

    function brandOf(number) {
        if (/^4/.test(number)) { return 'Visa'; }
        if (/^(5[1-5]|2(2[2-9]|[3-6]|7[01]|720))/.test(number)) { return 'Mastercard'; }
        if (/^3[47]/.test(number)) { return 'American Express'; }
        if (/^(6011|65|64[4-9]|622)/.test(number)) { return 'Discover'; }
        if (/^35/.test(number)) { return 'JCB'; }
        if (/^3(0[0-5]|[68])/.test(number)) { return 'Diners Club'; }
        return '';
    }

    function luhnOk(number) {
        var sum = 0, doubleIt = false, i, d;
        for (i = number.length - 1; i >= 0; i--) {
            d = parseInt(number.charAt(i), 10);
            if (doubleIt) {
                d *= 2;
                if (d > 9) { d -= 9; }
            }
            sum += d;
            doubleIt = !doubleIt;
        }
        return number.length > 0 && (sum % 10) === 0;
    }

    function readCard() {
        var owner = formField(code + '_owner');
        var number = byId(code + '-cc-number');
        var month = byId(code + '-cc-expires-month');
        var year = byId(code + '-cc-expires-year');
        var cvv = byId(code + '-cc-cvv');
        return {
            owner: owner ? String(owner.value || '').trim() : '',
            number: number ? digitsOf(number.value) : '',
            month: month ? String(month.value || '') : '',
            year: year ? String(year.value || '') : '',
            cvv: cvv ? digitsOf(cvv.value) : ''
        };
    }

    function validate(card) {
        if (card.owner.replace(/\s+/g, '').length < cfg.minOwner) { return cfg.text.owner; }
        if (card.number.length < 13 || card.number.length > 19 || !luhnOk(card.number)) { return cfg.text.number; }
        var now = new Date();
        var mm = parseInt(card.month, 10);
        var yy = parseInt(card.year, 10);
        var fullYear = (yy < 100) ? 2000 + yy : yy;
        if (isNaN(mm) || mm < 1 || mm > 12 || isNaN(fullYear)
            || fullYear < now.getFullYear()
            || (fullYear === now.getFullYear() && mm < now.getMonth() + 1)) {
            return cfg.text.expires;
        }
        if (cfg.requireCvv && (card.cvv.length < 3 || card.cvv.length > 4)) { return cfg.text.cvv; }
        return '';
    }

    function setHidden(name, value) {
        var field = formField(name);
        if (field) { field.value = value; }
    }

    function clearToken() {
        setHidden(code + '_descriptor', '');
        setHidden(code + '_value', '');
        state.tokenizedAt = 0;
    }

    function loadSdk(onReady, onFail) {
        if (window.Accept && typeof window.Accept.dispatchData === 'function') {
            onReady();
            return;
        }
        var marker = 'data-' + code + '-sdk';
        var tag = document.querySelector('script[' + marker + ']');
        if (!tag) {
            tag = document.createElement('script');
            tag.type = 'text/javascript';
            tag.charset = 'utf-8';
            tag.src = cfg.scriptUrl;
            tag.setAttribute(marker, '1');
            tag.addEventListener('error', function () { onFail(); });
            document.head.appendChild(tag);
        }
        var waited = 0;
        var timer = window.setInterval(function () {
            waited += 100;
            if (window.Accept && typeof window.Accept.dispatchData === 'function') {
                window.clearInterval(timer);
                onReady();
            } else if (waited >= 15000) {
                window.clearInterval(timer);
                onFail();
            }
        }, 100);
    }

    function tokenize(card, done) {
        var secureData = {
            authData: { clientKey: cfg.clientKey, apiLoginID: cfg.apiLoginID },
            cardData: {
                cardNumber: card.number,
                month: ('0' + card.month).slice(-2),
                year: card.year,
                fullName: card.owner.slice(0, 64)
            }
        };
        if (card.cvv) { secureData.cardData.cardCode = card.cvv; }
        if (cfg.zip) { secureData.cardData.zip = String(cfg.zip).slice(0, 20); }

        window.Accept.dispatchData(secureData, function (response) {
            var messages = [], i;
            if (!response || !response.messages || response.messages.resultCode === 'Error' || !response.opaqueData) {
                if (response && response.messages && response.messages.message) {
                    for (i = 0; i < response.messages.message.length; i++) {
                        messages.push(response.messages.message[i].text);
                    }
                }
                showMessage(messages.length ? messages.join(' ') : cfg.text.failed);
                done(false);
                return;
            }
            setHidden(code + '_descriptor', response.opaqueData.dataDescriptor);
            setHidden(code + '_value', response.opaqueData.dataValue);
            setHidden(code + '_brand', brandOf(card.number));
            setHidden(code + '_last4', card.number.slice(-4));
            setHidden(code + '_expires', ('0' + card.month).slice(-2) + String(card.year).slice(-2));
            state.tokenizedAt = Date.now();
            showMessage('');
            done(true);
        });
    }

    function submitControlFor(target) {
        if (!target || typeof target.closest !== 'function') {
            return null;
        }
        return target.closest(cfg.submitSelector);
    }

    // Capture phase: this runs before the button's own onclick, before jQuery's
    // delegated handlers, and before the form's submit event.
    document.addEventListener('click', function (event) {
        var control = submitControlFor(event.target);
        if (!control || !isSelected()) {
            return;
        }
        var fresh = state.tokenizedAt > 0 && (Date.now() - state.tokenizedAt) < cfg.tokenTtlMs;
        if (fresh) {
            return; // second pass: the nonce is in place, let Zen Cart submit
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        if (state.pending) {
            return;
        }
        var card = readCard();
        var problem = validate(card);
        if (problem) {
            showMessage(problem);
            return;
        }
        state.pending = true;
        showMessage(cfg.text.working);
        loadSdk(function () {
            tokenize(card, function (ok) {
                state.pending = false;
                if (ok) {
                    control.click();
                }
            });
        }, function () {
            state.pending = false;
            showMessage(cfg.text.loadFailed);
        });
    }, true);

    // Any edit to the card details invalidates the nonce.
    ['-cc-number', '-cc-expires-month', '-cc-expires-year', '-cc-cvv'].forEach(function (suffix) {
        var el = byId(code + suffix);
        if (el) {
            el.addEventListener('input', clearToken);
            el.addEventListener('change', clearToken);
        }
    });
    var ownerField = formField(code + '_owner');
    if (ownerField) {
        ownerField.addEventListener('input', clearToken);
    }
})();
</script>
