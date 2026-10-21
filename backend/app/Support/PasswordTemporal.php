<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PasswordTemporal
{
    public const HORAS = [24, 48, 72, 168];

    public const MENSAJE = 'La contraseña temporal venció. Solicita una nueva contraseña temporal al administrador correspondiente.';

    public static function reglasPlazo(): array
    {
        return ['vigencia_horas' => ['sometimes', 'integer', Rule::in(self::HORAS)],
            'vence_en' => ['sometimes', 'required', 'date_format:Y-m-d\TH:i:sP', 'after:now',
                'before_or_equal:'.CarbonImmutable::now('America/Lima')->addDays(7)->endOfDay()->toIso8601String()]];
    }

    public static function limite(array $datos): CarbonImmutable
    {
        if (isset($datos['vence_en'], $datos['vigencia_horas'])) {
            throw ValidationException::withMessages(['vence_en' => 'Elige una fecha límite o un plazo en horas, no ambos.']);
        }

        return isset($datos['vence_en']) ? CarbonImmutable::parse($datos['vence_en'])->utc() : CarbonImmutable::now()->addHours($datos['vigencia_horas'] ?? 48);
    }

    public static function preparar(Model $cuenta): void
    {
        if (! $cuenta->debe_cambiar_password) {
            $cuenta->setAttribute('password_temporal_vence_en', null);
        } elseif (! $cuenta->password_temporal_vence_en) {
            $cuenta->setAttribute('password_temporal_vence_en', now()->addHours(48));
        }
    }

    public static function vencida(Model $cuenta): bool
    {
        return $cuenta->debe_cambiar_password && $cuenta->password_temporal_vence_en !== null && $cuenta->password_temporal_vence_en->lessThanOrEqualTo(now());
    }

    public static function datos(Model $cuenta): array
    {
        return ['password_temporal_vence_en' => $cuenta->password_temporal_vence_en?->toIso8601String(),
            'password_temporal_vencida' => self::vencida($cuenta)];
    }
}
