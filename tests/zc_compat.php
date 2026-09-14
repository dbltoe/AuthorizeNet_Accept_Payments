<?php
/**
 * Does this plugin only use what every supported Zen Cart release actually has?
 *
 * The claim is v1.5.8 through v3.0.0 from a single codebase, and the way that
 * claim breaks is one call to a helper that arrived in a later release. So
 * this does not guess from documentation: it reads the real installs under
 * F:\zclab\sites and asks each one whether it defines the function.
 *
 * Skips itself with a note when the lab is not present.
 */

require __DIR__ . '/_bootstrap.php';

$PLUGIN = ana_plugin_dir();
$manifest = require $PLUGIN . '/manifest.php';

$branchDirs = ['v158' => 'zc158', 'v200' => 'zc200', 'v210' => 'zc210', 'v220' => 'zc222', 'v230' => 'zc230', 'v300' => 'zc300'];
$LAB = 'F:/zclab/sites';

$available = [];
foreach ($manifest['zcVersions'] as $branch) {
    $dir = $LAB . '/' . ($branchDirs[$branch] ?? '');
    if (isset($branchDirs[$branch]) && is_dir($dir)) {
        $available[$branch] = $dir;
    }
}

section('what can be checked');
check('the manifest declares the branches it supports: ' . implode(', ', $manifest['zcVersions']), !empty($manifest['zcVersions']));
if ($available === []) {
    echo "          no Zen Cart installs found under $LAB -- source checks only\n";
} else {
    echo '          checking against ' . count($available) . ' of ' . count($manifest['zcVersions']) . ' declared branches: ' . implode(', ', array_keys($available)) . "\n";
}

/* ---- gather every core call the plugin makes ---- */
$calls = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($PLUGIN, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') {
        continue;
    }
    $src = file_get_contents($f->getPathname());
    preg_match_all('~(?<![\w$>])(zen_[a-z0-9_]+)\s*\(~', $src, $m);
    foreach ($m[1] as $fn) {
        $calls[$fn] = true;
    }
}
$calls = array_keys($calls);
sort($calls);
echo '          ' . count($calls) . ' distinct zen_* functions called: ' . implode(', ', $calls) . "\n";

section('source rules');
$allSrc = '';
$bridgeIt = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ana_repo_root() . '/for_zen_cart_1.5.8_to_2.0.x', FilesystemIterator::SKIP_DOTS));
foreach ([$it, $bridgeIt] as $tree) {
    foreach ($tree as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
            $allSrc .= file_get_contents($f->getPathname());
        }
    }
}
/* Syntax checks look at code only: string literals and comments are stripped
 * first, so "(qty: 2)" in a product description is not a named argument. */
$codeOnly = preg_replace('~"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|/\*.*?\*/|//[^\n]*|#[^\n]*~s', '""', $allSrc);
check('no zen_config(): it is v3.0.0 only', strpos($codeOnly, 'zen_config(') === false);
check('no named arguments (PHP 8.0) or match (8.0): the floor is 7.4', preg_match('~\bmatch\s*\(~', $codeOnly) === 0 && preg_match('~\w\(\s*[a-z_][a-z0-9_]*:\s~', $codeOnly) === 0);
check('no str_contains / str_starts_with (PHP 8.0)', preg_match('~\bstr_(contains|starts_with|ends_with)\(~', $codeOnly) === 0);
check('no readonly / enum / never (PHP 8.1)', preg_match('~\b(readonly|enum)\s|:\s*never\b~', $codeOnly) === 0);
check('no nullsafe operator (PHP 8.0) or constructor promotion', preg_match('~\?->~', $codeOnly) === 0 && preg_match('~function __construct\((\s*(public|private|protected)\b)~', $codeOnly) === 0);
check('no HTTPS_SERVER / ENABLE_SSL dependency (dropped in 3.0.0) beyond a defined() guard', preg_match('~(?<!defined\(\')ENABLE_SSL~', str_replace("defined('ENABLE_SSL') && ENABLE_SSL === 'true'", '', $allSrc)) === 0 && strpos($allSrc, 'HTTPS_SERVER') === false);

/* ---- each branch: does it define the function, on the side that uses it? ---- */
foreach ($available as $branch => $dir) {
    section("branch $branch");
    $defined = [];
    $roots = [$dir . '/includes/functions', $dir . '/includes/classes', $dir . '/admin/includes/functions'];
    foreach ($roots as $root) {
        if (!is_dir($root)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
                preg_match_all('~function\s+(zen_[a-z0-9_]+)\s*\(~i', file_get_contents($f->getPathname()), $m);
                foreach ($m[1] as $fn) {
                    $defined[strtolower($fn)] = true;
                }
            }
        }
    }
    $missing = [];
    foreach ($calls as $fn) {
        if (!isset($defined[strtolower($fn)])) {
            $missing[] = $fn;
        }
    }
    check("every zen_* function the plugin calls exists in $branch", $missing === []);
    foreach ($missing as $fn) {
        echo "          missing in $branch: $fn\n";
    }
    $paymentClass = file_get_contents($dir . '/includes/classes/payment.php');
    $langLoader = $dir . '/includes/classes/ResourceLoaders/ArraysLanguageLoader.php';
    $loaderSrc = is_file($langLoader) ? file_get_contents($langLoader) : '';
    if (in_array($branch, ['v158', 'v200'], true)) {
        /* These two are why the bridge files exist: the payment class, Modules > Payment
         * and the order page read the core folders only. The bridge relies on three
         * things they DO have: lang.* array files for modules, plugin extra_datafiles
         * (the table name), and $installedPlugins to pick the installed version. */
        check("$branch looks for a payment module only in the core folder, which is what the module bridge is for", strpos($paymentClass, 'zc_plugins') === false && strpos($paymentClass, "DIR_WS_MODULES . '/payment/'") !== false);
        check("$branch loads a lang.* array file for a module from the core languages folder, which is what the language bridge is for", $loaderSrc !== '' && strpos($loaderSrc, "'lang.' . \$fileName") !== false);
        $appTop = file_get_contents($dir . '/includes/application_top.php');
        check("$branch loads plugin extra_datafiles and exposes \$installedPlugins", strpos($appTop, "'catalog/includes/extra_datafiles'") !== false && strpos($appTop, '$installedPlugins = ') !== false);
    } else {
        check("$branch loads payment modules from zc_plugins", strpos($paymentClass, 'zc_plugins') !== false);
        check("$branch reads a plugin's payment-module language file", $loaderSrc !== '' && strpos($loaderSrc, "'/modules/' . \$module_type") !== false);
    }
    $orders = $dir . '/admin/orders.php';
    check("$branch's order page still calls _doRefund / _doCapt / _doVoid", is_file($orders) && strpos(file_get_contents($orders), '_doRefund') !== false && strpos(file_get_contents($orders), '_doCapt') !== false && strpos(file_get_contents($orders), '_doVoid') !== false);
}

ana_done('compatible with every declared release');
