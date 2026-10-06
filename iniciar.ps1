$ErrorActionPreference = 'Stop'

function Invoke-Docker {
    param([string[]]$DockerArguments)
    & docker @DockerArguments
    if ($LASTEXITCODE -ne 0) {
        throw "Docker fallo: docker $($DockerArguments -join ' ')"
    }
}

Push-Location $PSScriptRoot
try {
    foreach ($environmentFile in @('.env', 'backend/.env')) {
        if (!(Test-Path -LiteralPath $environmentFile)) {
            Copy-Item -LiteralPath "$environmentFile.example" -Destination $environmentFile
        }
    }

    Invoke-Docker @('compose', 'up', '-d', '--build', '--wait', 'db', 'app', 'nginx')
    Invoke-Docker @('compose', 'exec', '-T', '-e', 'COMPOSER_HOME=/tmp/composer', 'app', 'composer', 'install', '--no-interaction', '--prefer-dist')
    Invoke-Docker @('compose', 'exec', '-T', 'app', 'php', 'artisan', 'config:clear', '--no-interaction')

    $backendEnvironment = Get-Content -LiteralPath 'backend/.env'
    $applicationKey = $backendEnvironment | Where-Object { $_ -match '^APP_KEY=.+\S' -and $_ -notmatch '^APP_KEY=["'']{2}\s*$' }
    if (!$applicationKey) {
        Invoke-Docker @('compose', 'exec', '-T', 'app', 'php', 'artisan', 'key:generate', '--no-interaction')
    }

    Invoke-Docker @('compose', 'exec', '-T', 'app', 'php', 'artisan', 'migrate', '--no-interaction')
    Invoke-Docker @('compose', 'exec', '-T', 'app', 'php', 'artisan', 'optimize:clear', '--no-interaction')
    Invoke-Docker @('compose', 'up', '-d', '--build', '--force-recreate', 'frontend')

    Write-Host 'Sistema preparado. Abre http://localhost:5173'
    Write-Host 'Cada computadora que clona el proyecto tiene su propia base de datos y archivos.'
} finally {
    Pop-Location
}
