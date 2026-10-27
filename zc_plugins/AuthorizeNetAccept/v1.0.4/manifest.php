<?php
/**
 * Authorize.Net Accept.js Payments -- plugin manifest.
 *
 * The description is echoed as raw HTML in the Plugin Manager's info panel,
 * which is also where the Install / Uninstall buttons live, so the ReadMe,
 * Settings, Forum Thread and GitHub buttons are placed here. The ReadMe link
 * is built from DIR_WS_CATALOG so it works whatever the store lives at.
 *
 * On v2.2 and later the description is refreshed from this file on every
 * Plugin Manager scan; on v1.5.8, v2.0 and v2.1 it is captured once, when the
 * plugin_control row is first created, and only this plugin's installer
 * refreshes it there (at Install, Upgrade and Uninstall). The one
 * state-dependent part, the Settings button, is handled below. The forum
 * support link has to be present BEFORE the first release.
 *
 * @package  AuthorizeNetAccept
 * @license  https://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU Public License V2.0
 */

$anaPluginDir = 'zc_plugins/AuthorizeNetAccept/v1.0.4/';
$anaReadmeUrl = (defined('DIR_WS_CATALOG') ? DIR_WS_CATALOG : '/') . $anaPluginDir . 'readme.html';
$anaGithubUrl = 'https://github.com/dbltoe/AuthorizeNet_Accept_Payments';

/**
 * The Zen Cart forum support thread: the opening-post permalink, set before
 * the first release. An empty string renders no link at all, which is better
 * than a link that 404s.
 */
$anaForumUrl = 'https://www.zen-cart.com/threads/207341?page=1#post-1347112';

/**
 * The Settings button: Modules > Payment with this module selected, shown only
 * once the plugin is installed in Plugin Manager. A payment module has no
 * configuration group of its own; its settings live in Modules > Payment, and
 * the module is installed there as a second step, so the button goes there
 * whether or not that step has been done. The link is relative because Plugin
 * Manager is an admin page, so it survives a domain or admin-folder rename.
 *
 * "Installed" has to be right at the moment the description is saved:
 *
 * - On v1.5.8, v2.0 and v2.1 the only save after the first scan is the
 *   installer's own (anaRefreshPluginDescription), which runs INSIDE core's
 *   install, upgrade and uninstall step. plugin_control.status can't be the
 *   signal there: BasePluginInstaller::processInstall() sets status 1 only
 *   AFTER executeInstall() returns (1.5.8 through 3.0.0), so a status read
 *   during Install still says "not installed"; and on 3.0.0
 *   processUninstall() sets status 0 only AFTER executeUninstall(). So the
 *   installer tells this file directly: it sets $anaInstallerSaysInstalled
 *   (true at Install and Upgrade, false at Uninstall) in its own scope just
 *   before it requires this file, and require shares that scope.
 * - Everywhere else (every Plugin Manager visit on v2.2 and later, which
 *   rewrites the description from this file) the variable isn't set, core
 *   has finished the step, and plugin_control.status is the truth: installed
 *   is any status but 0 (1 enabled, 2 disabled). That read is guarded so a
 *   manifest read anywhere else (no database, catalog side, CLI) just leaves
 *   the button out.
 */
if (isset($anaInstallerSaysInstalled)) {
    $anaIsInstalled = ($anaInstallerSaysInstalled === true);
} else {
    $anaIsInstalled = false;
    if (defined('IS_ADMIN_FLAG') && IS_ADMIN_FLAG === true && defined('TABLE_PLUGIN_CONTROL')
        && isset($GLOBALS['db']) && is_object($GLOBALS['db'])) {
        $anaControl = $GLOBALS['db']->Execute(
            "SELECT status FROM " . TABLE_PLUGIN_CONTROL
            . " WHERE unique_key = 'AuthorizeNetAccept' LIMIT 1"
        );
        $anaIsInstalled = !$anaControl->EOF && (int)$anaControl->fields['status'] !== 0;
    }
}
$anaSettingsUrl = $anaIsInstalled ? 'index.php?cmd=modules&set=payment&module=authorizenet_accept' : '';

/**
 * The buttons are styled like Plugin Manager's own controls, which use
 * exactly `class="btn btn-primary" role="button"`. The whole description is a
 * single setBoxContent() item, so the inline-block buttons line up on one row.
 *
 * One row: ReadMe | Settings | Forum Thread | GitHub. A second line would push
 * the Author line and the core buttons further down the panel. Spacing is set
 * inline, which beats any theme `.btn` margin: every gap is 4px (the wrapper
 * supplies the space before the first button, each button the space after
 * itself) and the side padding is 8px rather than Bootstrap's 12px, so all
 * four fit on one line in Plugin Manager's side panel from a 1366px-wide
 * window up. The wrapper's 10px top margin separates the row from the
 * description text above it. Settings opens in the same tab (it's the admin
 * itself); the others open a new one.
 */
$anaGap = '4px';
$anaButtonStyle = ' style="margin:0 ' . $anaGap . ' 0 0;padding-left:8px;padding-right:8px"';
$anaButton = static function ($url, $label, $newTab) use ($anaButtonStyle) {
    return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"'
        . ($newTab ? ' target="_blank" rel="noopener noreferrer"' : '')
        . ' class="btn btn-primary" role="button"' . $anaButtonStyle . '>' . $label . '</a>';
};

$anaLinks =
    '<div style="margin:10px 0 0;padding:0 0 0 ' . $anaGap . '">'
    . $anaButton($anaReadmeUrl, 'ReadMe', true)
    . ($anaSettingsUrl !== '' ? $anaButton($anaSettingsUrl, 'Settings', false) : '')
    // Renders nothing while $anaForumUrl is empty.
    . ($anaForumUrl !== '' ? $anaButton($anaForumUrl, 'Forum Thread', true) : '')
    . $anaButton($anaGithubUrl, 'GitHub', true)
    . '</div>';

return [
    'pluginVersion' => 'v1.0.4',
    'pluginName' => 'Authorize.Net Accept.js Payments',
    'pluginDescription' =>
        'Credit card payments through Authorize.Net\'s current JSON API with Accept.js tokenization, '
        . 'so the card number never touches your server. Replaces the retired AIM and SIM methods. '
        . 'Authorize, capture, refund and void from the order page, with a transaction history per order. '
        . 'Install it here, then enable and configure it under Modules &gt; Payment.'
        . $anaLinks,
    'pluginAuthor' => 'My Zen Cart Host (dbltoe)',
    'pluginId' => 2459, // Plugins Library ID since 2026-10-06 (the earlier 2261 was shared with another plugin)
    'zcVersions' => ['v158', 'v200', 'v210', 'v220', 'v230', 'v300'],
    'changelog' => 'changelog.txt',
    'github_repo' => $anaGithubUrl,
    'pluginGroups' => [],
];
