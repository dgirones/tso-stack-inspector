# TSO Stack Inspector - PHP lint + safety checks before ZIP
# Usage: .\scripts\phpcs-check.ps1

param(
    [string[]]$Paths = @(),
    [switch]$PhpLintOnly
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$Prefix = 'tsosi_'
$Fail = 0

function Write-Step($msg) {
    Write-Host "==> $msg" -ForegroundColor Cyan
}

function Write-Ok($msg) {
    Write-Host "OK: $msg" -ForegroundColor Green
}

function Write-Fail($msg) {
    Write-Host "FAIL: $msg" -ForegroundColor Red
    $script:Fail = 1
}

if ($Paths.Count -gt 0) {
    $PhpFiles = @()
    foreach ($p in $Paths) {
        $full = Join-Path $Root $p
        if (-not (Test-Path $full)) {
            Write-Fail "Path not found: $p"
            continue
        }
        if ((Get-Item $full).PSIsContainer) {
            $PhpFiles += Get-ChildItem $full -Recurse -Filter *.php |
                Where-Object { $_.FullName -notmatch '[\\/]vendor[\\/]' }
        } else {
            $PhpFiles += Get-Item $full
        }
    }
} else {
    $PhpFiles = Get-ChildItem $Root -Recurse -Filter *.php |
        Where-Object { $_.FullName -notmatch '[\\/]vendor[\\/]' }
}

if ($PhpFiles.Count -eq 0) {
    Write-Fail 'No PHP files to check.'
    exit 1
}

Write-Step 'PHP syntax (php -l)'
$phpCmd = Get-Command php -ErrorAction SilentlyContinue
if (-not $phpCmd) {
    Write-Host 'WARN: php not found in PATH - skip syntax check.' -ForegroundColor Yellow
} else {
    foreach ($f in $PhpFiles) {
        $out = & php -l $f.FullName 2>&1
        if ($LASTEXITCODE -ne 0) {
            Write-Fail "$($f.FullName.Substring($Root.Length + 1)): $out"
        }
    }
    if ($Fail -eq 0) { Write-Ok 'php -l' }
}

Write-Step "Duplicate function definitions ($Prefix*)"
$funcHits = @{}
foreach ($f in $PhpFiles) {
    $lines = Get-Content $f.FullName
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match "^\s*function\s+($([regex]::Escape($Prefix))\w+)\s*\(") {
            $name = $Matches[1]
            $rel = $f.FullName.Substring($Root.Length + 1)
            if (-not $funcHits.ContainsKey($name)) { $funcHits[$name] = @() }
            $funcHits[$name] += ($rel + ':' + ($i + 1))
        }
    }
}
foreach ($name in $funcHits.Keys) {
    if ($funcHits[$name].Count -gt 1) {
        Write-Fail "Duplicate $name at $($funcHits[$name] -join ', ')"
    }
}
if ($Fail -eq 0) { Write-Ok 'No duplicate functions' }

if (-not $PhpLintOnly) {
    Write-Step 'Direct $_POST/$_GET outside storage helpers'
    foreach ($f in $PhpFiles) {
        $leaf = Split-Path $f.FullName -Leaf
        if ($leaf -eq 'tsosi-storage.php') { continue }
        Select-String -Path $f.FullName -Pattern '\$_POST\[|\$_GET\[' | ForEach-Object {
            if ($_.Line -notmatch 'phpcs:ignore') {
                Write-Fail "$($f.FullName.Substring($Root.Length + 1)):$($_.LineNumber): direct superglobal"
            }
        }
    }
    if ($Fail -eq 0) { Write-Ok 'No direct superglobals outside storage' }

    $phpcs = Get-Command phpcs -ErrorAction SilentlyContinue
    if ($phpcs) {
        Write-Step 'PHPCS WordPress standard'
        & phpcs --standard=WordPress --extensions=php $Root
        if ($LASTEXITCODE -ne 0) { $Fail = 1 } else { Write-Ok 'PHPCS' }
    } else {
        Write-Host 'WARN: phpcs not in PATH - skipped.' -ForegroundColor Yellow
    }
}

if ($Fail -ne 0) { exit 1 }
Write-Host 'All checks passed.' -ForegroundColor Green
exit 0
