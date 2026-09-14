<?php
/**
 * The Plugin Manager installer: creates the table, refuses what it must
 * (releases before 1.5.8; 1.5.8 through 2.0.x without the two bridge files),
 * and on uninstall removes the payment module's settings.
 *
 * The version cases need PROJECT_VERSION_* constants with other values, and a
 * constant cannot be redefined, so those run in child processes:
 *   php installer_check.php old-zc | old-zc-bridged | zc158a | ancient-zc
 */

require __DIR__ . '/_bootstrap.php';

$mode = $argv[1] ?? 'normal';

define('IS_ADMIN_FLAG', true);
define('DB_PREFIX', 'zen_');
define('TABLE_CONFIGURATION', 'zen_configuration');
define('PLUGIN_INSTALL_SQL_FAILURE', 'x');
$versions = [
    'normal' => ['2', '2.2'],
    'old-zc' => ['2', '0.1'],
    'old-zc-bridged' => ['2', '0.1'],
    'zc158a' => ['1', '5.8a'],
    'ancient-zc' => ['1', '5.7c'],
];
if (!isset($versions[$mode])) {
    fwrite(STDERR, "unknown mode $mode\n");
    exit(2);
}
define('PROJECT_VERSION_MAJOR', $versions[$mode][0]);
define('PROJECT_VERSION_MINOR', $versions[$mode][1]);

/* A throwaway store root, with the two bridge files present only when the mode says so. */
$storeRoot = str_replace('\\', '/', sys_get_temp_dir()) . '/ana_installer_' . getmypid() . '_' . $mode . '/';
$bridgeFiles = [
    'includes/modules/payment/authorizenet_accept.php',
    'includes/languages/english/modules/payment/lang.authorizenet_accept.php',
];
ana_rm_tree($storeRoot);
@mkdir($storeRoot, 0777, true);
if (in_array($mode, ['old-zc-bridged', 'zc158a'], true)) {
    foreach ($bridgeFiles as $file) {
        @mkdir(dirname($storeRoot . $file), 0777, true);
        file_put_contents($storeRoot . $file, "<?php // bridge\n");
    }
}
define('DIR_FS_CATALOG', $storeRoot);
register_shutdown_function(static function () use ($storeRoot) {
    ana_rm_tree($storeRoot);
});

eval('namespace Zencart\PluginSupport; class ScriptedInstaller {
    protected $dbConn; protected $errorContainer; protected $pluginDir = "";
    public function __construct($d, $e) { $this->dbConn = $d; $this->errorContainer = $e; }
    public function doInstall() { return $this->executeInstall(); }
    public function doUninstall() { return $this->executeUninstall(); }
    public function doUpgrade($v = null) { return $this->executeUpgrade($v); }
    protected function executeInstall() { return true; }
    protected function executeUninstall() { return true; }
    protected function executeUpgrade($v = null) { return true; }
    protected function executeInstallerSql($sql) { return $this->dbConn->Execute($sql) !== false; }
}');

class AnaErrors
{
    public $errors = [];
    public function addError($severity, $message, $useForFriendly = false, $friendly = '')
    {
        $this->errors[] = ['message' => $message, 'friendly' => $useForFriendly ? $message : $friendly];
    }
}

$PLUGIN = ana_plugin_dir();
require $PLUGIN . '/Installer/ScriptedInstaller.php';

if ($mode !== 'normal') {
    $db = new AnaDb();
    $errors = new AnaErrors();
    $ok = (new ScriptedInstaller($db, $errors))->doInstall();
    $friendly = $errors->errors[0]['friendly'] ?? '';
    switch ($mode) {
        case 'old-zc':
            check('Zen Cart 2.0.1 without the bridge files is refused', $ok === false && count($errors->errors) === 1);
            check('the refusal names the bridge folder and both files',
                strpos($friendly, 'for_zen_cart_1.5.8_to_2.0.x') !== false
                && strpos($friendly, $bridgeFiles[0]) !== false
                && strpos($friendly, $bridgeFiles[1]) !== false);
            check('nothing was written', $db->log === []);
            break;
        case 'old-zc-bridged':
            check('Zen Cart 2.0.1 with the bridge files installs', $ok === true && $errors->errors === []);
            check('the table is created', count($db->log) === 1 && strpos($db->log[0], 'CREATE TABLE IF NOT EXISTS') === 0);
            break;
        case 'zc158a':
            check('1.5.8a is not read as older than 1.5.8 (version_compare treats the letter as a pre-release)', $ok === true && $errors->errors === []);
            break;
        case 'ancient-zc':
            check('1.5.7 is refused', $ok === false && count($errors->errors) === 1);
            check('the refusal names 1.5.8 and the store\'s version', strpos($friendly, '1.5.8') !== false && strpos($friendly, '1.5.7c') !== false);
            check('nothing was written', $db->log === []);
            break;
    }
    ana_done("$mode handled");
}

section('install');
$db = new AnaDb();
$errors = new AnaErrors();
$ok = (new ScriptedInstaller($db, $errors))->doInstall();
check('install succeeds on 2.2.2', $ok === true && $errors->errors === []);
check('one statement: create the table if it is not there', count($db->log) === 1 && strpos($db->log[0], 'CREATE TABLE IF NOT EXISTS zen_authorizenet_accept') === 0);
foreach (['orders_id', 'customers_id', 'session_id', 'transaction_type', 'trans_id', 'ref_trans_id', 'network_trans_id', 'response_code', 'reason_code', 'response_text', 'auth_code', 'avs_code', 'cvv_code', 'account_type', 'account_number', 'amount decimal(15,4)', 'currency char(3)', 'payment_source', 'request_json mediumtext', 'response_json mediumtext', 'date_added datetime'] as $column) {
    check("column $column", strpos($db->log[0], $column) !== false);
}
check('indexed by order and transaction id', strpos($db->log[0], 'KEY idx_ana_orders_id (orders_id)') !== false && strpos($db->log[0], 'KEY idx_ana_trans_id (trans_id)') !== false);
check('InnoDB', strpos($db->log[0], 'ENGINE=InnoDB') !== false);
check('no configuration rows: those belong to Modules > Payment', $db->matching('zen_configuration') === []);
check('2.2.2 needs no bridge files: none were looked for in the store root', !is_dir($storeRoot . 'includes'));

section('upgrade');
$db = new AnaDb();
(new ScriptedInstaller($db, new AnaErrors()))->doUpgrade('v0.9.0');
check('an upgrade re-runs the idempotent table create', count($db->log) === 1 && strpos($db->log[0], 'CREATE TABLE IF NOT EXISTS') === 0);

section('refusals other than the release');
$src = file_get_contents($PLUGIN . '/Installer/ScriptedInstaller.php');
check('Zen Cart 1.5.8 is the floor and 2.1.0 is where the bridge stops being needed', strpos($src, "MIN_ZC_VERSION = '1.5.8'") !== false && strpos($src, "BRIDGE_BELOW_ZC_VERSION = '2.1.0'") !== false);
check('the bridge folder and files the installer names are the ones in the package', strpos($src, "BRIDGE_FOLDER = 'for_zen_cart_1.5.8_to_2.0.x'") !== false && strpos($src, "'" . $bridgeFiles[0] . "'") !== false && strpos($src, "'" . $bridgeFiles[1] . "'") !== false && is_file(ana_repo_root() . '/for_zen_cart_1.5.8_to_2.0.x/' . $bridgeFiles[0]) && is_file(ana_repo_root() . '/for_zen_cart_1.5.8_to_2.0.x/' . $bridgeFiles[1]));
check('the store version is compared digits-only, so 1.5.8a is not a pre-release', strpos($src, "preg_replace('/[^0-9.]/', '', ") !== false);
check('PHP 7.4 is the floor', strpos($src, "MIN_PHP_VERSION = '7.4.0'") !== false);
check('a missing version constant loads includes/version.php rather than reading as 0.0.0 (found on the _test223 CLI install)', strpos($src, "require DIR_FS_CATALOG . 'includes/version.php';") !== false);
check('curl and json are required', strpos($src, "function_exists('curl_init')") !== false && strpos($src, "function_exists('json_encode')") !== false);
check('refusals go through the error container as friendly messages', substr_count($src, '$this->errorContainer->addError(0, $message, true, $message)') === 1);
check('the installer never writes into the core folders: the owner uploads the bridge', preg_match('~\b(copy|file_put_contents|unlink|rename|mkdir)\s*\(~', $src) === 0);

section('uninstall');
// The module's own remove() needs its class loadable; STATUS defined means "installed under Modules > Payment".
define('MODULE_PAYMENT_AUTHORIZENET_ACCEPT_STATUS', 'True');
define('MODULE_PAYMENT_AUTHORIZENET_ACCEPT_SORT_ORDER', '0');
$db = new AnaDb();
$db->answers["configuration_key = 'MODULE_PAYMENT_INSTALLED'"] = [['configuration_value' => 'authorizenet_accept.php;cod.php']];
$ok = (new ScriptedInstaller($db, new AnaErrors()))->doUninstall();
check('uninstall succeeds', $ok === true);
check('the module settings are deleted', count($db->matching("DELETE FROM zen_configuration WHERE configuration_key LIKE 'MODULE\\_PAYMENT\\_AUTHORIZENET\\_ACCEPT\\_%'")) === 1);
check('the module leaves MODULE_PAYMENT_INSTALLED', count($db->matching("SET configuration_value = 'cod.php'")) === 1);
check('no settings stash is written and any old one is deleted', $db->matching('INSERT INTO') === [] && count($db->matching("configuration_key = 'AUTHORIZENET_ACCEPT_SETTINGS_STASH'")) >= 1);
check('the transaction table is kept', $db->matching('DROP TABLE') === []);

section('the version cases, in child processes');
foreach (['old-zc', 'old-zc-bridged', 'zc158a', 'ancient-zc'] as $child) {
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . $child . ' 2>&1');
    $childOk = is_string($out) && strpos($out, 'PASS:') !== false && strpos($out, 'FAIL') === false;
    check("$child child passed", $childOk);
    if (!$childOk) {
        echo $out;
    }
}

ana_done('installer creates the table and refuses cleanly');
