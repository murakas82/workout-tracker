<#
.SYNOPSIS
Deploy Workout tracker to a Zone-style SSH host.

.DESCRIPTION
Deployment settings are loaded from scripts/deploy-zone.local.json by default.
That local config file is ignored by Git so host names, account names, paths,
and email addresses do not end up in the public repository.

.EXAMPLE
.\scripts\deploy-zone.ps1

.EXAMPLE
.\scripts\deploy-zone.ps1 -RunLocalChecks

.EXAMPLE
.\scripts\deploy-zone.ps1 -DryRun
#>

[CmdletBinding()]
param(
    [string] $ConfigPath = (Join-Path $PSScriptRoot "deploy-zone.local.json"),
    [string] $SshUser,
    [string] $SshHost,
    [string] $HostKeyAlias,
    [string] $IdentityFile,
    [string] $RemoteApp,
    [string] $RemoteBareRepo,
    [string] $RemoteWeb,
    [string] $PublicUrl,
    [string] $MailFromAddress,
    [string] $AppName,
    [switch] $RunLocalChecks,
    [switch] $SkipOriginPush,
    [switch] $AllowDirty,
    [switch] $DryRun
)

$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest

$deployConfig = @{}
if (Test-Path -LiteralPath $ConfigPath) {
    $configJson = Get-Content -LiteralPath $ConfigPath -Raw | ConvertFrom-Json
    foreach ($property in $configJson.PSObject.Properties) {
        $deployConfig[$property.Name] = [string] $property.Value
    }
}

function Write-Step {
    param([string] $Message)

    Write-Host ""
    Write-Host "==> $Message" -ForegroundColor Cyan
}

function Resolve-DeploySetting {
    param(
        [Parameter(Mandatory = $true)][string] $Name,
        [string] $Value,
        [string] $Default,
        [switch] $Required
    )

    if (-not [string]::IsNullOrWhiteSpace($Value)) {
        return $Value
    }

    if ($deployConfig.ContainsKey($Name) -and -not [string]::IsNullOrWhiteSpace($deployConfig[$Name])) {
        return $deployConfig[$Name]
    }

    if (-not [string]::IsNullOrWhiteSpace($Default)) {
        return $Default
    }

    if ($Required) {
        throw "Missing deployment setting '$Name'. Add it to $ConfigPath or pass -$Name."
    }

    return $null
}

function ConvertTo-BashSingleQuoted {
    param([Parameter(Mandatory = $true)][string] $Value)

    return "'" + $Value.Replace("'", "'\''") + "'"
}

function ConvertTo-UriPath {
    param([Parameter(Mandatory = $true)][string] $Url)

    $uri = [Uri] $Url
    $path = $uri.AbsolutePath.TrimEnd("/")

    if ([string]::IsNullOrWhiteSpace($path)) {
        return ""
    }

    return $path
}

function Invoke-External {
    param(
        [Parameter(Mandatory = $true)][string] $FilePath,
        [Parameter(Mandatory = $true)][string[]] $Arguments,
        [string] $InputText
    )

    $display = "$FilePath $($Arguments -join ' ')"
    Write-Host $display -ForegroundColor DarkGray

    if ($DryRun) {
        return
    }

    if ($PSBoundParameters.ContainsKey("InputText")) {
        $InputText | & $FilePath @Arguments
    } else {
        & $FilePath @Arguments
    }

    if ($LASTEXITCODE -ne 0) {
        throw "Command failed with exit code ${LASTEXITCODE}: $display"
    }
}

function Invoke-Git {
    param([Parameter(Mandatory = $true)][string[]] $Arguments)

    Invoke-External -FilePath "git" -Arguments $Arguments
}

function Invoke-Ssh {
    param(
        [Parameter(Mandatory = $true)][string] $RemoteCommand,
        [string] $InputText
    )

    $args = @(
        "-i", $script:IdentityFile,
        "-o", "IdentitiesOnly=yes",
        "-o", "HostKeyAlias=$script:HostKeyAlias",
        "$script:SshUser@$script:SshHost",
        $RemoteCommand
    )

    if ($PSBoundParameters.ContainsKey("InputText")) {
        Invoke-External -FilePath "ssh" -Arguments $args -InputText $InputText
    } else {
        Invoke-External -FilePath "ssh" -Arguments $args
    }
}

function Resolve-Settings {
    $script:SshUser = Resolve-DeploySetting -Name "SshUser" -Value $SshUser -Required
    $script:SshHost = Resolve-DeploySetting -Name "SshHost" -Value $SshHost -Required
    $script:HostKeyAlias = Resolve-DeploySetting -Name "HostKeyAlias" -Value $HostKeyAlias -Required
    $script:IdentityFile = Resolve-DeploySetting -Name "IdentityFile" -Value $IdentityFile -Required
    $script:RemoteApp = Resolve-DeploySetting -Name "RemoteApp" -Value $RemoteApp -Required
    $script:RemoteBareRepo = Resolve-DeploySetting -Name "RemoteBareRepo" -Value $RemoteBareRepo -Required
    $script:RemoteWeb = Resolve-DeploySetting -Name "RemoteWeb" -Value $RemoteWeb -Required
    $script:PublicUrl = Resolve-DeploySetting -Name "PublicUrl" -Value $PublicUrl -Required
    $script:MailFromAddress = Resolve-DeploySetting -Name "MailFromAddress" -Value $MailFromAddress -Default "hello@example.com"
    $script:AppName = Resolve-DeploySetting -Name "AppName" -Value $AppName -Default "Workout tracker"
    $script:PublicBasePath = ConvertTo-UriPath -Url $script:PublicUrl
}

function Assert-RepoReady {
    Write-Step "Checking local repository"

    if (-not $DryRun -and -not (Test-Path -LiteralPath $script:IdentityFile)) {
        throw "SSH identity file not found: $script:IdentityFile"
    }

    $branch = (git branch --show-current).Trim()
    if ($LASTEXITCODE -ne 0) {
        throw "Could not read current Git branch."
    }

    if ($branch -ne "main") {
        throw "Refusing to deploy branch '$branch'. Switch to main first."
    }

    Invoke-Git -Arguments @("diff", "--check")

    $dirty = (git status --porcelain)
    if ($dirty -and -not $AllowDirty) {
        Write-Host $dirty
        throw "Refusing to deploy with uncommitted changes. Commit or stash first, or pass -AllowDirty intentionally."
    }
}

function Invoke-OptionalLocalChecks {
    if (-not $RunLocalChecks) {
        Write-Step "Skipping local tests/build"
        Write-Host "Use -RunLocalChecks when local vendor/ and node_modules/ are installed." -ForegroundColor DarkGray
        return
    }

    Write-Step "Running local checks"

    if (-not (Test-Path "vendor\autoload.php")) {
        throw "vendor/autoload.php is missing. Run composer install, or omit -RunLocalChecks."
    }

    if (-not (Test-Path "node_modules")) {
        throw "node_modules is missing. Run npm install, or omit -RunLocalChecks."
    }

    Invoke-External -FilePath "composer" -Arguments @("exec", "--", "php", "artisan", "test", "--ansi")
    Invoke-External -FilePath "npm" -Arguments @("run", "build")
}

function Push-Code {
    Write-Step "Pushing source"

    if (-not $SkipOriginPush) {
        Invoke-Git -Arguments @("push", "origin", "main")
    }

    $identityForGit = $script:IdentityFile.Replace("\", "/")
    $gitSshCommand = "ssh -i $identityForGit -o IdentitiesOnly=yes -o HostKeyAlias=$script:HostKeyAlias"
    $zoneRepo = "ssh://$script:SshUser@$script:SshHost$script:RemoteBareRepo"

    Invoke-Git -Arguments @("-c", "core.sshCommand=$gitSshCommand", "push", $zoneRepo, "HEAD:main")
}

function New-RemoteDeployScript {
    $script = @'
set -euo pipefail

APP=__REMOTE_APP__
BARE=__REMOTE_BARE_REPO__
WEB=__REMOTE_WEB__
PUBLIC_URL=__PUBLIC_URL__
BASE_URI_PATH=__PUBLIC_BASE_PATH__
APP_NAME=__APP_NAME__
MAIL_FROM_ADDRESS=__MAIL_FROM_ADDRESS__

fail() {
    echo "$1" >&2
    exit 1
}

case "$APP" in /*) ;; *) fail "RemoteApp must be an absolute path." ;; esac
case "$BARE" in /*.git) ;; *) fail "RemoteBareRepo must be an absolute .git path." ;; esac
case "$WEB" in /*) ;; *) fail "RemoteWeb must be an absolute path." ;; esac

[ "$APP" != "/" ] || fail "Refusing to deploy to /."
[ "$BARE" != "/" ] || fail "Refusing to use / as bare repo."
[ "$WEB" != "/" ] || fail "Refusing to publish to /."

APP_URL="${PUBLIC_URL%/}"
SCRIPT_NAME="${BASE_URI_PATH}/index.php"
REWRITE_BASE="${BASE_URI_PATH}/"
SESSION_PATH="$BASE_URI_PATH"

if [ -z "$BASE_URI_PATH" ]; then
    SCRIPT_NAME="/index.php"
    REWRITE_BASE="/"
    SESSION_PATH="/"
fi

mkdir -p "$APP" "$WEB"
if [ ! -d "$BARE" ]; then
    git init --bare "$BARE"
fi

git --git-dir="$BARE" --work-tree="$APP" checkout -f main
cd "$APP"

EXISTING_KEY=""
if [ -f .env ]; then
    EXISTING_KEY=$(grep '^APP_KEY=' .env | cut -d= -f2- || true)
fi

cat > .env <<EOF
APP_NAME="$APP_NAME"
APP_ENV=production
APP_KEY=$EXISTING_KEY
APP_DEBUG=false
APP_URL=$APP_URL
ASSET_URL=$APP_URL
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US
APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=12
LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=error
DB_CONNECTION=sqlite
DB_DATABASE=$APP/database/database.sqlite
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=$SESSION_PATH
SESSION_DOMAIN=null
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database
MAIL_MAILER=log
MAIL_SCHEME=null
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS=$MAIL_FROM_ADDRESS
MAIL_FROM_NAME="$APP_NAME"
VITE_APP_NAME="$APP_NAME"
EOF
chmod 600 .env

touch database/database.sqlite
composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build

php artisan config:clear --ansi || true
if [ -z "$EXISTING_KEY" ]; then
    php artisan key:generate --force --ansi
fi

php artisan migrate --force --seed --ansi
php artisan config:cache --ansi
php artisan route:cache --ansi
php artisan view:cache --ansi
chmod -R u+rwX storage bootstrap/cache database

cat > "$WEB/index.php" <<EOF
<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

\$_SERVER['SCRIPT_NAME'] = '$SCRIPT_NAME';
\$_SERVER['PHP_SELF'] = '$SCRIPT_NAME';

\$basePath = '$APP';

if (file_exists(\$maintenance = \$basePath.'/storage/framework/maintenance.php')) {
    require \$maintenance;
}

require \$basePath.'/vendor/autoload.php';

/** @var Application \$app */
\$app = require_once \$basePath.'/bootstrap/app.php';

\$app->handleRequest(Request::capture());
EOF

cat > "$WEB/.htaccess" <<EOF
<IfModule mod_rewrite.c>
    Options -MultiViews -Indexes
    RewriteEngine On
    RewriteBase $REWRITE_BASE

    RewriteRule ^$ home [R=302,L]

    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    RewriteCond %{HTTP:x-xsrf-token} .
    RewriteRule .* - [E=HTTP_X_XSRF_TOKEN:%{HTTP:X-XSRF-Token}]

    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI} (.+)/$
    RewriteRule ^ %1 [L,R=301]

    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>
EOF

rm -rf "$WEB/build" "$WEB/icons"
rm -f "$WEB/favicon.ico" "$WEB/robots.txt" "$WEB/manifest.webmanifest" "$WEB/service-worker.js"
ln -s "$APP/public/build" "$WEB/build"
ln -s "$APP/public/icons" "$WEB/icons"
ln -s "$APP/public/favicon.ico" "$WEB/favicon.ico"
ln -s "$APP/public/robots.txt" "$WEB/robots.txt"
ln -s "$APP/public/manifest.webmanifest" "$WEB/manifest.webmanifest"
ln -s "$APP/public/service-worker.js" "$WEB/service-worker.js"

rm -rf "$APP/node_modules"

curl -I --max-time 20 "$PUBLIC_URL"
curl -I --max-time 20 "$PUBLIC_URL/build/manifest.json"

echo "DEPLOYED $PUBLIC_URL"
'@

    return ($script.
        Replace("__REMOTE_APP__", (ConvertTo-BashSingleQuoted -Value $script:RemoteApp)).
        Replace("__REMOTE_BARE_REPO__", (ConvertTo-BashSingleQuoted -Value $script:RemoteBareRepo)).
        Replace("__REMOTE_WEB__", (ConvertTo-BashSingleQuoted -Value $script:RemoteWeb)).
        Replace("__PUBLIC_URL__", (ConvertTo-BashSingleQuoted -Value $script:PublicUrl)).
        Replace("__PUBLIC_BASE_PATH__", (ConvertTo-BashSingleQuoted -Value $script:PublicBasePath)).
        Replace("__APP_NAME__", (ConvertTo-BashSingleQuoted -Value $script:AppName)).
        Replace("__MAIL_FROM_ADDRESS__", (ConvertTo-BashSingleQuoted -Value $script:MailFromAddress)) -replace "`r`n", "`n")
}

function Invoke-RemoteDeploy {
    Write-Step "Deploying on remote host"

    $remoteScript = New-RemoteDeployScript
    Invoke-Ssh -RemoteCommand "bash -s" -InputText $remoteScript
}

function Invoke-FinalVerification {
    Write-Step "Final verification"

    Invoke-Ssh -RemoteCommand "test ! -d $script:RemoteApp/node_modules && echo 'remote node_modules absent'"
    Invoke-External -FilePath "curl.exe" -Arguments @("-I", "--max-time", "20", $script:PublicUrl)
}

Resolve-Settings
Assert-RepoReady
Invoke-OptionalLocalChecks
Push-Code
Invoke-RemoteDeploy
Invoke-FinalVerification

Write-Host ""
Write-Host "Deploy complete: $script:PublicUrl" -ForegroundColor Green
