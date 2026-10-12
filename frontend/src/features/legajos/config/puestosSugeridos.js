// Sugerencias PROVISIONALES para la denominación del puesto (vinculos_laborales.nombre_puesto, texto libre). No son un
// catálogo oficial de la institución: quedan pendientes de que confirme sus denominaciones. Solo ayudan a rellenar el
// campo; no se guardan en una tabla y no crean datos nuevos. El puesto no es la condición docente, la categoría, la
// dedicación, el grupo ocupacional, el cargo estructural ni el tipo de vínculo: cada uno tiene su propio campo.

export const OTRO = '__otro__'

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

export const etiquetaOtro = { docente: 'Otro puesto docente', administrativo: 'Otro puesto administrativo' }

export function sugerenciasPuesto(tipoPersonal) {
  return puestosSugeridos[tipoPersonal] || []
}

/**
 * Opción del selector para un puesto guardado: la sugerencia si coincide; OTRO si se eligió «Otro» o si es una
 * denominación propia o anterior que no está en las sugerencias (se conserva y se muestra para editarla); '' si no hay.
 */
export function seleccionPuesto(valor, tipoPersonal, otroElegido = false) {
  const texto = String(valor ?? '').trim()
  if (otroElegido) return OTRO
  if (!texto) return ''
  return sugerenciasPuesto(tipoPersonal).includes(texto) ? texto : OTRO
}

/** Valor del campo al elegir una opción: la sugerencia tal cual; con «Otro», el texto propio (nunca «Otro puesto…»). */
export function puestoElegido(opcion, valorActual, tipoPersonal) {
  if (opcion !== OTRO) return { nombre_puesto: opcion, otro: false }
  // Al pasar a «Otro» se conserva una denominación propia ya escrita; si venía de una sugerencia, se escribe de nuevo.
  const actual = String(valorActual ?? '').trim()
  return { nombre_puesto: actual && !sugerenciasPuesto(tipoPersonal).includes(actual) ? actual : '', otro: true }
}

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
  return tipoPersonal === 'docente' ? equivalencias[String(nombrePuesto ?? '').trim()] || {} : {}
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
