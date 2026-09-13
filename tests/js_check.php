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
check('the nonce and only the card summary go into the carriers', substr_count($js, 'setHidden(code + \'_') === 7 && strpos($js, "setHidden(code + '_value', response.opaqueData.dataValue)") !== false);
check('editing the card clears the nonce', strpos($js, "el.addEventListener('input', clearToken)") !== false);
check('an old nonce is refreshed', strpos($js, 'cfg.tokenTtlMs') !== false);
check('errors are shown with textContent, never innerHTML', strpos($js, 'box.textContent') !== false && strpos($js, 'innerHTML') === false);
check('no eval, no Function constructor, no console', preg_match('~\beval\(|new Function\(|console\.~', $js) === 0);
check('the re-issued click is the same control', strpos($js, 'control.click();') !== false);

ana_done('checkout script is sound');
