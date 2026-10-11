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
