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
    # Base de la plataforma y bases de cada institución aprovisionada (con una sola institución no hace nada).
    Invoke-Docker @('compose', 'exec', '-T', 'app', 'php', 'artisan', 'plataforma:migrar-instituciones', '--crear-base', '--no-interaction')
    # Catálogos e identidad local; no crea ni modifica cuentas de acceso.
    Invoke-Docker @('compose', 'exec', '-T', 'app', 'php', 'artisan', 'db:seed', '--no-interaction')
    Invoke-Docker @('compose', 'exec', '-T', 'app', 'php', 'artisan', 'optimize:clear', '--no-interaction')
    Invoke-Docker @('compose', 'up', '-d', '--build', '--force-recreate', 'frontend')

    Write-Host 'Sistema preparado. Las cuentas se gestionan desde el panel.'
    Write-Host '  Institucion principal: http://uniq.localhost:5173'
    Write-Host '  Panel de plataforma: http://localhost:5173/plataforma/login'
    Write-Host 'Primera instalacion: docker compose exec app php artisan plataforma:crear-administrador'
    Write-Host 'Cada computadora que clona el proyecto tiene su propia base de datos y archivos.'
} finally {
    Pop-Location
}
