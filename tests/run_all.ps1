<#
    Run the lint and every harness against every PHP version that can be found.

    The plugin claims PHP 7.4 through 8.5 from a single codebase, and that claim
    is only worth making if it is checked. Nothing here needs a web server or a
    database.

    PHP builds are looked for, in order:

      -PhpDir <path>        a directory holding php74\, php80\, ... php85\
      $env:ANA_PHP_DIR      the same, set once in your environment
      F:\zclab\php          the lab's builds, when this is the lab machine
      tests\php\            beside this script (gitignored)
      php on PATH           always tried, last, and labelled with its version

    A harness is green only when it prints its own "PASS:" line. Exit code alone
    is not enough: a die() exits 0 and would otherwise read as a pass while
    testing nothing.

    Exits non-zero if anything failed, so it can gate a release.
#>

[CmdletBinding()]
param(
    [string] $PhpDir = $env:ANA_PHP_DIR,
    # Run one harness rather than all of them, e.g. -Only module
    [string] $Only = ''
)

$here = $PSScriptRoot
$root = Split-Path $here -Parent

$searchRoots = @($PhpDir, 'F:\zclab\php', (Join-Path $here 'php')) | Where-Object { $_ -and (Test-Path $_) }

$versions = [ordered]@{}
foreach ($v in '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5') {
    foreach ($searchRoot in $searchRoots) {
        $exe = Join-Path $searchRoot ("php" + $v.Replace('.', '') + "\php.exe")
        if (Test-Path $exe) { $versions[$v] = $exe; break }
    }
}

$onPath = (Get-Command php -ErrorAction SilentlyContinue).Source
if ($onPath) {
    $banner = (& $onPath -v 2>&1 | Select-Object -First 1)
    if ($banner -match 'PHP (\d+\.\d+)') {
        $pathVersion = $Matches[1]
        if (-not $versions.Contains($pathVersion)) { $versions[$pathVersion] = $onPath }
    }
}

if ($versions.Count -eq 0) {
    Write-Error "no PHP found. Put builds in tests\php\php74\ (and so on), set ANA_PHP_DIR, or put php on PATH."
    exit 2
}

# Every .php in here except the shared scaffolding, so a new harness cannot be
# silently skipped by a glob.
$harnesses = @(Get-ChildItem $here -Filter '*.php' |
               Where-Object { $_.BaseName -ne '_bootstrap' } |
               Select-Object -ExpandProperty BaseName |
               Sort-Object -Unique)
if ($Only) { $harnesses = @($harnesses | Where-Object { $_ -like "*$Only*" }) }
if ($harnesses.Count -eq 0) { Write-Error "no harness matched '$Only'"; exit 2 }

$shipped = @(Get-ChildItem (Join-Path $root 'zc_plugins') -Recurse -Filter '*.php' | Select-Object -ExpandProperty FullName)

$noise = '(?m)^(PHP )?(Warning|Deprecated|Notice|Fatal error|Parse error|Strict Standards):'
$problems = @()

foreach ($v in $versions.Keys) {
    $exe = $versions[$v]
    $line = "{0,-5} " -f $v

    # Lint every shipped file first: a parse error on one version is the whole point.
    $lintFailed = @()
    foreach ($file in $shipped) {
        $out = (& $exe -l $file 2>&1) -join "`n"
        if ($out -notmatch 'No syntax errors') { $lintFailed += "$file`n$out" }
    }
    $line += if ($lintFailed.Count -eq 0) { 'L' } else { 'X' }
    if ($lintFailed.Count -gt 0) { $problems += "=== PHP $v / lint ===`n" + ($lintFailed -join "`n") }

    foreach ($h in $harnesses) {
        $out = (& $exe -d error_reporting=E_ALL "$here\$h.php" 2>&1) -join "`n"
        $passed = ($LASTEXITCODE -eq 0) -and ($out -match '(?m)^PASS:') -and ($out -notmatch $noise)
        $line += if ($passed) { '.' } else { 'X' }
        if (-not $passed) {
            $detail = ($out -split "`n" |
                       Select-String -Pattern 'FAIL|ABORT|Warning|Deprecated|Fatal|Parse error' |
                       Select-Object -First 4) -join "`n"
            if (-not $detail) { $detail = "no PASS: line -- did it exit early?`n" + ($out -split "`n" | Select-Object -Last 3 | Out-String) }
            $problems += "=== PHP $v / $h ===`n$detail"
        }
    }
    $line
}

''
"order: lint, $($harnesses -join ', ')"

$missing = @('7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5') | Where-Object { -not $versions.Contains($_) }
if ($missing) { "not checked: PHP $($missing -join ', ') -- no build found" }

if ($problems) {
    ''
    '--- PROBLEMS ---'
    $problems | Select-Object -First 8
    exit 1
}

''
"ALL GREEN ($($versions.Count) x $($harnesses.Count + 1) runs)"
exit 0
