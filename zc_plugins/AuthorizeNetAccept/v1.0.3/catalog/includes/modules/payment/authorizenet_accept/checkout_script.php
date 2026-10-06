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
 * Wallet tokens (since 1.0.1)
 * ---------------------------
 * An add-on (Apple Pay, Google Pay, anything the module's descriptor
 * whitelist allows) hands its token to window.<code>.setWalletToken(). The
 * script writes it into the same hidden carriers, selects the module, and
 * from then on lets the click through untouched: no card validation, no
 * Accept.js call. The token is also kept in sessionStorage for a few minutes
 * so it survives One Page Checkout re-rendering the payment block, and it is
 * forgotten when the customer edits the card fields, picks another payment
 * method, or the form is submitted. Each render announces itself with a
 * "<code>:ready" event on the document, carrying the API, so an add-on can
 * put its button back after a re-render.
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
    var acceptDescriptor = cfg.acceptDescriptor || 'COMMON.ACCEPT.INAPP.PAYMENT';
    var walletKey = cfg.walletStorageKey || (code + '_wallet');
    var walletTtl = cfg.walletTtlMs || 900000;

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

    function carrier(name) {
        var field = formField(code + '_' + name);
        return field ? String(field.value || '') : '';
    }

    function clearToken() {
        setHidden(code + '_descriptor', '');
        setHidden(code + '_value', '');
        state.tokenizedAt = 0;
        forgetWallet();
    }

    // ---- wallet tokens: Apple Pay, Google Pay, or any add-on the module allows ----

    function walletTokenPresent() {
        var descriptor = carrier('descriptor');
        return descriptor !== '' && descriptor !== acceptDescriptor && carrier('value') !== '';
    }

    function storeWallet(token) {
        try { window.sessionStorage.setItem(walletKey, JSON.stringify(token)); } catch (e) {}
    }

    function forgetWallet() {
        try { window.sessionStorage.removeItem(walletKey); } catch (e) {}
    }

    function readStoredWallet() {
        var token;
        try {
            var raw = window.sessionStorage.getItem(walletKey);
            if (!raw) { return null; }
            token = JSON.parse(raw);
        } catch (e) {
            return null;
        }
        if (!token || !token.descriptor || !token.value || token.descriptor === acceptDescriptor) {
            return null;
        }
        // A wallet token is for one total and a few minutes; anything else is dropped.
        if (!token.at || (Date.now() - token.at) > walletTtl || String(token.total) !== String(cfg.total)) {
            forgetWallet();
            return null;
        }
        return token;
    }

    function applyWallet(token) {
        setHidden(code + '_descriptor', token.descriptor);
        setHidden(code + '_value', token.value);
        setHidden(code + '_brand', token.brand || '');
        setHidden(code + '_last4', token.last4 || '');
        setHidden(code + '_expires', token.expires || '');
        state.tokenizedAt = 0;
        showMessage('');
    }

    function selectModule() {
        var radio = byId('pmt-' + code);
        if (!radio || radio.type === 'hidden' || radio.checked === true) {
            return;
        }
        // A real click, so One Page Checkout's own handler records the choice.
        radio.click();
    }

    function setWalletToken(descriptor, value, meta) {
        meta = meta || {};
        if (!descriptor || !value || String(descriptor) === acceptDescriptor) {
            return false;
        }
        var token = {
            descriptor: String(descriptor),
            value: String(value),
            brand: String(meta.brand || ''),
            last4: String(meta.last4 || ''),
            expires: String(meta.expires || ''),
            total: cfg.total,
            at: Date.now()
        };
        applyWallet(token);
        storeWallet(token);
        if (meta.select !== false) {
            selectModule();
        }
        return true;
    }

    function clearWalletToken() {
        forgetWallet();
        if (walletTokenPresent()) {
            setHidden(code + '_descriptor', '');
            setHidden(code + '_value', '');
            setHidden(code + '_brand', '');
            setHidden(code + '_last4', '');
            setHidden(code + '_expires', '');
        }
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
            forgetWallet();
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
        if (fresh || walletTokenPresent()) {
            return; // the nonce, or a wallet token an add-on supplied, is in place: let Zen Cart submit
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

    // Any edit to the card details invalidates the nonce (and drops a wallet token).
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

    // Choosing another payment method drops a waiting wallet token.
    document.addEventListener('change', function (event) {
        var target = event.target;
        if (target && target.name === 'payment' && target.value !== code) {
            clearWalletToken();
        }
    }, true);

    // Once the order is submitted the token is spent; never restore it into a later render.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (form && (form.name === 'checkout_payment' || form.name === 'checkout_confirmation')) {
            forgetWallet();
        }
    }, true);

    // The public surface for add-ons, one object per module code.
    var api = {
        setWalletToken: setWalletToken,
        clearWalletToken: clearWalletToken,
        hasWalletToken: walletTokenPresent,
        select: selectModule,
        config: function () {
            return { code: code, total: cfg.total, currency: cfg.currency, sandbox: !!cfg.sandbox };
        }
    };
    window[code] = api;

    // A wallet token handed over before One Page Checkout re-rendered this block comes back.
    var stored = readStoredWallet();
    if (stored) {
        applyWallet(stored);
    }
    try {
        document.dispatchEvent(new CustomEvent(code + ':ready', { detail: api }));
    } catch (e) {}
})();
</script>
