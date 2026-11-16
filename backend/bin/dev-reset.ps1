<#
.SYNOPSIS
    Rebuild the local development database (keelson_dev) from scratch.

.DESCRIPTION
    Drop, create, migrate and seed keelson_dev on a loopback host. Mirrors
    bin/dev-reset.sh and keeps the same refusals: the effective database name
    must be exactly keelson_dev and the host must be loopback, so it can never
    touch a shared or production database.

    This deletes every row in keelson_dev. It does not touch the PostgreSQL
    volume, the `keelson` database, or the container.

.EXAMPLE
    pwsh -File backend/bin/dev-reset.ps1
#>
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$backendDir = Split-Path -Parent $PSScriptRoot
Set-Location $backendDir

$expectedDatabase = 'keelson_dev'
$expectedEnvironment = 'dev'

function Stop-WithError {
    param([string] $Message)
    Write-Error "error: $Message"
    exit 1
}

if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    Stop-WithError 'php is not on PATH.'
}

# Credential-free: "database@host".
$target = (& php bin/console app:db:target --env=$expectedEnvironment --no-interaction) -join ''
if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($target)) {
    Stop-WithError 'could not read the effective database target (is DATABASE_URL set?).'
}
$target = $target.Trim()
$separator = $target.LastIndexOf('@')
$database = $target.Substring(0, $separator)
$hostName = $target.Substring($separator + 1)

if ($database -ne $expectedDatabase) {
    Stop-WithError "refusing to reset '$database': this script only rebuilds '$expectedDatabase'."
}
if (@('127.0.0.1', 'localhost', '::1') -notcontains $hostName) {
    Stop-WithError "refusing to reset a database on '$hostName': only a loopback host is allowed."
}

Write-Host "Resetting $database@$hostName (APP_ENV=$expectedEnvironment). Every row in it will be deleted."
Write-Host 'Starting from: docker compose up -d db   (Mailpit: docker compose up -d mailpit)'

$steps = @(
    @('doctrine:database:drop', '--force', '--if-exists'),
    @('doctrine:database:create'),
    @('doctrine:migrations:migrate'),
    @('app:fixtures:load', '--force')
)
foreach ($step in $steps) {
    $arguments = @('bin/console') + $step + @('--no-interaction', "--env=$expectedEnvironment")
    & php @arguments
    if ($LASTEXITCODE -ne 0) {
        Stop-WithError ("'php " + ($arguments -join ' ') + "' failed."
    }
}

Write-Host 'Done. Sign in at http://localhost:5173/sign-in with owner@example.test and viewer@example.test.'
