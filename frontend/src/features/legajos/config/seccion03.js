export const grupos03 = [
  ['Estudios que acreditan el perfil del puesto', 'Estudios básicos, títulos y habilitación profesional según el puesto.'],
  ['Posgrado', 'Diplomados, especializaciones, maestría y doctorado.'],
  ['Capacitación', 'Cursos, congresos y otros documentos de formación continua.'],
]

export const estados03 = {
  aceptado: ['Aceptado', 'bg-teal-50 text-teal-800'],
  pendiente: ['En revisión', 'bg-slate-100 text-slate-600'],
  observado: ['Observado', 'bg-rose-50 text-rose-800'],
  falta: ['Falta', 'bg-rose-50 text-rose-800'],
  no_aplica: ['No aplica', 'bg-slate-100 text-slate-600'],
  opcional: ['Opcional', 'bg-slate-100 text-slate-600'],
  anulado: ['Anulado', 'bg-slate-100 text-slate-600'],
  por_determinar: ['Revisar requisitos', 'bg-slate-100 text-slate-600'],
}

export const antivirus03 = {
  pendiente: 'Análisis antivirus pendiente', limpio: 'Archivo verificado por antivirus', infectado: 'Archivo bloqueado',
}

export const niveles03 = {
  b: { tecnico: 'Técnico', bachiller: 'Bachiller', titulo_profesional: 'Título profesional' },
  e: { maestria: 'Maestría', doctorado: 'Doctorado' },
}
export const capacitaciones03 = {
  f: { curso: 'Curso', programa_especializacion: 'Programa de especialización' },
  g: { congreso: 'Congreso', seminario: 'Seminario', taller: 'Taller' },
}

// Etiquetas de formacion_academica.nivel y capacitaciones.tipo (enums del esquema) para el resumen de formación.
export const nivelesFormacion = {
  educacion_basica: 'Educación básica', tecnico: 'Técnico', bachiller: 'Bachiller', titulo_profesional: 'Título profesional',
  diplomado_especializacion: 'Diplomado o especialización', maestria: 'Maestría', doctorado: 'Doctorado',
}
export const tiposCapacitacion = { ...capacitaciones03.f, ...capacitaciones03.g, otro: 'Otro' }

const recientes = campo => (a, b) => String(b[campo] || '').localeCompare(String(a[campo] || ''))

/**
 * Resumen de formación del panel 03: lo que acreditan los documentos vigentes (sin subsanados ni anulados, ni registros
 * anulados). Cada fila lleva el tipo y el documento de origen para abrir su detalle.
 */
export function resumenFormacion(items) {
  const filas = { formacion: [], colegiaturas: [], capacitaciones: [] }
  for (const item of items || []) {
    for (const documento of item.documentos || []) {
      if (documento.subsanado || documento.estado === 'anulado') continue
      for (const clave of Object.keys(filas)) {
        for (const registro of documento.registros?.[clave] || []) {
          if (!registro.anulado) filas[clave].push({ ...registro, item: item.id, documento: documento.id, estado: documento.estado })
        }
      }
    }
  }
  filas.formacion.sort(recientes('fecha_obtencion'))
  filas.colegiaturas.sort(recientes('fecha_colegiatura'))
  filas.capacitaciones.sort(recientes('fecha_fin'))
  return { ...filas, horas: filas.capacitaciones.reduce((total, fila) => total + (Number(fila.horas) || 0), 0) }
}
