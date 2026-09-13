<?php
/**
 * manifest.php: the Plugin Manager panel and the release identity.
 */

require __DIR__ . '/_bootstrap.php';

define('DIR_WS_CATALOG', '/shop/');

$PLUGIN = ana_plugin_dir();
$version = basename($PLUGIN);
$manifest = require $PLUGIN . '/manifest.php';
$desc = $manifest['pluginDescription'];

section('manifest shape');
check('returns an array', is_array($manifest));
foreach (['pluginVersion', 'pluginName', 'pluginDescription', 'pluginAuthor', 'pluginId', 'zcVersions', 'changelog', 'github_repo', 'pluginGroups'] as $key) {
    check("has $key", array_key_exists($key, $manifest));
}
check('declares the releases that can host a plugin payment module: v210 onward',
    $manifest['zcVersions'] === ['v210', 'v220', 'v230', 'v300']);
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
check('links open in a new tab safely', substr_count($desc, 'rel="noopener noreferrer"') >= 2);
check('the description tells the owner the second step: Modules > Payment', strpos($desc, 'Modules &gt; Payment') !== false);
check('no forum link is rendered while the thread does not exist', strpos($desc, 'Forum Support Thread') === false);

section('release hygiene');
check('pluginId is 0 until the Library assigns one, or a real id', is_int($manifest['pluginId']));
check('no license URL that 404s', strpos(file_get_contents($PLUGIN . '/manifest.php'), 'zen-cart.com/license') === false);

ana_done('manifest is consistent');
