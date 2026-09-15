<?php
/**
 * The checkout script: it has to parse, it has to do only what the design
 * says, and it must survive the PHP that emits it.
 *
 * Node is used for a real syntax check when it is on the PATH; otherwise a
 * bracket balance plus the pattern checks below.
 */

require __DIR__ . '/_bootstrap.php';

define('IS_ADMIN_FLAG', false);
$PLUGIN = ana_plugin_dir();
$file = $PLUGIN . '/catalog/includes/modules/payment/authorizenet_accept/checkout_script.php';

section('emission');
$anaScriptConfig = [
    'code' => 'authorizenet_accept',
    'apiLoginID' => 'login',
    'clientKey' => 'key</script><script>alert(1)</script>',
    'scriptUrl' => 'https://jstest.authorize.net/v1/Accept.js',
    'requireCvv' => true,
    'minOwner' => 3,
    'zip' => '78701',
    'tokenTtlMs' => 600000,
    'submitSelector' => '#paymentSubmit input[type="submit"], #opc-order-confirm',
    'acceptDescriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT',
    'walletStorageKey' => 'authorizenet_accept_wallet',
    'walletTtlMs' => 900000,
    'total' => '12.34',
    'currency' => 'USD',
    'sandbox' => true,
    'text' => ['owner' => "It's the owner", 'number' => 'n', 'expires' => 'e', 'cvv' => 'c', 'working' => 'w', 'failed' => 'f', 'loadFailed' => 'l'],
];
ob_start();
require $file;
$out = ob_get_clean();
check('emits one script block', preg_match_all('~<script>~', $out) === 1 && substr_count($out, '</script>') === 1);
check('a hostile config value cannot close the script tag', strpos($out, 'alert(1)</script>') === false && strpos($out, '</script') !== false);
check("an apostrophe in a message is safe", strpos($out, "It\\u0027s") !== false);
check('no config means no script', (static function () use ($file) { unset($GLOBALS['anaScriptConfig']); ob_start(); $anaScriptConfig = null; require $file; return ob_get_clean(); })() === '');

section('the JavaScript itself');
$js = substr($out, strpos($out, '<script>') + 8);
$js = substr($js, 0, strpos($js, '</script>'));
$tmp = tempnam(sys_get_temp_dir(), 'anajs') . '.js';
file_put_contents($tmp, $js);
$node = trim((string)shell_exec((stripos(PHP_OS, 'WIN') === 0 ? 'where node 2>NUL' : 'command -v node 2>/dev/null')));
if ($node !== '') {
    $result = shell_exec('node --check ' . escapeshellarg($tmp) . ' 2>&1');
    check('node --check accepts it', trim((string)$result) === '');
    if (trim((string)$result) !== '') {
        echo "          $result\n";
    }
} else {
    echo "          node not found -- bracket balance only\n";
    $stripped = preg_replace('~"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|/\*.*?\*/|//[^\n]*~s', '', $js);
    check('braces balance', substr_count($stripped, '{') === substr_count($stripped, '}'));
    check('parentheses balance', substr_count($stripped, '(') === substr_count($stripped, ')'));
    check('brackets balance', substr_count($stripped, '[') === substr_count($stripped, ']'));
}
unlink($tmp);

section('what it does, and does not do');
check('strict mode', strpos($js, "'use strict'") !== false);
check('wrapped in an IIFE, nothing global', preg_match('~^\s*\(function \(\) \{~', $js) === 1 && preg_match('~\}\)\(\);\s*$~', $js) === 1);
check('capture-phase click listener on the document', strpos($js, "document.addEventListener('click', function (event) {") !== false && preg_match('~\}, true\);~', $js) === 1);
check('the SDK is loaded on demand with the utf-8 charset and a marker', strpos($js, "tag.charset = 'utf-8'") !== false && strpos($js, "setAttribute(marker, '1')") !== false);
check('the SDK load times out rather than hanging', strpos($js, 'waited >= 15000') !== false);
check('a hidden radio (single module) counts as selected', strpos($js, "radio.type === 'hidden' || radio.checked === true") !== false);
check('local validation: Luhn, expiry, CVV length', strpos($js, 'function luhnOk') !== false && strpos($js, 'card.number.length < 13') !== false && strpos($js, 'card.cvv.length < 3') !== false);
check('the nonce and only the card summary go into the carriers (5 on tokenize, 2 on clear, 5 on a wallet token, 5 on clearing one)', substr_count($js, 'setHidden(code + \'_') === 17 && strpos($js, "setHidden(code + '_value', response.opaqueData.dataValue)") !== false);
check('editing the card clears the nonce', strpos($js, "el.addEventListener('input', clearToken)") !== false);
check('an old nonce is refreshed', strpos($js, 'cfg.tokenTtlMs') !== false);
check('errors are shown with textContent, never innerHTML', strpos($js, 'box.textContent') !== false && strpos($js, 'innerHTML') === false);
check('no eval, no Function constructor, no console', preg_match('~\beval\(|new Function\(|console\.~', $js) === 0);
check('the re-issued click is the same control', strpos($js, 'control.click();') !== false);

section('the wallet hook (1.0.1)');
check('a wallet token in the carriers lets the click through untouched', strpos($js, 'if (fresh || walletTokenPresent()) {') !== false);
check('only a non-Accept descriptor with a value counts as a wallet token', strpos($js, "descriptor !== '' && descriptor !== acceptDescriptor && carrier('value') !== ''") !== false);
check('the public API is one object under the module code, and nothing else is global', preg_match_all('~\bwindow\[[^\]]+\]\s*=|\bwindow\.[a-zA-Z_]+\s*=~', $js, $globals) === 1 && $globals[0][0] === 'window[code] =');
foreach (['setWalletToken', 'clearWalletToken', 'hasWalletToken', 'select', 'config'] as $method) {
    check("API method $method", preg_match('~\b' . $method . ':\s*(function|[a-zA-Z]+)~', $js) === 1);
}
check('setWalletToken refuses the Accept descriptor and empty values', strpos($js, "if (!descriptor || !value || String(descriptor) === acceptDescriptor) {") !== false);
check('the token is kept in sessionStorage keyed to the order total, with a lifetime', strpos($js, 'window.sessionStorage.setItem(walletKey') !== false && strpos($js, "String(token.total) !== String(cfg.total)") !== false && strpos($js, '(Date.now() - token.at) > walletTtl') !== false);
check('a stored token is restored on each render (One Page Checkout redraws the block)', strpos($js, 'var stored = readStoredWallet();') !== false && strpos($js, 'applyWallet(stored);') !== false);
check('selecting the module is a real radio click, for OPC', strpos($js, 'radio.click();') !== false);
check('card edits, another payment method, and a submit all drop the wallet token', strpos($js, 'forgetWallet();') !== false && strpos($js, "target.name === 'payment' && target.value !== code") !== false && strpos($js, "form.name === 'checkout_payment' || form.name === 'checkout_confirmation'") !== false);
check('an Accept.js tokenization replaces any wallet token', preg_match('~forgetWallet\(\);\s*setHidden\(code \+ \'_descriptor\', response\.opaqueData\.dataDescriptor\)~', $js) === 1);
check('each render announces itself with a ready event carrying the API', strpos($js, "new CustomEvent(code + ':ready', { detail: api })") !== false);
check('config() exposes only what a wallet needs: code, total, currency, sandbox', strpos($js, 'return { code: code, total: cfg.total, currency: cfg.currency, sandbox: !!cfg.sandbox };') !== false);

ana_done('checkout script is sound');
