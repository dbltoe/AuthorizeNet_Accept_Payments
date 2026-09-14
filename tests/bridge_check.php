<?php
/**
 * The bridge for Zen Cart 1.5.8 through 2.0.x: two files in the core folders
 * that hand off to the plugin's module and language file. They must find the
 * plugin, prefer the version the Plugin Manager installed, stay in step with
 * each other, and fail soft when the plugin folder is gone.
 *
 * The missing-plugin case declares a placeholder class of the same name, so
 * it runs in a child process:  php bridge_check.php missing
 */

require __DIR__ . '/_bootstrap.php';

$mode = $argv[1] ?? 'normal';
$ROOT = ana_repo_root();
$PLUGIN = ana_plugin_dir();
$BRIDGE = $ROOT . '/for_zen_cart_1.5.8_to_2.0.x';
$moduleBridge = $BRIDGE . '/includes/modules/payment/authorizenet_accept.php';
$langBridge = $BRIDGE . '/includes/languages/english/modules/payment/lang.authorizenet_accept.php';

/* A throwaway store root. */
$store = str_replace('\\', '/', sys_get_temp_dir()) . '/ana_bridge_' . getmypid() . '_' . $mode . '/';
ana_rm_tree($store);
@mkdir($store, 0777, true);
register_shutdown_function(static function () use ($store) {
    ana_rm_tree($store);
});

define('IS_ADMIN_FLAG', true);
define('DIR_FS_CATALOG', $store);

if ($mode === 'missing') {
    section('the plugin folder is gone but the bridge files were left behind');
    $define = require $langBridge;
    check('the language bridge returns an empty array', $define === []);
    require $moduleBridge;
    check('a placeholder class stands in', class_exists('authorizenet_accept', false));
    $placeholder = new authorizenet_accept();
    check('it reports itself as not installed and disabled', $placeholder->check() === 0 && $placeholder->enabled === false);
    check('its title says the plugin folder is missing and which files to delete',
        strpos($placeholder->title, 'zc_plugins/AuthorizeNetAccept') !== false
        && strpos($placeholder->title, 'includes/modules/payment/authorizenet_accept.php') !== false
        && strpos($placeholder->title, 'lang.authorizenet_accept.php') !== false);
    check('install() refuses without redirecting', $placeholder->install() === 'failed');
    foreach (['selection', 'pre_confirmation_check', 'confirmation', 'process_button', 'before_process', 'after_process', 'javascript_validation', 'update_status', 'keys', 'remove', 'get_error', 'check', 'install'] as $method) {
        check("the storefront and admin can call $method()", method_exists($placeholder, $method));
    }
    foreach (['code', 'title', 'description', 'enabled', 'sort_order', 'order_status'] as $property) {
        check("Modules > Payment can read ->$property", property_exists($placeholder, $property));
    }
    ana_done('missing plugin handled softly');
}

section('the bridge files themselves');
check('both files exist', is_file($moduleBridge) && is_file($langBridge));
foreach ([$moduleBridge, $langBridge] as $file) {
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1');
    check(basename($file) . ' lints', is_string($out) && strpos($out, 'No syntax errors') !== false);
}
$moduleSrc = file_get_contents($moduleBridge);
$langSrc = file_get_contents($langBridge);
$resolver = '~if \(!function_exists\(\'authorizenet_accept_bridge_file\'\)\) \{.*?\n\}\n~s';
preg_match($resolver, $moduleSrc, $m1);
preg_match($resolver, $langSrc, $m2);
check('both carry the resolver, guarded, and it is identical in each (either file may load first)', isset($m1[0], $m2[0]) && $m1[0] === $m2[0]);
check('the module bridge refuses direct access', strpos($moduleSrc, "if (!defined('IS_ADMIN_FLAG'))") !== false);
check('the language bridge returns nothing outside Zen Cart', strpos($langSrc, "if (!defined('DIR_FS_CATALOG'))") !== false);
check('the headers say which releases they are for', strpos($moduleSrc, '1.5.8 through 2.0.x') !== false && strpos($langSrc, '1.5.8') !== false && strpos($langSrc, '2.0.x') !== false);
check('a package note tells the owner when to upload them', is_file($BRIDGE . '/README.txt') && strpos(file_get_contents($BRIDGE . '/README.txt'), '2.1.0') !== false);
check('no PHP 8 syntax: the bridge runs on 1.5.8 under PHP 7.4', preg_match('~\bmatch\s*\(|\?->|\bstr_(contains|starts_with|ends_with)\(~', $moduleSrc . $langSrc) === 0);

section('finding the plugin');
$pluginCopy = $store . 'zc_plugins/AuthorizeNetAccept/' . basename($PLUGIN);
ana_copy_tree($PLUGIN . '/catalog', $pluginCopy . '/catalog');
file_put_contents($pluginCopy . '/marker.txt', 'current');
@mkdir($store . 'zc_plugins/AuthorizeNetAccept/v0.9.0', 0777, true);
file_put_contents($store . 'zc_plugins/AuthorizeNetAccept/v0.9.0/marker.txt', 'old');

ana_define_environment();
$define = require $langBridge;
check('the language bridge returns the plugin\'s definitions', is_array($define) && isset($define['MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_ADMIN_TITLE'], $define['MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_CATALOG_TITLE']));
check('the resolver is declared by whichever file loads first', function_exists('authorizenet_accept_bridge_file'));
check('with no installed version recorded, the highest version folder wins', authorizenet_accept_bridge_file('marker.txt') === $pluginCopy . '/marker.txt');
$installedPlugins = [['unique_key' => 'OtherPlugin', 'version' => 'v3.0.0'], ['unique_key' => 'AuthorizeNetAccept', 'version' => 'v0.9.0']];
check('the version the Plugin Manager installed is preferred', authorizenet_accept_bridge_file('marker.txt') === $store . 'zc_plugins/AuthorizeNetAccept/v0.9.0/marker.txt');
$installedPlugins = [['unique_key' => 'AuthorizeNetAccept', 'version' => 'v9.9.9']];
check('an installed version whose folder is gone falls back to what is there', authorizenet_accept_bridge_file('marker.txt') === $pluginCopy . '/marker.txt');
check('a file the plugin does not have gives an empty string', authorizenet_accept_bridge_file('nope.php') === '');
$installedPlugins = null;

section('loading the module through the bridge');
$db = new AnaDb();
$messageStack = new AnaMessageStack();
$zcDate = new AnaDate();
$order = ana_sample_order();
$_SESSION = ['customer_id' => 9];
require $moduleBridge;
$declaredIn = class_exists('authorizenet_accept', false) ? str_replace('\\', '/', (string)(new ReflectionClass('authorizenet_accept'))->getFileName()) : '';
check('the class comes from the plugin copy, not a placeholder', $declaredIn === $pluginCopy . '/catalog/includes/modules/payment/authorizenet_accept.php');
check('it is the real module: api() and the notifier seams are there', method_exists('authorizenet_accept', 'api') && method_exists('authorizenet_accept', 'allowedDescriptors'));
$module = new authorizenet_accept();
check('and it constructs with the plugin\'s language constants (admin title, since IS_ADMIN_FLAG is on here)', $module->code === 'authorizenet_accept' && strpos($module->title, 'Authorize.net (Accept.js)') === 0);
require $moduleBridge;
check('loading the bridge twice is harmless', (new authorizenet_accept())->code === 'authorizenet_accept');

section('the missing-plugin case, in a child process');
$out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' missing 2>&1');
$childOk = is_string($out) && strpos($out, 'PASS:') !== false && strpos($out, 'FAIL') === false;
check('missing child passed', $childOk);
if (!$childOk) {
    echo $out;
}

ana_done('the 1.5.8 - 2.0.x bridge finds the plugin and fails soft without it');
