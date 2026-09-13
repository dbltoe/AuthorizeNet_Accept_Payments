<?php
/**
 * Authorize.Net Accept.js Payments -- plugin manifest.
 *
 * The description is echoed as raw HTML in the Plugin Manager's info panel,
 * which is also where the Install / Uninstall buttons live, so the Read Me and
 * GitHub buttons are placed here. The Read Me link is built from
 * DIR_WS_CATALOG so it works whatever the store lives at.
 *
 * On v2.2 and later the description is refreshed from this file on every
 * Plugin Manager scan; on v2.1 it is captured once, when the plugin_control
 * row is first created. So nothing state-dependent belongs here, and the forum
 * support link has to be present BEFORE the first release.
 *
 * @package  AuthorizeNetAccept
 * @license  https://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU Public License V2.0
 */

$anaPluginDir = 'zc_plugins/AuthorizeNetAccept/v1.0.0/';
$anaReadmeUrl = (defined('DIR_WS_CATALOG') ? DIR_WS_CATALOG : '/') . $anaPluginDir . 'readme.html';
$anaGithubUrl = 'https://github.com/dbltoe/AuthorizeNet_Accept_Payments';

/**
 * The Zen Cart forum support thread: the opening-post permalink, set before
 * the first release. An empty string renders no link at all, which is better
 * than a link that 404s.
 */
$anaForumUrl = '';

$anaGap = '6px';
$anaLinks =
    '<div style="margin:10px 0 0;padding:0 0 0 ' . $anaGap . '">'
    . '<a href="' . $anaReadmeUrl . '" target="_blank" rel="noopener noreferrer"'
    . ' class="btn btn-primary" role="button" style="margin:0 ' . $anaGap . ' 0 0">Read Me</a>'
    . '<a href="' . $anaGithubUrl . '" target="_blank" rel="noopener noreferrer"'
    . ' class="btn btn-primary" role="button" style="margin:0 ' . $anaGap . ' 0 0">GitHub</a>'
    . ($anaForumUrl !== ''
        ? '<a href="' . $anaForumUrl . '" target="_blank" rel="noopener noreferrer"'
          . ' class="btn btn-primary" role="button" style="margin:0 ' . $anaGap . ' 0 0">Forum Support Thread</a>'
        : '')
    . '</div>';

return [
    'pluginVersion' => 'v1.0.0',
    'pluginName' => 'Authorize.Net Accept.js Payments',
    'pluginDescription' =>
        'Credit card payments through Authorize.Net\'s current JSON API with Accept.js tokenization, '
        . 'so the card number never touches your server. Replaces the retired AIM and SIM methods. '
        . 'Authorize, capture, refund and void from the order page, with a transaction history per order. '
        . 'Install it here, then enable and configure it under Modules &gt; Payment.'
        . $anaLinks,
    'pluginAuthor' => 'My Zen Cart Host (dbltoe)',
    'pluginId' => 0, // assigned by the Plugins Library on acceptance
    'zcVersions' => ['v210', 'v220', 'v230', 'v300'],
    'changelog' => 'changelog.txt',
    'github_repo' => $anaGithubUrl,
    'pluginGroups' => [],
];
