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

    Invoke-Docker @('compose', 'up', '-d', '--build', '--wait', 'db', 'app', 'nginx', 'clamav')
    Invoke-Docker @('compose', 'exec', '-T', '-e', 'COMPOSER_HOME=/tmp/composer', 'app', 'composer', 'install', '--no-interaction', '--prefer-dist')
    Invoke-Docker @('compose', 'exec', '-T', 'app', 'php', 'artisan', 'config:clear', '--no-interaction')

    $backendEnvironment = Get-Content -LiteralPath 'backend/.env'
    $applicationKey = $backendEnvironment | Where-Object { $_ -match '^APP_KEY=.+\S' -and $_ -notmatch '^APP_KEY=["'']{2}\s*$' }
    if (!$applicationKey) {
        Invoke-Docker @('compose', 'exec', '-T', 'app', 'php', 'artisan', 'key:generate', '--no-interaction')
    }

    Invoke-Docker @('compose', 'exec', '-T', 'app', 'php', 'artisan', 'documentos:clave', '--no-interaction')
    Invoke-Docker @('compose', 'exec', '-T', 'app', 'php', 'artisan', 'migrate', '--no-interaction')
    # Base de la plataforma y bases de cada institución aprovisionada (con una sola institución no hace nada).
    Invoke-Docker @('compose', 'exec', '-T', 'app', 'php', 'artisan', 'plataforma:migrar-instituciones', '--crear-base', '--no-interaction')
    # Catálogos y cuentas de prueba (backend/.env); se puede repetir sin duplicar datos.
    Invoke-Docker @('compose', 'exec', '-T', 'app', 'php', 'artisan', 'db:seed', '--no-interaction')
    Invoke-Docker @('compose', 'exec', '-T', 'app', 'php', 'artisan', 'optimize:clear', '--no-interaction')
    Invoke-Docker @('compose', 'up', '-d', '--build', '--force-recreate', 'frontend', 'scheduler', 'worker')

    Write-Host 'Sistema preparado. Cuentas de prueba en el README:'
    Write-Host '  Institucion: http://uniq.localhost:5173 (tambien uno. y dos.)'
    Write-Host '  Panel de plataforma: http://localhost:5173/plataforma/login'
    Write-Host 'Cada computadora que clona el proyecto tiene su propia base de datos y archivos.'
} finally {
    Pop-Location
}
