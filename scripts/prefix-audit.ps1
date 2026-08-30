# TSO Stack Inspector - prefix audit before WordPress.org ZIP
# Usage: .\scripts\prefix-audit.ps1
$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$Violations = @()

$Patterns = @(
    @{ Label = 'class TSO_ (runtime)'; Regex = 'class TSO_[^S]' },
    @{ Label = 'function tso_'; Regex = '\bfunction tso_[a-z]' },
    @{ Label = 'wp_ajax_tso_ (not tsosi)'; Regex = 'wp_ajax_tso_[^s]' },
    @{ Label = 'bare tso_ option API'; Regex = "(get|update|delete)_option\s*\(\s*'tso_[^s]" },
    @{ Label = 'tsoSi JS global'; Regex = 'tsoSi' }
)

Get-ChildItem $Root -Recurse -Include *.php,*.js |
    Where-Object { $_.FullName -notmatch '[\\/]vendor[\\/]' } |
    ForEach-Object {
        $rel = $_.FullName.Substring($Root.Length + 1)
        foreach ($p in $Patterns) {
            Select-String -Path $_.FullName -Pattern $p.Regex -CaseSensitive -AllMatches | ForEach-Object {
                $Violations += [pscustomobject]@{
                    Rule = $p.Label
                    File = $rel
                    Line = $_.LineNumber
                    Text = $_.Line.Trim()
                }
            }
        }
    }

if ($Violations.Count -eq 0) {
    Write-Host 'OK: no prefix violations.' -ForegroundColor Green
    exit 0
}

Write-Host "FAIL: $($Violations.Count) violation(s):" -ForegroundColor Red
$Violations | Format-Table -AutoSize
exit 1
