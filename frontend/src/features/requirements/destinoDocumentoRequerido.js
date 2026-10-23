import { gruposSeccion01 } from '../legajos/config/seccion01.js'
import { subseccionesSeccion02 } from '../legajos/config/seccion02.js'
import { subseccionesSeccion07 } from '../legajos/config/seccion07.js'
import { subseccionesSeccion10 } from '../legajos/config/legajoSections.js'

export function destinoDocumentoRequerido(record) {
  const base = `/legajos/${record.usuario_id}`
  const tipo = record.tipo_documento
  if (!tipo) return base
  const codigo = tipo.codigo || ''
  const seccion = Number(tipo.seccion_legajo_id)
  let categoria
  if (seccion === 1) categoria = Object.entries(gruposSeccion01).find(([, grupo]) => grupo.codigos.includes(codigo))?.[0]
  if ([2, 7, 10].includes(seccion)) {
    const grupos = { ...subseccionesSeccion02, ...subseccionesSeccion07, ...subseccionesSeccion10 }
    categoria = Object.entries(grupos).find(([, grupo]) => grupo.codigo === tipo.subseccion_legajo?.codigo)?.[0]
  }
  if ([3, 4, 6].includes(seccion)) categoria = `seccion-${String(seccion).padStart(2, '0')}`
  if (seccion === 5) categoria = /^S05_[BCD]_/.test(codigo) ? 's05-vacaciones' : codigo === 'S05_OTROS_UNIVERSIDAD' ? 's05-otros' : 's05-licencias'
  if (seccion === 8) categoria = codigo.startsWith('SAN_') ? 'sanciones' : 'reconocimientos'
  if (seccion === 9) categoria = codigo.startsWith('S09_A_') ? 's09-individuales' : codigo.startsWith('S09_B_') ? 's09-colectivas' : codigo.startsWith('S09_C_') ? 's09-afiliacion' : 's09-otros'
  if (seccion === 11) categoria = 's11-documentos'
  if (!categoria) return `${base}?categoria=documentos`
  const ancla = categoria === 'general' ? `bloque-${codigo}` : `item-${seccion === 8 ? codigo : tipo.id}`
  return `${base}?categoria=${categoria}#${ancla}`
}
