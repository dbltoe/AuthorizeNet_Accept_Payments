<?php
/**
 * readme.html, the docs, and the packaged copy under dist/.
 *
 * readme.html is opened from the Plugin Manager panel, served out of
 * zc_plugins/ by the web server, and is the only documentation most store
 * owners will read.
 */

require __DIR__ . '/_bootstrap.php';

$PLUGIN = ana_plugin_dir();
$ROOT = ana_repo_root();
$readme = file_get_contents($PLUGIN . '/readme.html');
$version = basename($PLUGIN);

section('readme.html stands alone');
check('no external stylesheet', preg_match('~<link[^>]+rel="stylesheet"~i', $readme) === 0);
check('no external script', preg_match('~<script[^>]+src=~i', $readme) === 0);
check('no <img> at all, so nothing can 404 in the panel', preg_match('~<img~i', $readme) === 0);
check('nothing is LOADED from another host (links are fine)', preg_match('~\bsrc\s*=\s*["\']https?://~i', $readme) === 0);
check('it declares its charset', stripos($readme, 'charset="utf-8"') !== false || stripos($readme, 'charset=utf-8') !== false);
check('it declares a viewport', stripos($readme, 'name="viewport"') !== false);
check('it declares a language', preg_match('~<html[^>]+lang=~i', $readme) === 1);
check('it has a title', preg_match('~<title>[^<]+</title>~i', $readme) === 1);

section('the readme is navigable and current');
preg_match_all('~<h2 id="([^"]+)"~', $readme, $h2);
check('every h2 carries an id (' . count($h2[1]) . ' sections)', count($h2[1]) >= 10);
check('the version on the page is this version', strpos($readme, ltrim($version, 'v')) !== false);
foreach (['sandbox', 'Public Client Key', 'Transaction Key', 'API Login ID', 'Modules', 'refund', 'One Page Checkout', 'E00007'] as $needle) {
    check("it covers: $needle", stripos($readme, $needle) !== false);
}
check('it says where the sandbox account comes from', strpos($readme, 'developer.authorize.net/hello_world/sandbox') !== false);
check('it says a live account is paid and where to sign up', stripos($readme, 'paid service') !== false && strpos($readme, 'authorize.net/sign-up/pricing.html') !== false);
check('it names the two SDK hosts the store owner may need to allow', strpos($readme, 'js.authorize.net') !== false && strpos($readme, 'jstest.authorize.net') !== false);
check('American spelling', preg_match('~\b(licence|authorise|authorised|colour|cancelled)\b~i', $readme) === 0);
check('it uses contractions rather than stiff prose', preg_match_all("~\\b(doesn't|isn't|can't|won't|you'll|it's|don't|aren't)\\b~", $readme) >= 4);

section('the docs');
foreach (['INSTALL.md', 'CONFIGURATION.md', 'COMPATIBILITY.md', 'CUSTOMIZING.md', 'DESIGN.md'] as $doc) {
    check("docs/$doc exists and is not a stub", is_file($ROOT . '/docs/' . $doc) && filesize($ROOT . '/docs/' . $doc) > 800);
}
$configDoc = file_get_contents($ROOT . '/docs/CONFIGURATION.md');
$moduleSrc = file_get_contents($PLUGIN . '/catalog/includes/modules/payment/authorizenet_accept.php');
preg_match_all("~installKey\('([^']+)', '([A-Z_]+)'~", $moduleSrc, $m);
$undocumented = [];
foreach ($m[1] as $title) {
    if (strpos($configDoc, $title) === false) {
        $undocumented[] = $title;
    }
}
check('every setting the module installs is documented by its title', $undocumented === []);
foreach ($undocumented as $u) {
    echo "          missing: $u\n";
}
$customizing = file_get_contents($ROOT . '/docs/CUSTOMIZING.md');
preg_match_all("~NOTIFY_AUTHNET_ACCEPT_[A-Z_]+~", $moduleSrc, $events);
$missingEvents = array_diff(array_unique($events[0]), array_keys(array_flip(array_filter($events[0], static function ($e) use ($customizing) { return strpos($customizing, $e) !== false; }))));
check('every notifier the module fires is documented', $missingEvents === []);
foreach ($missingEvents as $e) {
    echo "          missing: $e\n";
}
$top = file_get_contents($ROOT . '/README.md');
check('README.md names the plugin and the version floor', strpos($top, 'Authorize.Net Accept.js Payments') !== false && strpos($top, '1.5.8') !== false);
check('the readme tells 1.5.8 - 2.0.x owners about the bridge folder, in the install and uninstall sections', substr_count($readme, 'for_zen_cart_1.5.8_to_2.0.x') >= 1 && substr_count($readme, 'bridge files') >= 2 && strpos($readme, '1.5.8 through 3.0.0') !== false);
check('CHANGELOG.md and changelog.txt agree on the version', strpos(file_get_contents($ROOT . '/CHANGELOG.md'), $version) !== false && strpos(file_get_contents($PLUGIN . '/changelog.txt'), $version) !== false);
check('LICENSE is GPL-2.0 with the GNU URL and no zen-cart.com URL', strpos(file_get_contents($ROOT . '/LICENSE'), 'gnu.org') !== false && strpos(file_get_contents($ROOT . '/LICENSE'), 'zen-cart.com') === false);

section('the packaged copy');
$distDir = $ROOT . '/dist/authorizenet_accept_js_payments_' . $version;
if (!is_dir($distDir)) {
    echo "          dist/ not built yet (tools\\build_package.ps1) -- skipping the package comparison\n";
} else {
    check('the packaged readme.html is byte-identical to the source', file_get_contents($distDir . '/readme.html') === $readme);
    check('the packaged plugin readme is byte-identical too', file_get_contents($distDir . '/zc_plugins/AuthorizeNetAccept/' . $version . '/readme.html') === $readme);
    $srcFiles = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($PLUGIN, FilesystemIterator::SKIP_DOTS)) as $f) {
        $srcFiles[] = substr(str_replace('\\', '/', $f->getPathname()), strlen($PLUGIN));
    }
    $stale = [];
    foreach ($srcFiles as $relPath) {
        $packaged = $distDir . '/zc_plugins/AuthorizeNetAccept/' . $version . $relPath;
        if (!is_file($packaged) || md5_file($packaged) !== md5_file($PLUGIN . $relPath)) {
            $stale[] = $relPath;
        }
    }
    check('every shipped file in dist/ matches the source (' . count($srcFiles) . ' files)', $stale === []);
    foreach ($stale as $s) {
        echo "          stale: $s\n";
    }
    $bridgeSrc = $ROOT . '/for_zen_cart_1.5.8_to_2.0.x';
    $bridgeStale = [];
    $bridgeCount = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($bridgeSrc, FilesystemIterator::SKIP_DOTS)) as $f) {
        $bridgeCount++;
        $relPath = substr(str_replace('\\', '/', $f->getPathname()), strlen($bridgeSrc));
        $packaged = $distDir . '/for_zen_cart_1.5.8_to_2.0.x' . $relPath;
        if (!is_file($packaged) || md5_file($packaged) !== md5_file($f->getPathname())) {
            $bridgeStale[] = $relPath;
        }
    }
    check('the bridge folder is packaged and current (' . $bridgeCount . ' files)', $bridgeCount === 3 && $bridgeStale === []);
    $zip = dirname($ROOT) . '/authorizenet_accept_js_payments_' . $version . '.zip';
    check('the release zip exists next to the repository', is_file($zip));
}

ana_done('readme and docs are complete');
