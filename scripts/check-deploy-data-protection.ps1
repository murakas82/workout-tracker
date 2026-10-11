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
if ($bash -match '(?m)^\s*php artisan .*?(?:--seed\b|\bdb:(?:seed|wipe)\b|\bmigrate:(?:fresh|refresh|reset|rollback)\b)') {
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

$secureCookies = [regex]::Matches($bash, '(?m)^SESSION_SECURE_COOKIE=[^\r\n]*')
if ($secureCookies.Count -ne 1 -or $secureCookies[0].Value -ne 'SESSION_SECURE_COOKIE=true') {
    throw "Production must enable secure session cookies."
}

$preflightFunction = $ast.Find({
    param($node)
    $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and
    $node.Name -eq 'Invoke-DeploymentPreflight'
}, $true)
if ($null -eq $preflightFunction -or
    $preflightFunction.Body.EndBlock.Statements.Count -ne 2 -or
    $preflightFunction.Body.EndBlock.Statements[0].Extent.Text.Trim() -ne
        'Write-Step "Checking deployment data protection"' -or
    $preflightFunction.Body.EndBlock.Statements[-1].Extent.Text.Trim() -ne
        '& (Join-Path $PSScriptRoot "check-deploy-data-protection.ps1")') {
    throw "Deployment preflight must execute the data protection check unconditionally."
}

$calls = @($ast.EndBlock.Statements | Where-Object {
    $_ -is [System.Management.Automation.Language.PipelineAst] -and
    $_.PipelineElements[0] -is [System.Management.Automation.Language.CommandAst]
})
$preflightCalls = @($calls | Where-Object { $_.PipelineElements[0].GetCommandName() -eq 'Invoke-DeploymentPreflight' })
$deploymentCalls = @($calls | Where-Object { $_.PipelineElements[0].GetCommandName() -in @('Push-Code', 'Invoke-RemoteDeploy', 'Invoke-FinalVerification') })
if ($preflightCalls.Count -ne 1 -or $deploymentCalls.Count -eq 0 -or
    @($deploymentCalls | Where-Object { $_.Extent.StartOffset -lt $preflightCalls[0].Extent.StartOffset }).Count -gt 0) {
    throw "Mandatory local preflight must run before Git push or SSH deployment."
}

Write-Host "PASS: deployment syntax, mandatory preflight, secure cookies, migration flags, and SQLite backup protection."
