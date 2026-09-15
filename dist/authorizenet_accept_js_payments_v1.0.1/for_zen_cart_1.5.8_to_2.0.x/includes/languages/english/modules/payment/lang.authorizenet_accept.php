<?php
/**
 * Authorize.Net Accept.js Payments -- language bridge for Zen Cart 1.5.8
 * through 2.0.x.
 *
 * Those releases load a payment module's language file only from this
 * folder, so this returns the plugin's own definitions. Upload it together
 * with includes/modules/payment/authorizenet_accept.php (the note there says
 * when). Another language needs a copy of this file under its own folder; the
 * text is the plugin's English either way.
 *
 * @package  AuthorizeNetAccept
 * @license  https://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU Public License V2.0
 */

if (!defined('DIR_FS_CATALOG')) {
    return [];
}

if (!function_exists('authorizenet_accept_bridge_file')) {
    /**
     * The path of a file inside the plugin, or '' when the plugin folder is
     * gone. The version the Plugin Manager has installed is preferred; with
     * none recorded (the language file is asked for before the install), the
     * highest version folder present is used.
     */
    function authorizenet_accept_bridge_file($relative)
    {
        global $installedPlugins;
        $base = DIR_FS_CATALOG . 'zc_plugins/AuthorizeNetAccept/';
        $versions = [];
        if (is_array($installedPlugins)) {
            foreach ($installedPlugins as $plugin) {
                if (is_array($plugin) && isset($plugin['unique_key'], $plugin['version']) && $plugin['unique_key'] === 'AuthorizeNetAccept') {
                    $versions[] = $plugin['version'];
                }
            }
        }
        $folders = glob($base . 'v*', GLOB_ONLYDIR);
        if (is_array($folders) && $folders !== []) {
            $folders = array_map('basename', $folders);
            usort($folders, 'version_compare');
            $versions = array_merge($versions, array_reverse($folders));
        }
        foreach ($versions as $version) {
            $file = $base . $version . '/' . $relative;
            if (is_file($file)) {
                return $file;
            }
        }
        return '';
    }
}

$anaBridgeLang = authorizenet_accept_bridge_file('catalog/includes/languages/english/modules/payment/lang.authorizenet_accept.php');
$define = ($anaBridgeLang !== '') ? require $anaBridgeLang : [];
if (!is_array($define)) {
    $define = [];
}
unset($anaBridgeLang);

return $define;
