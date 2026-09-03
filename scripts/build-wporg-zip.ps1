# Build a WordPress.org-ready ZIP for TSO Stack Inspector.
# Usage: .\scripts\build-wporg-zip.ps1
# Output: parent folder\tso-stack-inspector-VERSION.zip (folder slug = product slug)

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$Slug = 'tso-stack-inspector'
$Main = Join-Path $Root "$Slug.php"
if (-not (Test-Path $Main)) {
    throw "Main plugin file not found: $Main"
}

$Version = '1.0.0'
$header = Get-Content $Main -Raw
if ($header -match '(?m)^\s*\*\s*Version:\s*(\S+)') {
    $Version = $Matches[1]
}

$OutDir = Split-Path -Parent $Root
$ZipPath = Join-Path $OutDir "$Slug-$Version.zip"
$Stage = Join-Path $env:TEMP "tso-wporg-zip-$Slug"
$StagePlugin = Join-Path $Stage $Slug

if (Test-Path $Stage) {
    Remove-Item $Stage -Recurse -Force
}
New-Item -ItemType Directory -Path $StagePlugin | Out-Null

$ExcludeDirNames = @(
    '.git', '.github', '.idea', '.vscode', 'vendor', 'node_modules', 'tests', 'bin'
)
$ExcludeFileNames = @(
    'composer.json', 'composer.lock', 'phpstan.neon', 'phpunit.xml', 'phpunit.xml.dist',
    'package.json', 'package-lock.json', '.pcp-blueprint.json', '.gitignore', '.gitattributes',
    'compile-mo.py'
)
$ExcludeRelPrefixes = @(
    'scripts\'
)

Get-ChildItem $Root -Force | ForEach-Object {
    $name = $_.Name
    if ($ExcludeDirNames -contains $name) {
        return
    }
    if ($_.PSIsContainer -and ($name -eq 'scripts')) {
        return
    }
    Copy-Item $_.FullName -Destination (Join-Path $StagePlugin $name) -Recurse -Force
}

# Drop known non-.org files that may have been copied with languages/
$compileMo = Join-Path $StagePlugin 'languages\compile-mo.py'
if (Test-Path $compileMo) {
    Remove-Item $compileMo -Force
}
$blueprint = Join-Path $StagePlugin '.pcp-blueprint.json'
if (Test-Path $blueprint) {
    Remove-Item $blueprint -Force
}

if (Test-Path $ZipPath) {
    Remove-Item $ZipPath -Force
}

Add-Type -AssemblyName System.IO.Compression.FileSystem
[System.IO.Compression.ZipFile]::CreateFromDirectory($Stage, $ZipPath, [System.IO.Compression.CompressionLevel]::Optimal, $false)

Remove-Item $Stage -Recurse -Force

Write-Host "OK: $ZipPath" -ForegroundColor Green
Write-Host "Folder inside ZIP must be: $Slug/" -ForegroundColor Cyan
