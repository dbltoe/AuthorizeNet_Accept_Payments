<?php
/**
 * The pre-release security scan, as a test rather than a habit.
 *
 * The Plugins Library runs its own scan on submission; this runs the same
 * kind of checks first, plus the ones that matter for a payment module: the
 * card number and CVV inputs must have no name, the SDK must come from
 * Authorize.Net's hosts and nowhere else, secrets must be masked wherever
 * they are written down.
 *
 * A hit is a question, not a verdict. Where a pattern is legitimately
 * present, the exemption is written here with its reason.
 */

require __DIR__ . '/_bootstrap.php';

$PLUGIN = ana_plugin_dir();

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($PLUGIN, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile()) {
        $files[] = str_replace('\\', '/', $f->getPathname());
    }
}
$BRIDGE = ana_repo_root() . '/for_zen_cart_1.5.8_to_2.0.x';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($BRIDGE, FilesystemIterator::SKIP_DOTS)) as $f) {
    if ($f->isFile()) {
        $files[] = str_replace('\\', '/', $f->getPathname());
    }
}
sort($files);

function rel($path)
{
    global $PLUGIN, $BRIDGE;
    if (strpos($path, $BRIDGE . '/') === 0) {
        return basename($BRIDGE) . '/' . substr($path, strlen($BRIDGE) + 1);
    }
    return substr($path, strlen($PLUGIN) + 1);
}

function scan(array $files, $pattern, array $exempt = [], $onlyExt = null)
{
    $hits = [];
    foreach ($files as $path) {
        if ($onlyExt !== null && !in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $onlyExt, true)) {
            continue;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES) as $i => $line) {
            if (preg_match($pattern, $line) !== 1) {
                continue;
            }
            foreach ($exempt as $ex) {
                if (preg_match($ex, rel($path) . ':' . $line) === 1) {
                    continue 2;
                }
            }
            $hits[] = rel($path) . ':' . ($i + 1) . '  ' . trim($line);
        }
    }
    return $hits;
}

function report($label, array $hits)
{
    check($label, empty($hits));
    foreach (array_slice($hits, 0, 8) as $h) {
        echo "          $h\n";
    }
    if (count($hits) > 8) {
        echo '          ... and ' . (count($hits) - 8) . " more\n";
    }
}

echo 'scanning ' . count($files) . " shipped files\n";

section('dynamic code and process execution');
report('no eval/exec/system/passthru/shell_exec/proc_open/create_function/assert',
    scan($files, '~(?<![a-z_$>])(eval|exec|system|passthru|shell_exec|proc_open|create_function|assert|popen|pcntl_fork)\s*\(~i'));

section('encoding and obfuscation');
report('no base64/gzinflate/str_rot13/hex2bin',
    scan($files, '~\b(base64_(en|de)code|gzinflate|gzuncompress|str_rot13|hex2bin)\s*\(~i'));

section('remote calls and deserialisation');
/* The gateway call is the module's purpose. It lives in exactly one place,
 * the API client, over TLS with peer verification on. */
report('curl only in the API client, nothing else remote',
    scan($files, '~\b(unserialize|file_get_contents|curl_init|fsockopen|stream_socket_client)\s*\(~i', [
        '~^catalog/includes/modules/payment/authorizenet_accept/AuthorizeNetAcceptApi\.php:.*curl_init~',
    ]));
$apiSrc = file_get_contents($PLUGIN . '/catalog/includes/modules/payment/authorizenet_accept/AuthorizeNetAcceptApi.php');
check('the client verifies the gateway certificate', strpos($apiSrc, 'CURLOPT_SSL_VERIFYPEER, true') !== false && strpos($apiSrc, 'CURLOPT_SSL_VERIFYHOST, 2') !== false);
/* Comments may cite documentation URLs; code may not. Line comments are only
 * those preceded by whitespace, so the "//" inside "https://" survives. */
$apiCode = preg_replace('~/\*.*?\*/|(?<=\s)//[^\n]*~s', '', $apiSrc);
check('the client only ever posts to the two Authorize.Net endpoints',
    preg_match_all('~https://[a-z0-9.-]+~i', $apiCode, $m) > 0 && array_values(array_unique($m[0])) === ['https://api.authorize.net', 'https://apitest.authorize.net']);

section('DOM injection');
report('no innerHTML/outerHTML/document.write/insertAdjacentHTML',
    scan($files, '~\b(innerHTML|outerHTML|document\.write|insertAdjacentHTML)\b~i'));

section('third-party code');
$packed = [];
foreach ($files as $path) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, ['js', 'css', 'php'], true)) {
        continue;
    }
    if (preg_match('~\.min\.|packed|vendor|jquery|bootstrap~i', basename($path)) === 1) {
        $packed[] = rel($path) . '  (name suggests third-party or minified)';
        continue;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $i => $line) {
        if (strlen($line) > 500) {
            $packed[] = rel($path) . ':' . ($i + 1) . '  line is ' . strlen($line) . ' chars';
            break;
        }
    }
}
report('every shipped file is readable source', $packed);

section('off-site assets');
report('no script, stylesheet, image or iframe tag loads from off-site',
    scan($files, '~<(script|link|img|iframe|source|object|embed)\b[^>]*\b(src|href|data)\s*=\s*["\']?https?://~i'));
/* The Accept.js SDK is the exception and is built by the loader, not a tag:
 * Authorize.Net requires it from their host. Pin the two allowed URLs. */
$moduleSrc = file_get_contents($PLUGIN . '/catalog/includes/modules/payment/authorizenet_accept.php');
check('the SDK URLs are exactly the two Authorize.Net hosts',
    strpos($moduleSrc, "SCRIPT_SANDBOX = 'https://jstest.authorize.net/v1/Accept.js'") !== false
    && strpos($moduleSrc, "SCRIPT_PRODUCTION = 'https://js.authorize.net/v1/Accept.js'") !== false);
$scriptSrc = file_get_contents($PLUGIN . '/catalog/includes/modules/payment/authorizenet_accept/checkout_script.php');
check('the loader uses the configured URL and declares utf-8, as Authorize.Net requires', strpos($scriptSrc, 'tag.src = cfg.scriptUrl') !== false && strpos($scriptSrc, "tag.charset = 'utf-8'") !== false);
check('no other https URL appears in the checkout script', preg_match('~https?://~', $scriptSrc, $m, 0, strpos($scriptSrc, '<script>')) === 0);

section('cardholder data never posts');
check('the card number input has no name attribute', preg_match('~<input type="text" id="\' \. \$this->code \. \'-cc-number"[^>]*\'~', $moduleSrc, $m) === 1 && strpos($m[0], 'name=') === false);
check('the CVV input has no name attribute', preg_match('~<input type="text" id="\' \. \$this->code \. \'-cc-cvv"[^>]*\'~', $moduleSrc, $m) === 1 && strpos($m[0], 'name=') === false);
check('the expiry selects are drawn without names', strpos($moduleSrc, 'function unnamedSelect') !== false && preg_match('~<select id="\' \. \$id \. \'"~', $moduleSrc) === 1);
check('the script never reads the card fields by form name', strpos($scriptSrc, "formField(code + '-cc-number')") === false && strpos($scriptSrc, "byId(code + '-cc-number')") !== false);
check('the script only sends card data to Accept.dispatchData', substr_count($scriptSrc, 'cardNumber') === 1 && strpos($scriptSrc, 'window.Accept.dispatchData(secureData') !== false);
check('the script never logs the card or the nonce', preg_match('~console\.~', $scriptSrc) === 0);

section('secrets are masked wherever they are written down');
check('mask() blanks the transaction key, the nonce, card numbers and codes', preg_match_all("~\\\$key === '(transactionKey|dataValue|cardNumber|cardCode)'~", $apiSrc, $m) === 4);
check('the table and the log get the masked request', substr_count($moduleSrc, 'maskedRequest()') >= 3 && strpos($moduleSrc, 'lastRequest') === false);
check('the log file goes through the masked path only', strpos(file_get_contents($PLUGIN . '/catalog/includes/modules/payment/authorizenet_accept/AuthorizeNetAcceptLog.php'), 'lastRequest') === false);
check('the transaction key setting uses the password display', preg_match("~'TXNKEY'[^\n]*zen_cfg_password_display~", $moduleSrc) === 1);
check('the transaction key is never sent to the browser', strpos($moduleSrc, "cfg('TXNKEY')") !== false && preg_match("~'text' => \\[|'apiLoginID' => trim\\(\\\$this->cfg\\('LOGIN'\\)\\)~", $moduleSrc) === 1 && strpos(substr($moduleSrc, strpos($moduleSrc, '$anaScriptConfig = ['), 1200), 'TXNKEY') === false);

section('superglobals reaching SQL');
report('no $_GET/$_POST/$_REQUEST inside a query string',
    scan($files, '~(SELECT|INSERT\s+INTO|UPDATE|DELETE\s+FROM|WHERE|VALUES)\b[^;]*\$_(GET|POST|REQUEST|COOKIE)~i', [], ['php']));

section('superglobals reaching the filesystem');
report('no $_GET/$_POST/$_FILES reaching include/require/readfile/unlink',
    scan($files, '~\b(include|include_once|require|require_once|readfile|fopen|unlink|rename|copy)\s*\([^)]*\$_(GET|POST|REQUEST|FILES)~i', [], ['php']));

section('output escaping');
report('no unescaped variable echoed',
    scan($files, '~\becho\s+\$[a-z_]~i', [], ['php']));
$adminSrc = file_get_contents($PLUGIN . '/catalog/includes/modules/payment/authorizenet_accept/admin_notification.php');
/* Eight columns in the history row, each through the escaper; and no row value
 * concatenated straight into the markup. */
check('every database value on the order page is escaped', substr_count($adminSrc, 'zen_output_string_protected(') >= 8 && preg_match('~\. \$t\[[^\]]+\] \. \'<~', $adminSrc) === 0);
check('gateway text shown to the customer is escaped', strpos($moduleSrc, "zen_output_string_protected(\$txn['reasonText']") !== false && strpos($moduleSrc, "zen_output_string_protected(\$result['messageText']") !== false);

section('direct-access guards');
$noGuard = [];
foreach ($files as $path) {
    if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
        continue;
    }
    $r = rel($path);
    if (preg_match('~^(manifest\.php|Installer/|.*languages/)~', $r) === 1) {
        continue; // core convention: no guard on these
    }
    $head = implode("\n", array_slice(file($path, FILE_IGNORE_NEW_LINES), 0, 60));
    if (strpos($head, "defined('IS_ADMIN_FLAG')") === false) {
        $noGuard[] = $r;
    }
}
report('every included file carries the guard', $noGuard);

section('CSRF and confirmation');
check('the three order-page forms are drawn with the security-token flag', substr_count($adminSrc, "'post', '', true)") === 3);
check('refund, capture and void each require their confirmation box', substr_count($moduleSrc, "?? '') !== 'on')") === 3);

section('sanity of what a customer can send');
check('the nonce is shape-checked before it is used', strpos($moduleSrc, "preg_match('~^[A-Za-z0-9+/=._-]{16,16384}$~'") !== false);
check('the descriptor is whitelisted', strpos($moduleSrc, 'in_array($data[\'descriptor\'], $this->allowedDescriptors(), true)') !== false);
check('last4 and expiry (and the refund form\'s last four) are reduced to digits', substr_count($moduleSrc, "preg_replace('/\\D/', ''") === 3);

ana_done('security scan clean');
