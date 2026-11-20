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

// Grupos ocupacionales que admite cada denominación sugerida del personal administrativo (D.L. 276: profesional, técnico,
// auxiliar). PROVISIONAL, como las denominaciones: TODO pendiente de confirmar con el cliente según su CAP/PAP.
const gruposSugeridos = {
  'Director o jefe de oficina': ['profesional'], Coordinador: ['profesional'], Especialista: ['profesional'], Analista: ['profesional'],
  Asesor: ['profesional'], 'Asistente administrativo': ['tecnico'], Secretario: ['tecnico', 'auxiliar'], 'Técnico administrativo': ['tecnico'],
  'Auxiliar administrativo': ['auxiliar'], 'Personal de informática y soporte': ['profesional', 'tecnico'],
  'Personal de biblioteca y archivo': ['tecnico', 'auxiliar'], 'Personal de contabilidad y tesorería': ['profesional', 'tecnico'],
  'Personal de servicios y mantenimiento': ['auxiliar'],
}

/**
 * Grupos ocupacionales que admite el puesto, o null si admite cualquiera (puesto escrito antes, o del catálogo sin grupo).
 * Un puesto del catálogo manda con su grupo; si no está en el catálogo, se usa la denominación sugerida.
 */
export function gruposDelPuesto(nombrePuesto, puestosCatalogo = []) {
  const texto = String(nombrePuesto ?? '').trim()
  if (!texto) return null
  const delCatalogo = (puestosCatalogo || []).filter(p => p.tipo_personal !== 'docente' && mismoTexto(p.nombre, texto))
  if (delCatalogo.length) return delCatalogo.every(p => p.grupo_ocupacional) ? [...new Set(delCatalogo.map(p => p.grupo_ocupacional))] : null
  const clave = Object.keys(gruposSugeridos).find(puesto => mismoTexto(puesto, texto))
  return clave ? gruposSugeridos[clave] : null
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
  const distintos = diferenciasConPuesto(datos, equivalencia)
  if (distintos.length) return { datos, completados: [], distintos }
  const completados = Object.keys(equivalencia).filter(campo => !datos[campo])
  return { datos: { ...datos, ...Object.fromEntries(completados.map(campo => [campo, equivalencia[campo]])) }, completados, distintos }
}

/** Campos con un valor distinto del que fija el puesto (los vacíos no contradicen). */
export function diferenciasConPuesto(datos, equivalencia) {
  return Object.keys(equivalencia).filter(campo => datos[campo] && datos[campo] !== equivalencia[campo])
}

// La categoría es solo de ordinarios y el subtipo, de extraordinarios: si la condición ya no los admite, se vacían.
function sinCamposHuerfanos(datos) {
  return {
    ...datos,
    ...(datos.condicion_docente !== 'ordinario' && { categoria: '' }),
    ...(datos.condicion_docente !== 'extraordinario' && { subtipo_extraordinario: '' }),
  }
}

/** Deja los datos como los fija el puesto (botón «Ajustar»). */
export function ajustarAPuesto(datos, equivalencia) {
  return sinCamposHuerfanos({ ...datos, ...equivalencia })
}

/**
 * Al cambiar el puesto. `autocompletados` son los campos que el sistema rellenó desde el puesto anterior: esos siguen al
 * puesto nuevo (se cambian o, si el nuevo no los fija, se vacían). Lo elegido a mano no se toca: si contradice al puesto
 * nuevo, no se completa nada y se devuelve en `distintos` para avisar.
 *
 * @returns {{ datos: object, autocompletados: string[], completados: string[], quitados: string[], distintos: string[] }}
 */
export function sincronizarConPuesto(datos, equivalencia, autocompletados = []) {
  const campos = Object.keys(equivalencia)
  const quitados = autocompletados.filter(campo => !campos.includes(campo) && datos[campo])
  const base = { ...datos, ...Object.fromEntries(quitados.map(campo => [campo, ''])) }
  const restantes = autocompletados.filter(campo => campos.includes(campo))
  const distintos = campos.filter(campo => base[campo] && base[campo] !== equivalencia[campo] && !restantes.includes(campo))
  if (distintos.length) return { datos: base, autocompletados: restantes, completados: [], quitados, distintos }
  const completados = campos.filter(campo => base[campo] !== equivalencia[campo])
  return { datos: sinCamposHuerfanos({ ...base, ...equivalencia }), autocompletados: campos, completados, quitados, distintos }
}
