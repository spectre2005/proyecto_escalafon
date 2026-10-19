<?php

namespace App\Console\Commands;

use App\Models\ArchivoDocumento;
use App\Models\CertificadoDiscapacidad;
use App\Models\DeclaracionVerificacion;
use App\Models\Plataforma\Institucion;
use App\Support\Documentos\AlmacenDocumento;
use App\Support\Plataforma\ConexionInstitucion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ProtegerDocumentos extends Command
{
    protected $signature = 'documentos:proteger {--todas : Recorre las instituciones aprovisionadas} {--institucion= : Solo esta institución}';

    protected $description = 'Analiza y cifra PDF registrados, verifica su lectura y conserva sus metadatos';

    public function handle(): int
    {
        $fallos = 0;
        $procesar = function () use (&$fallos): void {
            ArchivoDocumento::query()->where('almacenamiento', config('legajo.disco_documentos'))
                ->where(fn ($q) => $q->where('cifrado', false)->orWhere('estado_antivirus', '!=', 'limpio')->orWhereNotNull('ruta_sin_cifrar_pendiente'))
                ->orderBy('id')->chunkById(100, function ($archivos) use (&$fallos): void {
                    foreach ($archivos as $archivo) {
                        try {
                            AlmacenDocumento::proteger($archivo);
                            $this->line('Procesado: '.$archivo->id);
                        } catch (Throwable) {
                            $fallos++;
                            $this->error('No se pudo proteger el archivo '.$archivo->id.'. Se conserva para revisión; no se omite el error.');
                        }
                    }
                });
            foreach ([DeclaracionVerificacion::class, CertificadoDiscapacidad::class] as $modelo) {
                $modelo::query()->whereNotNull('pdf_path')->chunkById(100, function ($registros) use (&$fallos): void {
                    $disco = Storage::disk('local');
                    foreach ($registros as $registro) {
                        $temporal = $registro->pdf_path.'.'.Str::uuid().'.tmp';
                        try {
                            $original = $disco->get($registro->pdf_path);
                            if (str_starts_with($original, 'ESC-PDF1:')) {
                                AlmacenDocumento::leerAnterior($original);

                                continue;
                            }
                            $contenido = AlmacenDocumento::leerAnterior($original);
                            if (! $disco->put($temporal, AlmacenDocumento::cifrarAnterior($contenido))
                                || ! hash_equals(hash('sha256', $contenido), hash('sha256', AlmacenDocumento::leerAnterior($disco->get($temporal))))
                                || ! $disco->move($temporal, $registro->pdf_path)) {
                                throw new \RuntimeException('No se pudo verificar la copia protegida.');
                            }
                            $this->line('PDF anterior protegido: '.$registro->id);
                        } catch (Throwable) {
                            $fallos++;
                            $disco->delete($temporal);
                            $this->error('Revisar PDF anterior del registro '.$registro->id.'.');
                        }
                    }
                });
            }
        };
        if ($this->option('todas') || $this->option('institucion')) {
            $instituciones = Institucion::query()->whereNotNull('aprovisionada_en')
                ->when($this->option('institucion'), fn ($q, $id) => $q->whereKey($id))->get();
            if ($instituciones->isEmpty()) {
                $this->error('No se encontraron instituciones aprovisionadas para este alcance.');

                return self::FAILURE;
            }
            foreach ($instituciones as $institucion) {
                $this->info('Institución: '.$institucion->slug);
                ConexionInstitucion::usar($institucion, $procesar);
            }
        } else {
            $procesar();
        }
        $this->info('Archivos pendientes de revisión: '.$fallos);

        return $fallos === 0 ? self::SUCCESS : self::FAILURE;
    }
}
