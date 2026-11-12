<?php
/**
 * Authorize.Net Accept.js Payments -- table constant (storefront).
 *
 * The admin copy of this file defines the same constant; the two are kept
 * separate because Zen Cart loads extra_datafiles per side.
 *
 * @package  AuthorizeNetAccept
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

if (!defined('TABLE_AUTHORIZENET_ACCEPT')) {
    define('TABLE_AUTHORIZENET_ACCEPT', DB_PREFIX . 'authorizenet_accept');
}
