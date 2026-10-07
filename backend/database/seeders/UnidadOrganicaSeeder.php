<?php

namespace Database\Seeders;

use App\Models\UnidadOrganica;
use Illuminate\Database\Seeder;

class UnidadOrganicaSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        /** Organigrama UNIQ 2026: https://www.gob.pe/institucion/uniq/informes-publicaciones/7851686-organigrama-institucional-2026 */
        $units = [
            ['UNIQ-AU', 'Asamblea Universitaria', 'gobierno'],
            ['UNIQ-CU', 'Consejo Universitario', 'gobierno'],
            ['UNIQ-RECT', 'Rectorado', 'alta_direccion'],
            ['UNIQ-VA', 'Vicerrectorado Académico', 'alta_direccion'],
            ['UNIQ-VI', 'Vicerrectorado de Investigación', 'alta_direccion'],
            ['UNIQ-CPF', 'Comisión Permanente de Fiscalización', 'especial'],
            ['UNIQ-OCI', 'Órgano de Control Institucional', 'control'],
            ['UNIQ-TH', 'Tribunal de Honor', 'especial'],
            ['UNIQ-DU', 'Defensoría Universitaria', 'especial'],
            ['UNIQ-DSA', 'Dirección de Servicios Académicos', 'direccion'],
            ['UNIQ-DA', 'Dirección de Admisión', 'direccion'],
            ['UNIQ-DBU', 'Dirección de Bienestar Universitario', 'direccion'],
            ['UNIQ-DRS', 'Dirección de Responsabilidad Social', 'direccion'],
            ['UNIQ-DI', 'Dirección de Interculturalidad', 'direccion'],
            ['UNIQ-USEE', 'Unidad de Servicios Educativos de Extensión', 'unidad'],
            ['UNIQ-DPBS', 'Dirección de Producción de Bienes y Servicios', 'direccion'],
            ['UNIQ-DIE', 'Dirección de Incubadora de Empresas', 'direccion'],
            ['UNIQ-DITT', 'Dirección de Innovación y Transferencia Tecnológica', 'direccion'],
            ['UNIQ-DII', 'Dirección de Institutos e Investigación', 'direccion'],
            ['UNIQ-OTI', 'Oficina de Tecnologías de la Información', 'oficina'],
            ['UNIQ-DGA', 'Dirección General de Administración', 'direccion'],
            ['UNIQ-SG', 'Secretaría General', 'secretaria'],
            ['UNIQ-URH', 'Unidad de Recursos Humanos', 'unidad'],
            ['UNIQ-UEI', 'Unidad Ejecutora de Inversiones', 'unidad'],
            ['UNIQ-UA', 'Unidad de Abastecimiento', 'unidad'],
            ['UNIQ-USG', 'Unidad de Servicios Generales', 'unidad'],
            ['UNIQ-UC', 'Unidad de Contabilidad', 'unidad'],
            ['UNIQ-UT', 'Unidad de Tesorería', 'unidad'],
            ['UNIQ-UTDA', 'Unidad de Trámite Documentario y Archivo', 'unidad'],
            ['UNIQ-UGT', 'Unidad de Grados y Títulos', 'unidad'],
            ['UNIQ-OAJ', 'Oficina de Asesoría Jurídica', 'oficina'],
            ['UNIQ-OPP', 'Oficina de Planeamiento y Presupuesto', 'oficina'],
            ['UNIQ-OCRI', 'Oficina de Cooperación y Relaciones Internacionales', 'oficina'],
            ['UNIQ-OGC', 'Oficina de Gestión de la Calidad', 'oficina'],
            ['UNIQ-OCII', 'Oficina de Comunicación e Imagen Institucional', 'oficina'],
            ['UNIQ-UPEMI', 'Unidad de Planeamiento, Estadística y Modernización Institucional', 'unidad'],
            ['UNIQ-UP', 'Unidad de Presupuesto', 'unidad'],
            ['UNIQ-UF', 'Unidad Formuladora', 'unidad'],
            ['UNIQ-CF', 'Consejo de Facultad', 'gobierno'],
            ['UNIQ-DEC', 'Decanato', 'alta_direccion'],
            ['UNIQ-DAC', 'Departamento Académico', 'academico'],
            ['UNIQ-EP', 'Escuela Profesional', 'academico'],
            ['UNIQ-UI', 'Unidad de Investigación', 'unidad'],
            ['UNIQ-UPG', 'Unidad de Posgrado', 'unidad'],
            ['UNIQ-EPG', 'Escuela de Posgrado', 'academico'],
        ];
        foreach ($units as [$code, $name, $type]) {
            UnidadOrganica::query()->firstOrCreate(['codigo' => $code], [
                'nombre' => $name, 'tipo' => $type, 'activo' => true,
            ]);
        }
    }
}
