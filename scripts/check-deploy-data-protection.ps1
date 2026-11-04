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
if ($bash -match '(?m)^[ \t]*php\s+artisan\s+.*?(?:--seed\b|\bdb:(?:seed|wipe)\b|\bmigrate:(?:fresh|refresh|reset|rollback)\b)') {
    throw "Production deployment must not seed or reset the database."
}
$migrations = [regex]::Matches($bash, '(?m)^[ \t]*php\s+artisan\s+migrate(?=\s|$)(?<arguments>[^\r\n]*)')
if ($migrations.Count -eq 0 -or
    @($migrations | Where-Object { $_.Groups['arguments'].Value -notmatch '(?:^|\s)--force(?:\s|$)' }).Count -gt 0) {
    throw "Production must run non-interactive migrations with --force."
}

$backup = [regex]::Match($bash, '(?m)^backup_database "\$PREDEPLOY_BACKUP"\s*$')
if (-not $backup.Success -or $backup.Index -ge $migrations[0].Index) {
    throw "SQLite backup must run before migrations."
}
foreach ($required in @(
    'backup_database() {',
    'if [ ! -s "$DB_FILE" ]; then',
    'sqlite3 "$DB_FILE" ".backup ''$BACKUP_FILE''"',
    'chmod 600 "$BACKUP_FILE"'
)) {
    if (-not $bash.Contains($required)) {
        throw "Missing SQLite backup protection: $required"
    }
}

if ($bash -match '(?m)^[ \t]*cp\s+"\$DB_FILE"') {
    throw "Raw copying of the live SQLite database is unsafe."
}

$remotePreflight = [regex]::Match($bash, '(?ms)^remote_preflight\(\) \{\r?\n(?<body>.*?)^\}')
$remotePreflightCall = [regex]::Match($bash, '(?m)^remote_preflight[ \t]*\r?$')
$firstRemoteWrite = [regex]::Match($bash, '(?m)^(?:mkdir -p "\$APP"|git (?:init|remote|fetch|symbolic-ref|reset)|cat > \.env)')
if (-not $remotePreflight.Success -or
    $remotePreflight.Groups['body'].Value -notmatch '(?m)^\s*command\s+-v\s+sqlite3\b[^\r\n]*\|\|\s*fail\b' -or
    -not $remotePreflightCall.Success -or -not $firstRemoteWrite.Success -or
    $remotePreflightCall.Index -ge $firstRemoteWrite.Index) {
    throw "Remote preflight must check sqlite3 before modifying the application, environment, or repository."
}

if ($backup.Index -ge $firstRemoteWrite.Index) {
    throw "SQLite backup must run before production files change."
}

$migrationBackup = [regex]::Match($bash, '(?m)^backup_database "\$MIGRATION_BACKUP"\s*$')
$maintenance = [regex]::Match($bash, '(?m)^php artisan down\b[^\r\n]*')
if (-not $maintenance.Success -or -not $migrationBackup.Success -or
    $maintenance.Index -ge $migrationBackup.Index -or $migrationBackup.Index -ge $migrations[0].Index) {
    throw "A fresh migration backup must run in maintenance mode before migrations."
}
$backupFunction = [regex]::Match($bash, '(?ms)^backup_database\(\) \{\r?\n(?<body>.*?)^\}')
if ($backupFunction.Groups['body'].Value -match '(?m)^\s*(?:find|rm)\b') {
    throw "Backup creation must not prune recovery snapshots."
}
$complete = [regex]::Match($bash, '(?m)^complete_backups\s*$')
$healthCheck = [regex]::Match($bash, '(?m)^curl --fail[^\r\n]*?/login"')
if (-not $complete.Success -or -not $healthCheck.Success -or $complete.Index -le $healthCheck.Index) {
    throw "Backup retention must run only after successful health checks."
}

$secureCookies = [regex]::Matches($bash, '(?m)^SESSION_SECURE_COOKIE=[^\r\n]*')
if ($secureCookies.Count -ne 1 -or $secureCookies[0].Value -ne 'SESSION_SECURE_COOKIE=true') {
    throw "Production must enable secure session cookies."
}

# Find unconditional commands by scope, without assuming a particular statement
# layout, logging text, quote style, or number of statements in a function.
function Get-ScopeCommands {
    param(
        [System.Management.Automation.Language.ScriptBlockAst] $Scope,
        [switch] $IncludeConditional
    )

    $Scope.FindAll({ param($node) $node -is [System.Management.Automation.Language.CommandAst] }, $true) | Where-Object {
        $parent = $_.Parent
        while ($null -ne $parent -and $parent -ne $Scope) {
            if ($parent -is [System.Management.Automation.Language.ScriptBlockAst] -or
                $parent -is [System.Management.Automation.Language.CommandAst] -or
                (-not $IncludeConditional -and $parent -is [System.Management.Automation.Language.StatementAst] -and
                 $parent -isnot [System.Management.Automation.Language.PipelineBaseAst])) {
                return $false
            }
            $parent = $parent.Parent
        }
        return $parent -eq $Scope
    }
}

$calls = @(Get-ScopeCommands -Scope $ast)
$preflightCall = $calls | Where-Object { $_.GetCommandName() -eq 'Invoke-DeploymentPreflight' } | Select-Object -First 1
$deploymentCalls = @(Get-ScopeCommands -Scope $ast -IncludeConditional | Where-Object { $_.GetCommandName() -in @('Push-Code', 'Invoke-RemoteDeploy', 'Invoke-FinalVerification') })
if ($null -eq $preflightCall -or $deploymentCalls.Count -eq 0 -or
    @($deploymentCalls | Where-Object { $_.Extent.StartOffset -lt $preflightCall.Extent.StartOffset }).Count -gt 0) {
    throw "Mandatory local preflight must run before Git push or SSH deployment."
}

$preflightFunction = $ast.Find({
    param($node)
    $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq 'Invoke-DeploymentPreflight'
}, $true)
if ($null -eq $preflightFunction) { throw "Deployment preflight function was not found." }
$checkerCall = Get-ScopeCommands -Scope $preflightFunction.Body | Where-Object {
    $_.InvocationOperator -eq [System.Management.Automation.Language.TokenKind]::Ampersand -and
    $_.Extent.Text -match '["'']check-deploy-data-protection\.ps1["'']'
} | Select-Object -First 1
$earlyExits = @($preflightFunction.Body.FindAll({
    param($node)
    $node -is [System.Management.Automation.Language.ReturnStatementAst] -or
    $node -is [System.Management.Automation.Language.ExitStatementAst]
}, $false))
if ($null -eq $checkerCall -or
    @($earlyExits | Where-Object { $_.Extent.StartOffset -lt $checkerCall.Extent.StartOffset }).Count -gt 0) {
    throw "Deployment preflight must execute the data protection check unconditionally."
}

Write-Host "PASS: deployment syntax, mandatory preflight, secure cookies, migration flags, and SQLite backup protection."
