import test from 'node:test'
import assert from 'node:assert/strict'
import { conFiltro, CONSULTAS_SECCION, etiquetaReporte, familiaDe, familiasDe, gruposDe, paramsLimpios, reporteElegido, tituloFiltroEstado, valoresIniciales } from './reportesMenu.js'

const reportes = [
  { id: 'dotacion', grupo: 'Personal y dotación', titulo: 'Dotación por unidad y régimen' },
  { id: 'personal', grupo: 'Personal y dotación', titulo: 'Padrón del personal con legajo', seccion_origen: undefined },
  { id: 'contratos', grupo: 'Contratos y plazos', titulo: 'Contratos y adendas' },
  { id: 'colegiaturas', grupo: 'Contratos y plazos', titulo: 'Colegiatura y habilitación profesional', seccion_origen: 3 },
  { id: 'familiares', grupo: CONSULTAS_SECCION, titulo: 'Familiares y derechohabientes', seccion_origen: 1 },
]

test('los grupos conservan el orden del backend, sin ordenarse alfabéticamente', () => {
  assert.deepEqual(gruposDe(reportes), ['Personal y dotación', 'Contratos y plazos', CONSULTAS_SECCION])
})

test('se abre la dotación por defecto; si no está disponible, el padrón o el primero', () => {
  assert.equal(reporteElegido(reportes, null).id, 'dotacion')
  assert.equal(reporteElegido(reportes, 'contratos').id, 'contratos')
  assert.equal(reporteElegido(reportes, 'no-existe').id, 'dotacion')
  assert.equal(reporteElegido(reportes.slice(1), null).id, 'personal')
  assert.equal(reporteElegido(reportes.slice(2), null).id, 'contratos')
})

test('las pestañas renombran los grupos en un solo mapa y dejan fuera las consultas por sección', () => {
  assert.deepEqual(familiasDe(reportes).map(familia => familia.etiqueta), ['Personal y dotación', 'Contratos y plazos'])
  assert.equal(familiaDe('Control documental').etiqueta, 'Control del legajo')
  assert.equal(familiaDe('Vacaciones y licencias').etiqueta, 'Asistencia')
  assert.equal(familiaDe('Grupo nuevo').etiqueta, 'Grupo nuevo')
  assert.equal(familiaDe(CONSULTAS_SECCION), null)
})

test('al abrir una pestaña sin reporte elegido se muestra su primer reporte', () => {
  assert.equal(reporteElegido(reportes, null, 'contratos').id, 'contratos')
  assert.equal(reporteElegido(reportes, 'colegiaturas', 'personal').id, 'colegiaturas')
  assert.equal(reporteElegido(reportes, null, 'no-existe').id, 'dotacion')
})

test('solo las consultas por sección llevan el número de sección delante', () => {
  assert.equal(etiquetaReporte(reportes[4]), '01 · Familiares y derechohabientes')
  assert.equal(etiquetaReporte(reportes[3]), 'Colegiatura y habilitación profesional')
  assert.equal(etiquetaReporte(reportes[0]), 'Dotación por unidad y régimen')
})

test('el formulario parte de lo aplicado y de los mismos valores por defecto', () => {
  const p = new URLSearchParams('tipo=evaluaciones&unidad_id=u1')
  const inicial = valoresIniciales(p, 'evaluaciones', 2026)
  assert.equal(inicial.unidad_id, 'u1')
  assert.equal(inicial.anio, '2026')
  assert.equal(inicial.dias, '30')
  assert.equal(valoresIniciales(new URLSearchParams(), 'contratos', 2026).anio, '')
})

test('aplicar un filtro lo escribe o lo quita, vuelve a la página 1 y conserva el resto', () => {
  const p = new URLSearchParams('tipo=contratos&estado=abierto&page=3&por_pagina=15')
  assert.equal(conFiltro(p, 'contratos', 'unidad_id', 'u1').toString(), 'tipo=contratos&estado=abierto&por_pagina=15&unidad_id=u1')
  assert.equal(conFiltro(p, 'contratos', 'estado', '').toString(), 'tipo=contratos&por_pagina=15')
  assert.equal(conFiltro(new URLSearchParams(), 'personal', 'q', '  ana  ').toString(), 'tipo=personal&q=ana')
})

test('limpiar filtros deja solo el reporte y el tamaño de página', () => {
  assert.equal(paramsLimpios(new URLSearchParams('tipo=x&estado=y&q=z&page=2&por_pagina=20'), 'contratos').toString(), 'tipo=contratos&por_pagina=20')
  assert.equal(paramsLimpios(new URLSearchParams('estado=y'), 'contratos').toString(), 'tipo=contratos')
})

test('en las consultas por sección el filtro de estado se llama «Sustento documental»', () => {
  assert.equal(tituloFiltroEstado({ id: 'colegiaturas', seccion_origen: 3 }), 'Sustento documental')
  assert.equal(tituloFiltroEstado({ id: 'contratos' }), 'Estado')
})
