<?php
/**
 * Plugin Manager's Upgrade trap, checked structurally.
 *
 * An Upgrade runs the NEW version's Installer/ScriptedInstaller.php in an admin
 * request that has already loaded the INSTALLED version's admin start-up files
 * (extra_datafiles, extra_configures, extra_functions, init_includes, the
 * auto_loaders and what they name, auto.* observers, extra_definitions). Those
 * declared their classes and functions from zc_plugins/<Key>/<old>/. require_once
 * dedupes by file path, not by name, so the installer requiring the same class
 * or function file from zc_plugins/<Key>/<new>/ fatals ("Cannot redeclare"),
 * plugin_control stays at the old version and a logs/myDEBUG-adm-*.log appears.
 * Found on _test223 upgrading Subscriptions Pro v1.0.0 to v1.0.1, 2026-10-04.
 *
 * The rule: every require reachable from Installer/ (directly or nested) whose
 * target declares a class/interface/trait/enum or function that the admin
 * start-up also declares must sit directly under its own guard,
 *     if (!class_exists('X', false)) {        or     if (!function_exists('x')) {
 * unless the target guards itself (each declaration inside such an if, or an
 * early "if (defined('X_LOADED')) { return; }"). An installer-reachable require
 * whose path can't be worked out statically must be guarded the same way or be
 * listed in $UGC_ALLOW below with the reason it's safe. A value-returning
 * include ("$x = require $file;", "return include ...") is a data file
 * (a language array) and is skipped. Core files (DIR_FS_/DIR_WS_ paths with no
 * zc_plugins in them) are skipped.
 *
 * The rule passes code that would not fatal and fails the day an installer
 * (or a file it pulls in) starts requiring a start-up file unguarded. A
 * self-test runs the rule on small fixture plugins first, so a green run can't
 * be a scanner that matches nothing.
 *
 * Self-contained (no _bootstrap.php) and identical in every repo except
 * $UGC_ALLOW, so the copies can be diffed against each other.
 */

// Installer-reachable requires that can't be resolved statically, accepted with a reason.
// 'path/relative/to/plugin.php' => ['text in the require expression' => 'why it is safe'].
$UGC_ALLOW = [
];

error_reporting(E_ALL);

$ugcFailures = 0;

function ugc_check($label, $ok)
{
    global $ugcFailures;
    echo ($ok ? '  ok    ' : '  FAIL  '), $label, "\n";
    if (!$ok) {
        $ugcFailures++;
    }
}

function ugc_note($text)
{
    echo '  --    ', $text, "\n";
}

function ugc_section($title)
{
    echo "\n", $title, "\n";
}

function ugc_norm($path)
{
    $path = str_replace('\\', '/', $path);
    $abs = $path !== '' && $path[0] === '/' ? '/' : '';
    $drive = '';
    if (preg_match('~^([A-Za-z]:)/~', $path, $m)) {
        $drive = $m[1];
        $path = substr($path, 2);
        $abs = '/';
    }
    $out = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..') {
            array_pop($out);
            continue;
        }
        $out[] = $seg;
    }
    return $drive . $abs . implode('/', $out);
}

function ugc_key($path)
{
    return strtolower(ugc_norm($path));
}

function ugc_rel($plugin, $path)
{
    $p = ugc_norm($plugin);
    $f = ugc_norm($path);
    return strtolower(substr($f, 0, strlen($p) + 1)) === strtolower($p . '/') ? substr($f, strlen($p) + 1) : $f;
}

function ugc_inside($plugin, $path)
{
    return strtolower(substr(ugc_norm($path), 0, strlen(ugc_norm($plugin)) + 1)) === strtolower(ugc_norm($plugin) . '/');
}

function ugc_tokens($file)
{
    $out = [];
    foreach (token_get_all((string)file_get_contents($file)) as $t) {
        $out[] = is_array($t) ? $t : [0, $t, 0];
    }
    return $out;
}

function ugc_significant(array $toks, $i)
{
    return !in_array($toks[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
}

function ugc_is_name_token($t)
{
    $ids = [T_STRING];
    foreach (['T_NAME_FULLY_QUALIFIED', 'T_NAME_QUALIFIED'] as $c) {
        if (defined($c)) {
            $ids[] = constant($c);
        }
    }
    return in_array($t[0], $ids, true);
}

/**
 * Evaluate a require expression made only of __DIR__, __FILE__, dirname(...),
 * string literals, '.' and parentheses. Returns the path, or null when it
 * involves anything else (a variable, a constant, a call).
 */
function ugc_eval(array $expr, $file)
{
    $i = 0;
    $v = ugc_eval_concat($expr, $i, $file);
    return ($v !== null && $i === count($expr)) ? $v : null;
}

function ugc_eval_concat(array $e, &$i, $file)
{
    $v = ugc_eval_term($e, $i, $file);
    while ($v !== null && $i < count($e) && $e[$i][1] === '.') {
        $i++;
        $w = ugc_eval_term($e, $i, $file);
        $v = $w === null ? null : $v . $w;
    }
    return $v;
}

function ugc_eval_term(array $e, &$i, $file)
{
    if (!isset($e[$i])) {
        return null;
    }
    $t = $e[$i];
    if ($t[0] === T_DIR) {
        $i++;
        return dirname($file);
    }
    if ($t[0] === T_FILE) {
        $i++;
        return $file;
    }
    if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
        $i++;
        $s = substr($t[1], 1, -1);
        return ($t[1][0] === '"' && strpos($s, '$') !== false) ? null : $s;
    }
    if ($t[1] === '(') {
        $i++;
        $v = ugc_eval_concat($e, $i, $file);
        if ($v === null || !isset($e[$i]) || $e[$i][1] !== ')') {
            return null;
        }
        $i++;
        return $v;
    }
    if (ugc_is_name_token($t) && strtolower(ltrim($t[1], '\\')) === 'dirname' && isset($e[$i + 1]) && $e[$i + 1][1] === '(') {
        $i += 2;
        $v = ugc_eval_concat($e, $i, $file);
        $levels = 1;
        if ($v !== null && isset($e[$i]) && $e[$i][1] === ',') {
            $i++;
            if (!isset($e[$i]) || $e[$i][0] !== T_LNUMBER) {
                return null;
            }
            $levels = (int)$e[$i][1];
            $i++;
        }
        if ($v === null || !isset($e[$i]) || $e[$i][1] !== ')') {
            return null;
        }
        $i++;
        return dirname($v, $levels);
    }
    return null;
}

/**
 * What one PHP file declares and requires.
 *  decls:    [key => guarded?]  (classes keyed 'c:name', functions 'f:name', lower-cased)
 *  names:    [key => the name as written]
 *  loaded:   true when the file returns early on a defined() check before any declaration
 *  requires: list of [line, expr text, path|null, guardLine, valueReturning]
 */
function ugc_scan($file)
{
    $toks = ugc_tokens($file);
    $n = count($toks);
    $src = (string)file_get_contents($file);
    $decls = [];
    $names = [];
    $requires = [];
    $stack = [];          // frames: ['kind' => class|function|block|interp, 'head' => text]
    $head = '';           // statement text since the last ; { }
    $firstDecl = null;
    $classKinds = [T_CLASS, T_INTERFACE, T_TRAIT];
    if (defined('T_ENUM')) {
        $classKinds[] = constant('T_ENUM');
    }
    for ($i = 0; $i < $n; $i++) {
        $t = $toks[$i];
        $isCurlyOpen = $t[1] === '{' || $t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES;
        if ($isCurlyOpen) {
            if ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                $kind = 'interp';
            } elseif (preg_match('~(?:^|[^:$>\w])(?:class|interface|trait|enum)\s+\w+~i', $head)) {
                $kind = 'class';
            } elseif (preg_match('~\bfunction\b|\bfn\b~i', $head)) {
                $kind = 'function';
            } else {
                $kind = 'block';
            }
            $stack[] = ['kind' => $kind, 'head' => $head];
            $head = '';
            continue;
        }
        if ($t[1] === '}') {
            array_pop($stack);
            $head = '';
            continue;
        }
        if ($t[1] === ';') {
            $head = '';
            continue;
        }
        $head .= $t[1];

        $inCode = true;
        foreach ($stack as $f) {
            if ($f['kind'] === 'class' || $f['kind'] === 'function') {
                $inCode = false;
            }
        }

        // Declarations at file level (top level or inside plain if-blocks).
        if ($inCode && (in_array($t[0], $classKinds, true) || $t[0] === T_FUNCTION)) {
            $j = $i + 1;
            while ($j < $n && !ugc_significant($toks, $j)) {
                $j++;
            }
            if ($t[0] === T_FUNCTION && $j < $n && $toks[$j][1] === '&') {
                $j++;
                while ($j < $n && !ugc_significant($toks, $j)) {
                    $j++;
                }
            }
            $k = $i - 1;
            while ($k >= 0 && !ugc_significant($toks, $k)) {
                $k--;
            }
            $prev = $k >= 0 ? $toks[$k] : [0, '', 0];
            $anonymous = $prev[0] === T_NEW || $prev[0] === T_DOUBLE_COLON;
            if (!$anonymous && $j < $n && $toks[$j][0] === T_STRING) {
                $name = $toks[$j][1];
                $key = ($t[0] === T_FUNCTION ? 'f:' : 'c:') . strtolower($name);
                $fn = $t[0] === T_FUNCTION ? 'function_exists' : 'class_exists';
                $guarded = false;
                foreach ($stack as $f) {
                    if ($f['kind'] === 'block' && preg_match('~!\s*\\\\?' . $fn . '\s*\(\s*[\'"]\\\\?' . preg_quote($name, '~') . '[\'"]~i', $f['head'])) {
                        $guarded = true;
                    }
                }
                $decls[$key] = $guarded || !empty($decls[$key]);
                $names[$key] = $name;
                if ($firstDecl === null) {
                    $firstDecl = $t[2];
                }
            }
        }

        // Requires and includes, anywhere in the file.
        if (in_array($t[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) {
            $expr = [];
            $depth = 0;
            for ($j = $i + 1; $j < $n; $j++) {
                $u = $toks[$j];
                if ($u[1] === ';' || $u[0] === T_CLOSE_TAG) {
                    break;
                }
                if ($u[1] === '(' || $u[1] === '[') {
                    $depth++;
                } elseif ($u[1] === ')' || $u[1] === ']') {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                } elseif ($u[1] === ',' && $depth === 0) {
                    break;
                }
                if (ugc_significant($toks, $j)) {
                    $expr[] = $u;
                }
            }
            $k = $i - 1;
            while ($k >= 0 && !ugc_significant($toks, $k)) {
                $k--;
            }
            $prev = $k >= 0 ? $toks[$k] : [0, '', 0];
            $valueReturning = $prev[1] === '=' || $prev[0] === T_RETURN || $prev[1] === '(' || $prev[1] === ',';
            $text = trim(implode('', array_map(function ($u) {
                return $u[1];
            }, $expr)));
            $lines = preg_split('~\R~', $src);
            $guardLine = '';
            for ($l = $t[2] - 2; $l >= 0; $l--) {
                if (trim($lines[$l]) !== '') {
                    $guardLine = trim($lines[$l]);
                    break;
                }
            }
            $requires[] = [$t[2], $text, ugc_eval($expr, $file), $guardLine, $valueReturning];
        }
    }
    $loaded = $firstDecl !== null
        && preg_match('~^\s*if\s*\(\s*defined\s*\(\s*[\'"]\w+[\'"]\s*\)\s*\)\s*\{\s*return\s*;\s*\}~m', $src, $m, PREG_OFFSET_CAPTURE)
        && substr_count(substr($src, 0, $m[0][1]), "\n") + 1 < $firstDecl;
    return ['decls' => $decls, 'names' => $names, 'loaded' => (bool)$loaded, 'requires' => $requires];
}

function ugc_startup_files($plugin)
{
    $a = $plugin . '/admin/includes';
    $files = [];
    foreach (['extra_datafiles', 'extra_configures', 'functions/extra_functions', 'init_includes', 'auto_loaders'] as $d) {
        foreach (glob("$a/$d/*.php") ?: [] as $f) {
            $files[] = $f;
        }
    }
    foreach (glob("$a/classes/observers/auto.*.php") ?: [] as $f) {
        $files[] = $f;
    }
    foreach (glob("$a/languages/*/extra_definitions/*.php") ?: [] as $f) {
        $files[] = $f;
    }
    foreach (glob("$a/auto_loaders/*.php") ?: [] as $f) {
        preg_match_all('~[\'"]loadFile[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]~', (string)file_get_contents($f), $m);
        foreach ($m[1] as $lf) {
            foreach (["$a/classes/$lf", "$a/init_includes/$lf", "$a/$lf"] as $c) {
                if (is_file($c)) {
                    $files[] = $c;
                    break;
                }
            }
        }
    }
    return $files;
}

/**
 * Run the rule on one plugin version folder.
 * Returns ['violations' => [...], 'stats' => [...]].
 */
function ugc_analyze($plugin, array $allow)
{
    $plugin = ugc_norm($plugin);
    $scans = [];
    $scan = function ($f) use (&$scans) {
        $k = ugc_key($f);
        if (!isset($scans[$k])) {
            $scans[$k] = ugc_scan($f);
        }
        return $scans[$k];
    };
    $closure = function (array $roots) use ($plugin, $scan) {
        $seen = [];
        $queue = $roots;
        while ($queue) {
            $f = ugc_norm(array_shift($queue));
            $k = ugc_key($f);
            if (isset($seen[$k]) || !is_file($f)) {
                continue;
            }
            $seen[$k] = $f;
            foreach ($scan($f)['requires'] as $r) {
                if ($r[2] !== null && ugc_inside($plugin, $r[2]) && is_file($r[2])) {
                    $queue[] = $r[2];
                }
            }
        }
        return $seen;
    };

    // Names the admin start-up declares, and from which file.
    $startup = $closure(ugc_startup_files($plugin));
    $startupNames = [];
    foreach ($startup as $f) {
        foreach (array_keys($scan($f)['decls']) as $name) {
            $startupNames[$name] = ugc_rel($plugin, $f);
        }
    }

    // Everything the installer can reach, and every require edge on the way.
    $installerRoots = [];
    foreach (is_dir($plugin . '/Installer') ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($plugin . '/Installer', FilesystemIterator::SKIP_DOTS)) : [] as $f) {
        if ($f->getExtension() === 'php') {
            $installerRoots[] = $f->getPathname();
        }
    }
    $reach = $closure($installerRoots);
    $violations = [];
    $edges = 0;
    foreach ($reach as $f) {
        $s = $scan($f);
        $rel = ugc_rel($plugin, $f);
        if (strpos(strtolower($rel), 'installer/') === 0) {
            foreach ($s['decls'] as $name => $guarded) {
                if (isset($startupNames[$name]) && !$guarded) {
                    $violations[] = "$rel declares " . $s['names'][$name] . ', which the start-up already declared (' . $startupNames[$name] . ')';
                }
            }
        }
        foreach ($s['requires'] as $r) {
            list($line, $text, $path, $guardLine, $valueReturning) = $r;
            $where = "$rel:$line";
            if ($path === null) {
                if ($valueReturning) {
                    continue;
                }
                if (preg_match('~^\\\\?DIR_(?:FS|WS)_\w+~', $text) && stripos($text, 'zc_plugins') === false) {
                    continue;
                }
                if (!$startupNames) {
                    continue;
                }
                $allowed = false;
                foreach ($allow[$rel] ?? [] as $needle => $why) {
                    if (strpos($text, $needle) !== false) {
                        $allowed = true;
                    }
                }
                if (!$allowed && !preg_match('~^if\s*\(\s*!\s*(?:class_exists|function_exists)\s*\(~i', $guardLine)) {
                    $violations[] = "$where requires a path it can't resolve ($text): guard it or list it in \$UGC_ALLOW with the reason";
                }
                continue;
            }
            if (!ugc_inside($plugin, $path) || !is_file($path)) {
                continue;
            }
            $edges++;
            $t = $scan($path);
            if ($t['loaded']) {
                continue;
            }
            $clash = [];
            foreach ($t['decls'] as $name => $guarded) {
                if (!$guarded && isset($startupNames[$name])) {
                    $clash[] = $name;
                }
            }
            if (!$clash) {
                continue;
            }
            $ok = false;
            foreach (array_keys($t['decls']) as $name) {
                $bare = preg_quote($t['names'][$name], '~');
                $re = $name[0] === 'c'
                    ? '~^if\s*\(\s*!\s*\\\\?class_exists\s*\(\s*[\'"]\\\\?' . $bare . '[\'"]\s*,\s*false\s*\)\s*\)\s*\{$~i'
                    : '~^if\s*\(\s*!\s*\\\\?function_exists\s*\(\s*[\'"]\\\\?' . $bare . '[\'"]\s*\)\s*\)\s*\{$~i';
                if (preg_match($re, $guardLine)) {
                    $ok = true;
                }
            }
            if (!$ok) {
                $violations[] = "$where requires " . ugc_rel($plugin, $path) . ' unguarded; the start-up already declared '
                    . implode(', ', array_map(function ($n) use ($t) {
                        return $t['names'][$n];
                    }, array_slice($clash, 0, 3)))
                    . ' (from ' . $startupNames[$clash[0]] . ')';
            }
        }
    }
    return [
        'violations' => $violations,
        'stats'      => ['startup' => count($startup), 'names' => count($startupNames), 'reach' => count($reach), 'edges' => $edges],
    ];
}

function ugc_fixture(array $files)
{
    $dir = str_replace('\\', '/', sys_get_temp_dir()) . '/ugc_' . getmypid() . '_' . mt_rand();
    foreach ($files as $rel => $code) {
        $path = "$dir/zc_plugins/Fx/v1.0.0/$rel";
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, "<?php\n" . $code . "\n");
    }
    return $dir;
}

function ugc_rmtree($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $p) {
        $p->isDir() ? rmdir($p->getPathname()) : unlink($p->getPathname());
    }
    rmdir($dir);
}

function ugc_fixture_violations(array $files, array $allow = [])
{
    $dir = ugc_fixture($files);
    $r = ugc_analyze("$dir/zc_plugins/Fx/v1.0.0", $allow);
    ugc_rmtree($dir);
    return count($r['violations']);
}

// ---------------------------------------------------------------------------

ugc_section('the rule catches the trap (fixture plugins)');
$datafile = ['admin/includes/extra_datafiles/fx.php' => "require_once dirname(__DIR__, 3) . '/shared/FxCore.php';"];
$core = ['shared/FxCore.php' => "class FxCore\n{\n}"];
ugc_check('installer require_once of a start-up class, unguarded: caught', ugc_fixture_violations($datafile + $core + [
    'Installer/ScriptedInstaller.php' => "require_once dirname(__DIR__) . '/shared/FxCore.php';\nclass ScriptedInstaller\n{\n}",
]) === 1);
ugc_check('the same require under its class_exists guard: passes', ugc_fixture_violations($datafile + $core + [
    'Installer/ScriptedInstaller.php' => "if (!class_exists('FxCore', false)) {\n    require_once dirname(__DIR__) . '/shared/FxCore.php';\n}\nclass ScriptedInstaller\n{\n}",
]) === 0);
ugc_check('a guard naming another class: caught', ugc_fixture_violations($datafile + $core + [
    'Installer/ScriptedInstaller.php' => "if (!class_exists('Other', false)) {\n    require_once dirname(__DIR__) . '/shared/FxCore.php';\n}\nclass ScriptedInstaller\n{\n}",
]) === 1);
ugc_check('nested: installer loads a class the start-up never did, which requires a start-up class unguarded: caught', ugc_fixture_violations($datafile + $core + [
    'shared/FxMore.php' => "require_once __DIR__ . '/FxCore.php';\nclass FxMore\n{\n}",
    'Installer/ScriptedInstaller.php' => "if (!class_exists('FxMore', false)) {\n    require_once dirname(__DIR__) . '/shared/FxMore.php';\n}\nclass ScriptedInstaller\n{\n}",
]) === 1);
ugc_check('a require inside an installer method counts too: caught', ugc_fixture_violations($datafile + $core + [
    'Installer/ScriptedInstaller.php' => "class ScriptedInstaller\n{\n    protected function executeUpgrade()\n    {\n        require_once dirname(__DIR__) . '/shared/FxCore.php';\n    }\n}",
]) === 1);
$fnStart = ['admin/includes/classes/observers/auto.fx.php' => "require_once __DIR__ . '/../../../../shared/fx_functions.php';\nclass zcObserverFx\n{\n}"];
ugc_check('a start-up functions file the installer requires unguarded: caught', ugc_fixture_violations($fnStart + [
    'shared/fx_functions.php' => "function fx_one()\n{\n}\nfunction fx_two()\n{\n}",
    'Installer/ScriptedInstaller.php' => "require_once dirname(__DIR__) . '/shared/fx_functions.php';\nclass ScriptedInstaller\n{\n}",
]) === 1);
ugc_check('the same functions file with an early defined() return: passes', ugc_fixture_violations($fnStart + [
    'shared/fx_functions.php' => "if (defined('FX_LOADED')) {\n    return;\n}\ndefine('FX_LOADED', true);\nfunction fx_one()\n{\n}",
    'Installer/ScriptedInstaller.php' => "require_once dirname(__DIR__) . '/shared/fx_functions.php';\nclass ScriptedInstaller\n{\n}",
]) === 0);
ugc_check('a class file whose body sits inside its own class_exists guard: passes', ugc_fixture_violations($datafile + [
    'shared/FxCore.php' => "if (!class_exists('FxCore', false)) {\nclass FxCore\n{\n}\n}",
    'Installer/ScriptedInstaller.php' => "require_once dirname(__DIR__) . '/shared/FxCore.php';\nclass ScriptedInstaller\n{\n}",
]) === 0);
ugc_check('an installer that loads nothing the start-up loads: passes', ugc_fixture_violations($datafile + $core + [
    'shared/FxOther.php' => "class FxOther\n{\n}",
    'Installer/ScriptedInstaller.php' => "require_once dirname(__DIR__) . '/shared/FxOther.php';\nclass ScriptedInstaller\n{\n}",
]) === 0);
ugc_check('an auto_loader class file counts as start-up: caught', ugc_fixture_violations([
    'admin/includes/auto_loaders/config.fx.php' => "\$autoLoadConfig[90][] = ['autoType' => 'class', 'loadFile' => 'FxFields.php'];",
    'admin/includes/classes/FxFields.php' => "class FxFields\n{\n}",
    'Installer/ScriptedInstaller.php' => "require_once dirname(__DIR__) . '/admin/includes/classes/FxFields.php';\nclass ScriptedInstaller\n{\n}",
]) === 1);
$dynamic = $datafile + $core + [
    'Installer/ScriptedInstaller.php' => "class ScriptedInstaller\n{\n    protected function executeUninstall()\n    {\n        \$file = 'x';\n        require_once \$file;\n        \$lang = require \$file;\n        require DIR_FS_CATALOG . 'includes/version.php';\n    }\n}",
];
ugc_check('an unresolvable require in the installer: caught (a language-array load and a core file are not)', ugc_fixture_violations($dynamic) === 1);
ugc_check('the same require listed in $UGC_ALLOW: passes', ugc_fixture_violations($dynamic, ['Installer/ScriptedInstaller.php' => ['$file' => 'fixture']]) === 0);

// ---------------------------------------------------------------------------

$repo = dirname(__DIR__);
$plugins = [];
foreach (glob($repo . '/zc_plugins/*/*', GLOB_ONLYDIR) ?: [] as $d) {
    if (is_file("$d/manifest.php")) {
        $plugins[] = $d;
    }
}
ugc_section('this plugin');
ugc_check('one plugin version folder under zc_plugins/ (' . count($plugins) . ')', count($plugins) === 1);
if (count($plugins) === 1) {
    $plugin = ugc_norm($plugins[0]);
    $label = substr($plugin, strlen(ugc_norm($repo)) + 1);
    ugc_check("$label has Installer/ScriptedInstaller.php", is_file("$plugin/Installer/ScriptedInstaller.php"));
    $r = ugc_analyze($plugin, $UGC_ALLOW);
    $st = $r['stats'];
    ugc_note("start-up loads {$st['startup']} file(s) declaring {$st['names']} name(s); the installer reaches {$st['reach']} file(s) over {$st['edges']} require(s) inside the plugin");
    foreach ($r['violations'] as $v) {
        ugc_check($v, false);
    }
    ugc_check('the new installer never redeclares what the installed version loaded at start-up', $r['violations'] === []);
    foreach ($UGC_ALLOW as $file => $list) {
        foreach ($list as $needle => $why) {
            ugc_note("allowed: $file \"$needle\" -- $why");
        }
    }
}

echo "\n";
if ($ugcFailures > 0) {
    echo "$ugcFailures check(s) FAILED\n";
    exit(1);
}
echo "PASS: upgrade guard rule holds\n";
