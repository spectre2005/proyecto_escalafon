<?php

namespace App\Support\Identidad;

use RuntimeException;

/** El proveedor no pudo responder (error, tiempo de espera o servicio caído). El mensaje nunca lleva el DNI. */
class ProveedorNoDisponible extends RuntimeException {}
