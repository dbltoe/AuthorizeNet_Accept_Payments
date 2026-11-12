<?php
/**
 * README.md, the admin ReadMe page that shows it, the docs, and the packaged
 * copy under dist/.
 *
 * README.md is Markdown, kept in the plugin folder and at the package root
 * (byte-identical). It isn't a public file: the ReadMe button in Plugin
 * Manager opens it through admin/authorizenet_accept_readme.php, behind the
 * admin login, for superusers only (zencart/zencart#8031). It is also the
 * only documentation most store owners will read.
 */

require __DIR__ . '/_bootstrap.php';

$PLUGIN = ana_plugin_dir();
$ROOT = ana_repo_root();
$readme = (string)file_get_contents($PLUGIN . '/README.md');
$version = basename($PLUGIN);

section('README.md, in two places');
check('README.md is in the plugin', is_file($PLUGIN . '/README.md'));
check('no readme.html anywhere (it was served publicly from zc_plugins/)',
    !is_file($PLUGIN . '/readme.html') && !is_file($ROOT . '/readme.html'));
check('the package-root README.md is byte-identical to the plugin\'s',
    is_file($ROOT . '/README.md') && md5_file($ROOT . '/README.md') === md5_file($PLUGIN . '/README.md'));
check('LF line endings', strpos($readme, "\r") === false);
check('the top block is the agreed shape',
    strpos($readme, "# Authorize.Net Accept.js Payments\n\nVersion " . ltrim($version, 'v')
        . " \u{00B7} Zen Cart 1.5.8 through 3.0.0 \u{00B7} PHP 7.4 through 8.5 \u{00B7} GPL v3 \u{00B7} by My Zen Cart Host (dbltoe)\n\n") === 0);
check('it says how to open it once installed',
    strpos($readme, "Once it's installed, a superuser can open this guide from the **ReadMe** button in Plugin Manager.") !== false);
check('it names the license: GPL version 3, with the GNU URL',
    strpos($readme, 'GNU General Public License, version 3') !== false && strpos($readme, 'https://www.gnu.org/licenses/gpl-3.0.html') !== false);
check('no GPL version 2 left in it', preg_match('~GPL[ -]?v?2|gpl-2\.0|General Public License,? v(ersion )?2~i', $readme) === 0);
check('it ends with Changelog and License', preg_match('~\n## Changelog\n\nSee `CHANGELOG\.md`\..*\n\n## License\n\n~', $readme) === 1);

section('only the Markdown the admin page renders');
// Outside code (fenced blocks and `spans`), nothing the renderer would show raw.
$prose = preg_replace('~^```.*?^```~ms', '', $readme);
$prose = preg_replace('~`[^`\n]+`~', '', $prose);
check('no raw HTML', strpos($prose, '<') === false);
check('no [text](url) links and no images', preg_match('~!?\[[^\]\n]*\]\([^)\n]*\)~', $prose) === 0);
check('no heading deeper than ###', preg_match('~^#{4,}~m', $prose) === 0);
check('no nested lists', preg_match('~^[ \t]+(-|\*|\d+\.)\s~m', $prose) === 0);
check('lists use - and 1. only', preg_match('~^(\*|\+)\s~m', $prose) === 0);
check('every link is a bare https URL', preg_match('~http://~', $prose) === 0 && preg_match_all('~https://~', $prose) >= 7);

section('the admin ReadMe page');
$pageFile = $PLUGIN . '/admin/authorizenet_accept_readme.php';
$page = (string)file_get_contents($pageFile);
$guard = strpos($page, "if (!defined('IS_ADMIN_FLAG'))");
$top = strpos($page, "require 'includes/application_top.php';");
check('404 guard, then application_top (the login check)', $guard !== false && $top !== false && $guard < $top);
check('superusers only', strpos($page, 'zen_is_superuser()') !== false && strpos($page, 'FILENAME_DENIED') !== false);
foreach ([
    'HTML' => "header('Content-Type: text/html; charset=utf-8');",
    'nosniff' => "header('X-Content-Type-Options: nosniff');",
    'no framing' => "header('X-Frame-Options: DENY');",
    'a CSP with no scripts, no framing, sandboxed' => "header(\"Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'; sandbox allow-popups allow-popups-to-escape-sandbox\");",
    'no referrer' => "header('Referrer-Policy: no-referrer');",
] as $what => $line) {
    check("it sends $what", strpos($page, $line) !== false);
}
check('it reads only the plugin\'s own README.md', strpos($page, "dirname(__DIR__) . '/README.md'") !== false && substr_count($page, 'file_get_contents(') === 1);
check('it outputs only the escaped Markdown render',
    substr_count($page, 'echo ') === 1 && strpos($page, '<?php echo $anaReadmeHtml; ?>') !== false);
check('it has no script', stripos($page, '<script') === false);
check('it carries the GPL v3 header', strpos($page, '@license  https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0') !== false);
check('it is not registered as an admin page (the installer leaves admin_pages alone)',
    strpos((string)file_get_contents($PLUGIN . '/Installer/ScriptedInstaller.php'), 'authorizenet_accept_readme') === false);

// The renderer itself: lift the two functions out of the page and run them.
$a = strpos($page, "if (!function_exists('ana_markdown_html'))");
$b = strpos($page, '$anaReadmeFile');
eval(substr($page, $a, $b - $a));
$html = ana_markdown_html($readme);
check('the guide renders with its headings', substr_count($html, '<h2>') === preg_match_all('~^## ~m', $readme) && substr_count($html, '<h1>') === 1);
check('the settings render as a table', strpos($html, '<th>Setting</th>') !== false && strpos($html, '<td>Enable Authorize.net (Accept.js) Module</td>') !== false);
check('all four tables render (credentials, settings, test cards, troubleshooting)', substr_count($html, '<table>') === 4);
check('the install steps render as one numbered list of four',
    preg_match('~<h2>Installing</h2>\n<ol>\n(<li>.*</li>\n){4}</ol>~', $html) === 1);
check('the sandbox note renders as a quote', strpos($html, '<blockquote>The module starts in <strong>Sandbox</strong> mode on purpose.') !== false);
check('the log file name keeps its angle brackets, escaped', strpos($html, '<code>authnet_accept_&lt;kind&gt;_&lt;transaction id&gt;_&lt;date&gt;.log</code>') !== false);
check('nothing in the render but the tags the renderer makes',
    preg_match('~<(?!/?(h[1-3]|p|ul|ol|li|blockquote|pre|code|table|tr|th|td|strong|em|a)[ >])~', $html) === 0);
check('no Markdown left unrendered', strpos($html, '**') === false && strpos($html, '`') === false);
check('every link opens in a new window with no opener or referrer',
    preg_match_all('~<a ~', $html) === preg_match_all('~<a href="https?://[^"]+" target="_blank" rel="noopener noreferrer">~', $html));
check('the support thread link survives whole',
    strpos($html, '<a href="https://www.zen-cart.com/threads/207341?page=1#post-1347112" target="_blank" rel="noopener noreferrer">') !== false);
$evil = ana_markdown_html("# <script>alert(1)</script>\n\n<img src=x onerror=alert(1)> [x](javascript:alert(1))\n\n| <b>a</b> | `<i>` |\n| --- | --- |\n| \"onmouseover=\"x | javascript:alert(1) |\n\n```\n</code></pre><script>x</script>\n```\n");
check('markup in the file comes out escaped, never live',
    stripos($evil, '<script') === false && stripos($evil, '<img') === false && stripos($evil, '<b>') === false
    && stripos($evil, '<i>') === false && strpos($evil, 'href="javascript') === false && strpos($evil, '"onmouseover') === false);

section('the readme is navigable and current');
check('it has every section the old page had, and more (' . preg_match_all('~^## ~m', $readme) . ')', preg_match_all('~^## ~m', $readme) >= 17);
check('it names the version it ships in: ' . ltrim($version, 'v'), strpos($readme, 'Version ' . ltrim($version, 'v')) !== false);
check('every path it quotes uses this version directory',
    preg_match_all('~zc_plugins/AuthorizeNetAccept/(v[0-9.]+)~', $readme, $vm) > 0
    && count(array_unique($vm[1])) === 1 && $vm[1][0] === $version);
foreach (['sandbox', 'Public Client Key', 'Transaction Key', 'API Login ID', 'Modules', 'refund', 'One Page Checkout', 'E00007'] as $needle) {
    check("it covers: $needle", stripos($readme, $needle) !== false);
}
check('it says where the sandbox account comes from', strpos($readme, 'developer.authorize.net/hello_world/sandbox') !== false);
check('it says a live account is paid and where to sign up', stripos($readme, 'paid service') !== false && strpos($readme, 'authorize.net/sign-up/pricing.html') !== false);
check('it links the support thread by its opening-post permalink', strpos($readme, 'https://www.zen-cart.com/threads/207341?page=1#post-1347112') !== false);
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
check('both changelogs lead with this version',
    preg_match('~^## (v[0-9.]+)~m', (string)file_get_contents($ROOT . '/CHANGELOG.md'), $c1) === 1 && $c1[1] === $version
    && preg_match('~^(v[0-9.]+) \(~m', (string)file_get_contents($PLUGIN . '/changelog.txt'), $c2) === 1 && $c2[1] === $version);
$license = (string)file_get_contents($ROOT . '/LICENSE');
check('LICENSE is the GNU GPL version 3 text, with the GNU URL and no zen-cart.com URL',
    strpos($license, 'Version 3, 29 June 2007') !== false && strpos($license, 'Version 2, June 1991') === false
    && strpos($license, 'gnu.org') !== false && strpos($license, 'zen-cart.com') === false);
foreach (['README.md', 'docs/INSTALL.md', 'docs/DESIGN.md', 'docs/SUPPORT_THREAD.md'] as $doc) {
    $text = (string)file_get_contents($ROOT . '/' . $doc);
    preg_match_all('~AuthorizeNetAccept/(v[0-9.]+)~', $text, $dm);
    check("$doc quotes only $version", $dm[1] === [] || (count(array_unique($dm[1])) === 1 && $dm[1][0] === $version));
}
foreach (['docs/INSTALL.md', 'docs/CONFIGURATION.md', 'docs/COMPATIBILITY.md', 'docs/CUSTOMIZING.md', 'docs/DESIGN.md', 'docs/PLUGIN_LIBRARY_LISTING.md', 'docs/SUPPORT_THREAD.md', 'for_zen_cart_1.5.8_to_2.0.x/README.txt'] as $doc) {
    $text = (string)file_get_contents($ROOT . '/' . $doc);
    check("$doc has no readme.html and no GPL version 2", stripos($text, 'readme.html') === false && preg_match('~GPL[ -]?v?2|gpl-2\.0~i', $text) === 0);
}

section('the packaged copy');
$distDir = $ROOT . '/dist/authorizenet_accept_js_payments_' . $version;
if (!is_dir($distDir)) {
    echo "          dist/ not built yet (tools\\build_package.ps1) -- skipping the package comparison\n";
} else {
    check('the package-root README.md is byte-identical to the source', is_file($distDir . '/README.md') && file_get_contents($distDir . '/README.md') === $readme);
    check('the packaged plugin README.md is byte-identical too', is_file($distDir . '/zc_plugins/AuthorizeNetAccept/' . $version . '/README.md') && file_get_contents($distDir . '/zc_plugins/AuthorizeNetAccept/' . $version . '/README.md') === $readme);
    check('the package has no readme.html', !is_file($distDir . '/readme.html') && !is_file($distDir . '/zc_plugins/AuthorizeNetAccept/' . $version . '/readme.html'));
    check('the package carries the admin ReadMe page', is_file($distDir . '/zc_plugins/AuthorizeNetAccept/' . $version . '/admin/authorizenet_accept_readme.php'));
    foreach (['LICENSE', 'CHANGELOG.md', 'docs/INSTALL.md', 'docs/CONFIGURATION.md', 'docs/CUSTOMIZING.md', 'docs/COMPATIBILITY.md'] as $f) {
        check("the package carries $f, current", is_file($distDir . '/' . $f) && md5_file($distDir . '/' . $f) === md5_file($ROOT . '/' . $f));
    }
    check('tests/ and tools/ are not in the package', !is_dir($distDir . '/tests') && !is_dir($distDir . '/tools'));
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
