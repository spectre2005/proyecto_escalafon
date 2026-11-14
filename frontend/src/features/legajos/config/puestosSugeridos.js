// Sugerencias PROVISIONALES para la denominación del puesto (vinculos_laborales.nombre_puesto, texto libre). No son un
// catálogo oficial de la institución: quedan pendientes de que confirme sus denominaciones. Solo ayudan a rellenar el
// campo; no se guardan en una tabla y no crean datos nuevos. El puesto no es la condición docente, la categoría, la
// dedicación, el grupo ocupacional, el cargo estructural ni el tipo de vínculo: cada uno tiene su propio campo.

export const puestosSugeridos = {
  docente: [
    'Profesor ordinario principal', 'Profesor ordinario asociado', 'Profesor ordinario auxiliar', 'Docente contratado',
    'Docente extraordinario', 'Jefe de práctica', 'Docente investigador', 'Tutor de práctica clínica',
  ],
  administrativo: [
    'Director o jefe de oficina', 'Coordinador', 'Especialista', 'Analista', 'Asesor', 'Asistente administrativo', 'Secretario',
    'Técnico administrativo', 'Auxiliar administrativo', 'Personal de informática y soporte', 'Personal de biblioteca y archivo',
    'Personal de contabilidad y tesorería', 'Personal de servicios y mantenimiento',
  ],
}

export function sugerenciasPuesto(tipoPersonal) {
  return puestosSugeridos[tipoPersonal] || []
}

// Los puestos se escriben en mayúsculas (shared/forms/mayusculas.js): la coincidencia no distingue mayúsculas.
const mismoTexto = (a, b) => a.toLocaleUpperCase('es-PE') === b.toLocaleUpperCase('es-PE')

// Equivalencias inequívocas puesto → datos del perfil docente (Ley 30220: condición y, en ordinarios, categoría). Los
// demás puestos (p. ej. «Jefe de práctica» o «Docente investigador») no fijan esos datos y no se autocompletan.
const equivalencias = {
  'Profesor ordinario principal': { condicion_docente: 'ordinario', categoria: 'principal' },
  'Profesor ordinario asociado': { condicion_docente: 'ordinario', categoria: 'asociado' },
  'Profesor ordinario auxiliar': { condicion_docente: 'ordinario', categoria: 'auxiliar' },
  'Docente contratado': { condicion_docente: 'contratado' },
  'Docente extraordinario': { condicion_docente: 'extraordinario' },
}

export function equivalenciaPuesto(tipoPersonal, nombrePuesto) {
  const texto = String(nombrePuesto ?? '').trim()
  const clave = Object.keys(equivalencias).find(puesto => mismoTexto(puesto, texto))
  return tipoPersonal === 'docente' && clave ? equivalencias[clave] : {}
}

/**
 * Completa los datos del puesto con la equivalencia, solo en los campos vacíos: nunca sobrescribe lo que eligió el
 * usuario. Si algún campo ya tiene otro valor, no se completa nada (la equivalencia va entera: una categoría sin su
 * condición ordinaria no vale). Devuelve los datos, los campos completados y los que no coinciden (para avisar).
 */
export function completarDesdePuesto(datos, equivalencia) {
  const distintos = Object.keys(equivalencia).filter(campo => datos[campo] && datos[campo] !== equivalencia[campo])
  if (distintos.length) return { datos, completados: [], distintos }
  const completados = Object.keys(equivalencia).filter(campo => !datos[campo])
  return { datos: { ...datos, ...Object.fromEntries(completados.map(campo => [campo, equivalencia[campo]])) }, completados, distintos }
}
