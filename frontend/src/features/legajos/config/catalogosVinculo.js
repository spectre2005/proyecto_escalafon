// Catálogos del vínculo: unidades orgánicas, puestos, modalidades y procesos de selección. Los datos vienen de
// /expediente-opciones (App\Support\CatalogosVinculo::opciones); aquí solo se busca, se ordena y se completa el borrador.
// Mantener las listas en sincronía con backend/app/Support/CatalogosVinculo.php.

// Sin acentos, sin mayúsculas y con los espacios normalizados: «Unidad de Posgrado» = «unidad de  posgrado».
export const normalizar = texto => String(texto ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLocaleLowerCase('es-PE').replace(/\s+/g, ' ').trim()

/** Elementos activos (y el ya guardado, aunque esté desactivado) cuyo texto contiene lo buscado. */
export function filtrar(lista, busqueda, { texto = item => item.nombre, actual = '' } = {}) {
  const buscado = normalizar(busqueda)
  return (lista || []).filter(item => (item.activo !== false || item.id === actual) && (!buscado || normalizar(texto(item)).includes(buscado)))
}

/** Nombres desde la unidad raíz hasta la indicada: ['Vicerrectorado Académico', 'Unidad de Posgrado']. */
export function rutaUnidad(unidades, id) {
  const porId = new Map((unidades || []).map(unidad => [unidad.id, unidad]))
  const ruta = []
  const vistos = new Set()
  for (let actual = porId.get(id); actual && !vistos.has(actual.id); actual = porId.get(actual.unidad_padre_id)) {
    vistos.add(actual.id)
    ruta.unshift(actual.nombre)
  }
  return ruta
}

// Distancia de edición entre dos textos ya normalizados (para avisar de nombres casi iguales antes de crear uno nuevo).
function distancia(a, b) {
  let previa = Array.from({ length: b.length + 1 }, (_, j) => j)
  for (let i = 1; i <= a.length; i++) {
    const fila = [i]
    for (let j = 1; j <= b.length; j++) fila[j] = Math.min(previa[j] + 1, fila[j - 1] + 1, previa[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1))
    previa = fila
  }
  return previa[b.length]
}

/** Elemento ya existente con un nombre igual o casi igual (hasta un 20 % de diferencia), o null. */
export function parecido(lista, nombre, texto = item => item.nombre) {
  const buscado = normalizar(nombre)
  if (buscado.length < 4) return null
  let mejor = null
  for (const item of lista || []) {
    if (item.activo === false) continue
    const d = distancia(buscado, normalizar(texto(item)))
    if (d <= Math.max(1, Math.floor(buscado.length * 0.2)) && (!mejor || d < mejor.d)) mejor = { item, d }
  }
  return mejor?.item || null
}

/** Modalidades del tipo de vínculo y del tipo de personal (las de ambos tipos de personal tienen tipo_personal vacío). */
export function modalidadesDe(modalidades, regimen, tipoPersonal) {
  return (modalidades || []).filter(m => m.regimen_laboral === regimen && (!m.tipo_personal || !tipoPersonal || m.tipo_personal === tipoPersonal))
}

/** Puestos del tipo de personal: primero los de la unidad elegida, luego los generales y después los de otras unidades. */
export function puestosDe(puestos, tipoPersonal, unidadId = '') {
  const orden = puesto => puesto.unidad_organica_id === unidadId && unidadId ? 0 : !puesto.unidad_organica_id ? 1 : 2
  return (puestos || []).filter(puesto => !tipoPersonal || puesto.tipo_personal === tipoPersonal)
    .sort((a, b) => orden(a) - orden(b) || a.nombre.localeCompare(b.nombre, 'es'))
}

const BANDERAS_PUESTO = ['obligado_djbr', 'exige_colegiatura', 'tiene_periodo_prueba']
const nombresBandera = { obligado_djbr: 'DJBR', exige_colegiatura: 'colegiatura', tiene_periodo_prueba: 'período de prueba' }

/**
 * Al elegir un puesto del catálogo: su nombre, su id y las condiciones que el puesto define, solo si en el borrador siguen
 * «Sin responder» (lo ya respondido no se cambia). Devuelve el borrador y los nombres de lo completado, para avisarlo.
 */
export function aplicarPuesto(draft, puesto) {
  if (!puesto) return { draft: { ...draft, puesto_id: '' }, completados: [] }
  const completados = BANDERAS_PUESTO.filter(campo => puesto[campo] !== null && puesto[campo] !== undefined && (draft[campo] === null || draft[campo] === undefined))
  return {
    draft: { ...draft, puesto_id: puesto.id, nombre_puesto: puesto.nombre, ...Object.fromEntries(completados.map(campo => [campo, puesto[campo]])) },
    completados: completados.map(campo => nombresBandera[campo]),
  }
}

/** Al elegir una modalidad: su id y el período de prueba que sugiere, si sigue «Sin responder». */
export function aplicarModalidad(draft, modalidad) {
  if (!modalidad) return { draft: { ...draft, modalidad_vinculo_id: '' }, completados: [] }
  const completa = modalidad.tiene_periodo_prueba !== null && modalidad.tiene_periodo_prueba !== undefined && (draft.tiene_periodo_prueba === null || draft.tiene_periodo_prueba === undefined)
  return {
    draft: { ...draft, modalidad_vinculo_id: modalidad.id, ...(completa ? { tiene_periodo_prueba: modalidad.tiene_periodo_prueba } : {}) },
    completados: completa ? ['período de prueba'] : [],
  }
}

/** Texto de una lista para avisos: «unidad, puesto y modalidad». */
export function enumerar(lista) {
  return lista.length <= 1 ? lista.join('') : `${lista.slice(0, -1).join(', ')} y ${lista[lista.length - 1]}`
}

export const NOMBRES_CATALOGO = {
  unidades: { singular: 'unidad orgánica', nuevo: 'Nueva unidad orgánica', editar: 'Editar unidad orgánica', masculino: false },
  puestos: { singular: 'puesto', nuevo: 'Nuevo puesto', editar: 'Editar puesto', masculino: true },
  modalidades: { singular: 'modalidad', nuevo: 'Nueva modalidad de vínculo', editar: 'Editar modalidad', masculino: false },
  procesos: { singular: 'proceso de selección', nuevo: 'Nuevo proceso de selección', editar: 'Editar proceso de selección', masculino: true },
  tipos_vinculo: { singular: 'tipo de vínculo', nuevo: '', editar: 'Editar tipo de vínculo', masculino: true },
}

/** Unidades en el orden de su jerarquía, con su ruta desde la raíz. Las desactivadas solo si ya están elegidas. */
export function opcionesUnidad(unidades, actual = '') {
  return (unidades || []).filter(u => u.activo !== false || u.id === actual)
    .map(u => ({ id: u.id, ruta: rutaUnidad(unidades, u.id) }))
    .sort((a, b) => a.ruta.join(' › ').localeCompare(b.ruta.join(' › '), 'es'))
}

/** Aviso tras completar campos desde un elemento del catálogo. */
export function notaCompletado(origen, completados) {
  return completados.length ? `Se completó desde ${origen}: ${enumerar(completados)}. Puedes cambiarlo.` : ''
}

export const modalidadElegida = (opciones, draft) => (opciones?.modalidades || []).find(m => m.id === draft.modalidad_vinculo_id) || null

/** Tipos de vínculo que pasan por un proceso de selección (un designado no). */
export const CON_PROCESO = ['carrera', 'plazo_determinado']

/**
 * Cambiar el tipo de vínculo quita la modalidad si era de otro tipo y, si pasa a designado, el proceso de selección
 * (un designado no pasa por concurso). No se puede guardar de otro modo.
 */
export function elegirTipoVinculo(draft, poner, opciones, regimen) {
  poner('regimen_laboral')(regimen)
  const modalidad = modalidadElegida(opciones, draft)
  if (modalidad && modalidad.regimen_laboral !== regimen) poner('modalidad_vinculo_id')('')
  if (regimen && !CON_PROCESO.includes(regimen)) {
    poner('tipo_proceso_seleccion_id')('')
    poner('proceso_seleccion')('')
    poner('fecha_resultado_seleccion')('')
  }
}
