<?php
/**
 * manifest.php: the Plugin Manager panel and the release identity.
 *
 * The first read is a plain one (no admin, no database), so the Settings
 * button is absent. The Settings section then reads the manifest again as an
 * admin page would, once per install state: through plugin_control.status on
 * an ordinary Plugin Manager visit, and through the installer's own
 * $anaInstallerSaysInstalled while core's install / uninstall step is running.
 */

require __DIR__ . '/_bootstrap.php';

define('DIR_WS_CATALOG', '/shop/');

$PLUGIN = ana_plugin_dir();
$version = basename($PLUGIN);
$manifest = require $PLUGIN . '/manifest.php';
$desc = $manifest['pluginDescription'];
$src = file_get_contents($PLUGIN . '/manifest.php');

/**
 * Read the manifest the way an admin page would, in a function scope like
 * core's, with $db answering plugin_control.status ($status null = no row).
 * $installerSays non-null is the installer's variable, set as
 * anaRefreshPluginDescription() sets it.
 */
function ana_manifest_read($status, $installerSays = null)
{
    $db = new AnaDb();
    if ($status !== null) {
        $db->answers['FROM zen_plugin_control'] = [['status' => $status]];
    }
    $GLOBALS['db'] = $db;
    if ($installerSays !== null) {
        $anaInstallerSaysInstalled = $installerSays;
    }
    $manifest = require ana_plugin_dir() . '/manifest.php';
    unset($GLOBALS['db']);
    return [$manifest['pluginDescription'], $db];
}

function ana_labels($desc)
{
    preg_match_all('~role="button"[^>]*>([^<]+)</a>~', $desc, $m);
    return $m[1];
}

section('manifest shape');
check('returns an array', is_array($manifest));
foreach (['pluginVersion', 'pluginName', 'pluginDescription', 'pluginAuthor', 'pluginId', 'zcVersions', 'changelog', 'github_repo', 'pluginGroups'] as $key) {
    check("has $key", array_key_exists($key, $manifest));
}
check('declares every release it runs on: v158 onward (1.5.8 - 2.0.x through the bridge files)',
    $manifest['zcVersions'] === ['v158', 'v200', 'v210', 'v220', 'v230', 'v300']);
check('changelog points at a file that exists', is_file($PLUGIN . '/' . $manifest['changelog']));
check('the changelog mentions this version', strpos(file_get_contents($PLUGIN . '/' . $manifest['changelog']), $manifest['pluginVersion']) !== false);
check('pluginVersion matches the directory: ' . $manifest['pluginVersion'], ltrim($manifest['pluginVersion'], 'v') === ltrim($version, 'v'));
check('the module reports the same version', strpos(file_get_contents($PLUGIN . '/catalog/includes/modules/payment/authorizenet_accept.php'), "const VERSION = '" . ltrim($version, 'v') . "'") !== false);

section('author attribution');
check('author is the agreed string', $manifest['pluginAuthor'] === 'My Zen Cart Host (dbltoe)');
check('author fits the varchar(64) columns', strlen($manifest['pluginAuthor']) <= 64);
check('name fits the varchar(255) column', strlen($manifest['pluginName']) <= 255);

section('panel buttons');
check('readme link honours DIR_WS_CATALOG', strpos($desc, 'href="/shop/zc_plugins/AuthorizeNetAccept/' . $version . '/readme.html"') !== false);
check('readme.html exists at that path', is_file($PLUGIN . '/readme.html'));
check('GitHub button and github_repo agree', strpos($desc, 'href="' . $manifest['github_repo'] . '"') !== false && strpos($manifest['github_repo'], 'https://github.com/dbltoe/') === 0);
check('the description tells the owner the second step: Modules > Payment', strpos($desc, 'Modules &gt; Payment') !== false);
check('the Forum Thread button links the opening-post permalink', strpos($desc, 'href="https://www.zen-cart.com/threads/207341?page=1#post-1347112"') !== false && strpos($desc, '>Forum Thread</a>') !== false);
check('no old labels left', strpos($desc, 'Read Me') === false && strpos($desc, 'Forum Support Thread') === false);
check('a plain read (no admin, no database) has three buttons: ' . implode(' | ', ana_labels($desc)), ana_labels($desc) === ['ReadMe', 'Forum Thread', 'GitHub']);
check('all three styled as core buttons', substr_count($desc, 'class="btn btn-primary" role="button"') === 3);
check('the new-tab buttons open safely', substr_count($desc, 'target="_blank" rel="noopener noreferrer"') === 3);
check('they share a single row (nothing block-level between them)', preg_match('~>ReadMe</a>\s*<a~', $desc) === 1 && substr_count($desc, '<div') === 1);
check('equal 4px spacing and 8px side padding on every button', strpos($desc, 'padding:0 0 0 4px') !== false
    && substr_count($desc, 'style="margin:0 4px 0 0;padding-left:8px;padding-right:8px"') === 3);

section('the Settings button (a payment module: Modules > Payment, once the plugin is installed)');
define('IS_ADMIN_FLAG', true);
define('TABLE_PLUGIN_CONTROL', 'zen_plugin_control');
$row4 = ['ReadMe', 'Settings', 'Forum Thread', 'GitHub'];
$row3 = ['ReadMe', 'Forum Thread', 'GitHub'];
$settingsHref = 'href="index.php?cmd=modules&amp;set=payment&amp;module=authorizenet_accept" class="btn btn-primary"';
foreach ([
    'no plugin_control row yet (first scan)' => [null, null, $row3],
    'status 0, not installed' => [0, null, $row3],
    'status 1, installed and enabled' => [1, null, $row4],
    'status 2, installed but disabled' => [2, null, $row4],
    'installer at Install: says installed while core still has status 0' => [0, true, $row4],
    'installer at Upgrade: says installed (status 1)' => [1, true, $row4],
    'installer at Uninstall: says not installed while 3.0.0 still has status 1' => [1, false, $row3],
] as $case => [$status, $installerSays, $want]) {
    [$d, $db] = ana_manifest_read($status, $installerSays);
    check($case . ': ' . implode(' | ', ana_labels($d)), ana_labels($d) === $want);
    check('  every button styled and spaced alike', substr_count($d, 'style="margin:0 4px 0 0;padding-left:8px;padding-right:8px"') === count($want));
    if ($want === $row4) {
        check('  Settings links relatively to Modules > Payment with the module selected, same tab', strpos($d, $settingsHref) !== false && preg_match('~href="index.php[^"]*" target=~', $d) === 0);
    }
    $lookups = $db->matching("SELECT status FROM zen_plugin_control WHERE unique_key = 'AuthorizeNetAccept' LIMIT 1");
    check($installerSays === null ? '  read plugin_control once, by this plugin\'s key' : '  took the installer\'s word: no plugin_control read', count($lookups) === ($installerSays === null ? 1 : 0));
}
check('the module code in the link is the module\'s own', is_file($PLUGIN . '/catalog/includes/modules/payment/authorizenet_accept.php'));
check('the lookup is guarded (admin, plugin_control table, a database object)',
    strpos($src, "defined('IS_ADMIN_FLAG') && IS_ADMIN_FLAG === true && defined('TABLE_PLUGIN_CONTROL')") !== false
    && strpos($src, "isset(\$GLOBALS['db']) && is_object(\$GLOBALS['db'])") !== false);
check('the installer sets the variable the manifest reads, by the same name', strpos(file_get_contents($PLUGIN . '/Installer/ScriptedInstaller.php'), '$anaInstallerSaysInstalled = $installed;') !== false && strpos($src, 'isset($anaInstallerSaysInstalled)') !== false);

section('release hygiene');
check('pluginId is the Library\'s 2459 as a bare integer (renumbered from 2261 on 2026-10-06)', $manifest['pluginId'] === 2459);
check('pluginId is written unquoted in the manifest', preg_match("~'pluginId' => 2459,~", file_get_contents($PLUGIN . '/manifest.php')) === 1);
check('no license URL that 404s', strpos(file_get_contents($PLUGIN . '/manifest.php'), 'zen-cart.com/license') === false);

ana_done('manifest is consistent');
