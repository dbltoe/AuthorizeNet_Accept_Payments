<#
    Rebuild dist/ and the release zip from the source tree.

    dist/ is a copy, so it goes stale the moment a source file is edited and the
    package is not rebuilt; tests\readme_check.php catches that. This script is
    the answer: one command, no hand-copying, and it verifies what it produced.

    Output: dist\authorizenet_accept_js_payments_vX.Y.Z\ and, one level above
    the repository, authorizenet_accept_js_payments_vX.Y.Z.zip.
#>

$root = Split-Path $PSScriptRoot -Parent

$pluginDir = @(Get-ChildItem (Join-Path $root 'zc_plugins\AuthorizeNetAccept\v*') -Directory |
               Where-Object { Test-Path (Join-Path $_.FullName 'manifest.php') })
if ($pluginDir.Count -ne 1) {
    throw "expected one plugin version directory under $root\zc_plugins\AuthorizeNetAccept, found $($pluginDir.Count)"
}

$version = $pluginDir[0].Name
$name    = "authorizenet_accept_js_payments_$version"
"building : $name"
"from     : $root"
$dist    = Join-Path $root "dist\$name"
$zip     = Join-Path (Split-Path $root -Parent) "$name.zip"

# What goes in the package, and nothing else: no .git, no tests, no dist.
$topLevel = @('CHANGELOG.md', 'LICENSE', 'README.md')
$docs     = @('COMPATIBILITY.md', 'CONFIGURATION.md', 'CUSTOMIZING.md', 'INSTALL.md')

if (Test-Path $dist) { Remove-Item $dist -Recurse -Force }
New-Item -ItemType Directory -Path $dist -Force | Out-Null

foreach ($f in $topLevel) { Copy-Item (Join-Path $root $f) (Join-Path $dist $f) }

New-Item -ItemType Directory -Path (Join-Path $dist 'docs') -Force | Out-Null
foreach ($f in $docs) { Copy-Item (Join-Path $root "docs\$f") (Join-Path $dist "docs\$f") }

# The whole plugin, in its zc_plugins path, exactly as it must be uploaded.
Copy-Item (Join-Path $root 'zc_plugins') (Join-Path $dist 'zc_plugins') -Recurse

# The bridge files for Zen Cart 1.5.8 - 2.0.x, in the folder the docs name.
Copy-Item (Join-Path $root 'for_zen_cart_1.5.8_to_2.0.x') (Join-Path $dist 'for_zen_cart_1.5.8_to_2.0.x') -Recurse

# readme.html again at the package root, so it can be opened straight out of
# the download without digging four levels down.
Copy-Item (Join-Path $root "zc_plugins\AuthorizeNetAccept\$version\readme.html") (Join-Path $dist 'readme.html')

if (Test-Path $zip) { Remove-Item $zip -Force }
Add-Type -AssemblyName System.IO.Compression.FileSystem
Add-Type -AssemblyName System.IO.Compression

# Built entry by entry rather than with Compress-Archive, so the count is checked.
$archive = [System.IO.Compression.ZipFile]::Open($zip, 'Create')
$files = Get-ChildItem $dist -Recurse -File | Sort-Object FullName
foreach ($f in $files) {
    $entryName = "$name/" + $f.FullName.Substring($dist.Length + 1).Replace('\', '/')
    [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
        $archive, $f.FullName, $entryName, [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
}
$archive.Dispose()

$verify = [System.IO.Compression.ZipFile]::OpenRead($zip)
$entryCount = $verify.Entries.Count
$verify.Dispose()

"dist files : $($files.Count)"
"zip entries: $entryCount"
if ($entryCount -ne $files.Count) { throw "zip is short $($files.Count - $entryCount) file(s)" }
"zip bytes  : $((Get-Item $zip).Length)"
"sha256     : $((Get-FileHash $zip -Algorithm SHA256).Hash.ToLower())"
"zip        : $zip"
