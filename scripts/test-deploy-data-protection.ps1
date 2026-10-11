# Test static checks and the extracted preflight with local stubs only.
# Never execute deploy-zone.ps1, Git push, or SSH.
$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest

$deployPath = Join-Path $PSScriptRoot 'deploy-zone.ps1'
$checkerPath = Join-Path $PSScriptRoot 'check-deploy-data-protection.ps1'
$source = (Get-Content -LiteralPath $deployPath -Raw) -replace "`r`n", "`n"
$fixtureRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('workout-deploy-check-tests-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $fixtureRoot | Out-Null
$script:Passed = 0

function New-CheckFixture {
    param([string] $Name, [string] $DeploySource)

    $directory = Join-Path $fixtureRoot $Name
    New-Item -ItemType Directory -Path $directory | Out-Null
    Set-Content -LiteralPath (Join-Path $directory 'deploy-zone.ps1') -Value $DeploySource -Encoding UTF8
    Copy-Item -LiteralPath $checkerPath -Destination (Join-Path $directory 'check-deploy-data-protection.ps1')
    return $directory
}

function Invoke-FixtureScript {
    param([string] $Path)

    # Windows PowerShell treats native stderr as ErrorRecords. Capture expected
    # failing child scripts without terminating this test runner prematurely.
    $previousPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = @(& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $Path 2>&1)
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousPreference
    }

    return @{ ExitCode = $exitCode; Output = ($output | Out-String) }
}

try {
    $validDirectory = New-CheckFixture -Name 'valid' -DeploySource $source
    $result = Invoke-FixtureScript -Path (Join-Path $validDirectory 'check-deploy-data-protection.ps1')
    if ($result.ExitCode -ne 0) { throw "Valid deployment failed checks: $($result.Output)" }
    $script:Passed++

    $cases = @(
        @{ Name = 'migration-seed'; Source = $source.Replace('php artisan migrate --force --ansi', 'php artisan migrate --force --seed --ansi'); Error = 'Production must use exactly' },
        @{ Name = 'separate-seed'; Source = $source.Replace('php artisan view:cache --ansi', "php artisan view:cache --ansi`nphp artisan db:seed --force"); Error = 'must not seed or reset' },
        @{ Name = 'database-wipe'; Source = $source.Replace('php artisan view:cache --ansi', "php artisan view:cache --ansi`nphp artisan db:wipe --force"); Error = 'must not seed or reset' },
        @{ Name = 'backup-missing'; Source = $source.Replace("`nbackup_database`n", "`n# backup omitted`n"); Error = 'backup must run before migrations' },
        @{ Name = 'secure-cookie-disabled'; Source = $source.Replace('SESSION_SECURE_COOKIE=true', 'SESSION_SECURE_COOKIE=false'); Error = 'enable secure session cookies' },
        @{ Name = 'preflight-missing'; Source = $source.Replace("`nInvoke-DeploymentPreflight`n", "`n# preflight omitted`n"); Error = 'preflight must run before' },
        @{ Name = 'preflight-too-late'; Source = $source.Replace("`nInvoke-DeploymentPreflight`n", "`n").Replace("`nPush-Code`n", "`nPush-Code`nInvoke-DeploymentPreflight`n"); Error = 'preflight must run before' },
        @{ Name = 'dry-run-bypass'; Source = $source.Replace('function Invoke-DeploymentPreflight {', "function Invoke-DeploymentPreflight {`n    if (`$DryRun) { return }"); Error = 'check unconditionally' },
        @{ Name = 'early-return'; Source = $source.Replace('Write-Step "Checking deployment data protection"', 'return'); Error = 'check unconditionally' },
        @{ Name = 'syntax-error'; Source = $source + "`nif (`n"; Error = 'syntax errors' }
    )
    foreach ($command in @('migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback')) {
        $cases += @{ Name = $command.Replace(':', '-'); Source = $source.Replace('php artisan view:cache --ansi', "php artisan view:cache --ansi`nphp artisan $command --force"); Error = 'Production must use exactly' }
    }

    foreach ($case in $cases) {
        if ($case.Source -ceq $source) { throw "Fixture '$($case.Name)' did not change the deployment source." }
        $directory = New-CheckFixture -Name $case.Name -DeploySource $case.Source
        $result = Invoke-FixtureScript -Path (Join-Path $directory 'check-deploy-data-protection.ps1')
        if ($result.ExitCode -eq 0 -or $result.Output -notmatch $case.Error) {
            throw "Fixture '$($case.Name)' was not rejected correctly: $($result.Output)"
        }
        $script:Passed++
    }

    $tokens = $null
    $parseErrors = $null
    $ast = [System.Management.Automation.Language.Parser]::ParseFile($deployPath, [ref] $tokens, [ref] $parseErrors)
    $preflight = $ast.Find({
        param($node)
        $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and
        $node.Name -eq 'Invoke-DeploymentPreflight'
    }, $true)
    $harness = '$ErrorActionPreference = "Stop"' + "`n" + @'
function Write-Step { param([string] $Message) }
function Push-Code { Set-Content -LiteralPath (Join-Path $PSScriptRoot 'push-called') -Value 'stub' }
function Invoke-RemoteDeploy { Set-Content -LiteralPath (Join-Path $PSScriptRoot 'ssh-called') -Value 'stub' }
'@ + "`n" + $preflight.Extent.Text + "`nInvoke-DeploymentPreflight`nPush-Code`nInvoke-RemoteDeploy`n"

    foreach ($mode in @('pass', 'reject', 'missing-checker')) {
        $fixtureSource = if ($mode -eq 'reject') { $source.Replace('php artisan migrate --force --ansi', 'php artisan migrate --force --seed --ansi') } else { $source }
        $directory = New-CheckFixture -Name ('harness-' + $mode) -DeploySource $fixtureSource
        if ($mode -eq 'missing-checker') { Remove-Item -LiteralPath (Join-Path $directory 'check-deploy-data-protection.ps1') }
        $harnessPath = Join-Path $directory 'harness.ps1'
        Set-Content -LiteralPath $harnessPath -Value $harness -Encoding UTF8
        $result = Invoke-FixtureScript -Path $harnessPath
        $pushCalled = Test-Path -LiteralPath (Join-Path $directory 'push-called')
        $sshCalled = Test-Path -LiteralPath (Join-Path $directory 'ssh-called')
        if ($mode -eq 'pass') {
            if ($result.ExitCode -ne 0 -or -not $pushCalled -or -not $sshCalled) { throw "Valid preflight did not reach local stubs: $($result.Output)" }
        } elseif ($result.ExitCode -eq 0 -or $pushCalled -or $sshCalled) {
            throw "Failed preflight reached a push/SSH stub: $($result.Output)"
        }
        $script:Passed++
    }

    Write-Host "PASS: $script:Passed deployment regression checks; no Git push or SSH executed."
} finally {
    # Delete only this runner's verified, unique directory under the system temp root.
    $resolvedFixtureRoot = [System.IO.Path]::GetFullPath($fixtureRoot)
    $resolvedTempRoot = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath()).TrimEnd('\') + '\'
    if (-not $resolvedFixtureRoot.StartsWith($resolvedTempRoot, [StringComparison]::OrdinalIgnoreCase) -or
        (Split-Path -Leaf $resolvedFixtureRoot) -notlike 'workout-deploy-check-tests-*') {
        throw 'Refusing to remove an unexpected fixture directory.'
    }
    Remove-Item -LiteralPath $resolvedFixtureRoot -Recurse -Force
}
