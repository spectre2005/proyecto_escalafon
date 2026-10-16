<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use LogicException;

trait RegistroInmutable
{
    protected static function bootRegistroInmutable(): void
    {
        static::updating(function (Model $registro): never {
            throw new LogicException('Los registros históricos no se modifican.');
        });
        static::deleting(function (Model $registro): never {
            throw new LogicException('Los registros históricos no se eliminan.');
        });
    }
}
