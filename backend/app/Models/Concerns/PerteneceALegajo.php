<?php

namespace App\Models\Concerns;

use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\PerfilDocente;
use App\Models\PeriodoVacacional;
use App\Models\VinculoLaboral;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

trait PerteneceALegajo
{
    public function initializePerteneceALegajo(): void
    {
        $this->mergeFillable(['legajo_id']);
    }

    public static function bootPerteneceALegajo(): void
    {
        static::saving(function (Model $registro): void {
            $actual = app()->bound('legajo.escritura') ? app('legajo.escritura') : null;
            $padres = [];
            foreach (['documento_id' => DocumentoUsuario::class, 'vinculo_laboral_id' => VinculoLaboral::class, 'periodo_vacacional_id' => PeriodoVacacional::class, 'perfil_docente_id' => PerfilDocente::class] as $campo => $modelo) {
                if ($registro->getAttribute($campo)) {
                    $padre = $modelo::query()->find($registro->getAttribute($campo));
                    if ($campo === 'perfil_docente_id') {
                        $padre = $padre?->vinculoLaboral;
                    }
                    if ($actual && $padre && ($padre->usuario_id !== $registro->usuario_id
                        || ($padre->legajo_id === null && Legajo::query()->where('usuario_id', $registro->usuario_id)->count() > 1))) {
                        throw ValidationException::withMessages([$campo => 'El antecedente no pertenece a esta etapa o no tiene un legajo identificado. Consúltalo en el historial.']);
                    }
                    if ($padre?->legajo_id && $padre->usuario_id === $registro->usuario_id) {
                        $padres[] = $padre->legajo_id;
                    }
                }
            }
            $legajo = $registro->legajo_id ?? $actual ?? ($padres[0] ?? Legajo::query()->where('usuario_id', $registro->usuario_id)->where('estado_legajo', 'activo')->value('id'));
            if ($actual && (($registro->legajo_id && $registro->legajo_id !== $actual) || collect($padres)->contains(fn (string $id): bool => $id !== $actual))) {
                throw ValidationException::withMessages(['legajo' => 'El registro o su documento pertenece a otra etapa. Consulta el legajo anterior desde el historial.']);
            }
            $registro->legajo_id = $legajo;
        });
    }

    public function legajo(): BelongsTo
    {
        return $this->belongsTo(Legajo::class);
    }

    public function scopeEtapaActual(Builder $query): Builder
    {
        return $query->where(function (Builder $actual): void {
            $actual->where(fn (Builder $asociado): Builder => $asociado->whereNotNull($this->qualifyColumn('legajo_id'))
                ->whereIn($this->qualifyColumn('legajo_id'), Legajo::query()->select('id')->where('estado_legajo', 'activo')))
                ->orWhere(fn (Builder $legado): Builder => $legado->whereNull($this->qualifyColumn('legajo_id'))
                    ->whereNotExists(fn ($cerrados) => $cerrados->selectRaw('1')->from('legajos')
                        ->whereColumn('legajos.usuario_id', $this->qualifyColumn('usuario_id'))->where('estado_legajo', '!=', 'activo')));
        });
    }

    public function scopeDelLegajo(Builder $query, ?string $legajo): Builder
    {
        return $query->where(function (Builder $consulta) use ($legajo): void {
            $consulta->where($this->qualifyColumn('legajo_id'), $legajo);
            if ($legajo) {
                $consulta->orWhere(function (Builder $legado) use ($legajo): void {
                    $legado->whereNull($this->qualifyColumn('legajo_id'))->whereIn($this->qualifyColumn('usuario_id'),
                        Legajo::query()->select('usuario_id')->whereKey($legajo))
                        ->whereRaw('(SELECT COUNT(*) FROM legajos WHERE legajos.usuario_id = '.$this->getTable().'.usuario_id) = 1');
                });
            }
        });
    }
}
