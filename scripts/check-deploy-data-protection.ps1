# Static checks only: never execute or dot-source the deployment script.
$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest

$deployPath = Join-Path $PSScriptRoot "deploy-zone.ps1"
$tokens = $null
$parseErrors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile(
    $deployPath, [ref] $tokens, [ref] $parseErrors
)
if ($parseErrors.Count -gt 0) {
    throw "Deployment script has syntax errors: $($parseErrors -join '; ')"
}

$remoteScript = $ast.Find({
    param($node)
    $node -is [System.Management.Automation.Language.StringConstantExpressionAst] -and
    $node.StringConstantType -eq [System.Management.Automation.Language.StringConstantType]::SingleQuotedHereString -and
    $node.Value.StartsWith('set -euo pipefail')
}, $true)
if ($null -eq $remoteScript) {
    throw "Remote deployment script was not found."
}

$bash = $remoteScript.Value
$migrations = [regex]::Matches($bash, '(?m)^php artisan migrate[^\r\n]*')
if ($migrations.Count -ne 1 -or $migrations[0].Value -ne 'php artisan migrate --force --ansi') {
    throw "Production must use exactly: php artisan migrate --force --ansi"
}
if ($bash -match '(?m)^\s*php artisan .*?(?:--seed\b|\bdb:seed\b|\bmigrate:(?:fresh|refresh|reset)\b)') {
    throw "Production deployment must not seed or reset the database."
}

$backup = [regex]::Match($bash, '(?m)^backup_database\s*$')
if (-not $backup.Success -or $backup.Index -ge $migrations[0].Index) {
    throw "SQLite backup must run before migrations."
}
foreach ($required in @(
    'backup_database() {',
    'if [ ! -s "$DB_FILE" ]; then',
    'sqlite3 "$DB_FILE" ".backup ''$BACKUP_FILE''"',
    'cp "$DB_FILE" "$BACKUP_FILE"',
    'chmod 600 "$BACKUP_FILE"'
)) {
    if (-not $bash.Contains($required)) {
        throw "Missing SQLite backup protection: $required"
    }
}

Write-Host "PASS: deployment syntax, migration flags, and SQLite backup protection."
