<?php

use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\Usuario;
use App\Support\AvisosVencimiento;
use App\Support\Documentos\AuditoriaDocumento;
use App\Support\Documentos\Folios;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'escalafon_test' || config('database.connections.pgsql.host') !== 'testdb') {
    exit(3);
}
try {
    if ($argv[1] === 'audit') {
        $request = Request::create('/');
        $request->setUserResolver(fn () => Usuario::query()->findOrFail($argv[3]));
        AuditoriaDocumento::registrar(DocumentoUsuario::query()->findOrFail($argv[2]), $request, 'ver');
    } elseif ($argv[1] === 'folio') {
        DB::transaction(fn () => Folios::reservar(Legajo::query()->findOrFail($argv[2]), 2, 1, 1));
    } elseif ($argv[1] === 'mail') {
        AvisosVencimiento::procesar();
    } else {
        exit(3);
    }
} catch (ValidationException) {
    exit(2);
}
