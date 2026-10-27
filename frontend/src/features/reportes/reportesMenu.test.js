import test from 'node:test'
import assert from 'node:assert/strict'
import { conFiltro, conFiltros, CONSULTAS_SECCION, etiquetaReporte, filtrosActivos, paramsLimpios, reporteElegido, separarReportes, tituloFiltroEstado, valoresIniciales } from './reportesMenu.js'

const reportes = [
  { id: 'dotacion', grupo: 'Personal y dotación', titulo: 'Dotación por unidad y régimen' },
  { id: 'personal', grupo: 'Personal y dotación', titulo: 'Padrón del personal con legajo', seccion_origen: undefined },
  { id: 'contratos', grupo: 'Contratos y plazos', titulo: 'Contratos y adendas' },
  { id: 'colegiaturas', grupo: 'Contratos y plazos', titulo: 'Colegiatura y habilitación profesional', seccion_origen: 3 },
  { id: 'familiares', grupo: CONSULTAS_SECCION, titulo: 'Familiares y derechohabientes', seccion_origen: 1 },
]

test('a la vista solo los clave, en su orden; lo demás va aparte y las consultas por sección también', () => {
  const { clave, otros, secciones } = separarReportes(reportes)
  assert.deepEqual(clave.map(reporte => reporte.id), ['personal', 'dotacion', 'contratos'])
  assert.deepEqual(otros.map(reporte => reporte.id), ['colegiaturas'])
  assert.deepEqual(secciones.map(reporte => reporte.id), ['familiares'])
})

test('se abre el padrón por defecto; si no está disponible, el primer reporte clave', () => {
  assert.equal(reporteElegido(reportes, null).id, 'personal')
  assert.equal(reporteElegido(reportes, 'familiares').id, 'familiares')
  assert.equal(reporteElegido(reportes, 'no-existe').id, 'personal')
  assert.equal(reporteElegido(reportes.filter(reporte => reporte.id !== 'personal'), null).id, 'dotacion')
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

test('aplicar el formulario reemplaza solo los campos mostrados y vuelve a la página 1', () => {
  const p = new URLSearchParams('tipo=contratos&estado=abierto&q=ana&page=3&por_pagina=15')
  const campos = [['q', ''], ['estado', 'vencido'], ['unidad_id', 'u1'], ['otro', 'x']]
  assert.equal(conFiltros(p, 'contratos', campos).toString(), 'tipo=contratos&estado=vencido&por_pagina=15&unidad_id=u1')
})

test('las etiquetas de los filtros activos usan el nombre de cada opción', () => {
  const opciones = { unidades: [{ id: 7, nombre: 'Oficina de personal' }], regimenes: [{ id: 2, codigo: 'D.L. 276' }] }
  const reporte = { id: 'contratos', estados: { vencido: 'Vencido' } }
  const p = new URLSearchParams('tipo=contratos&q=ana&unidad_id=7&estado=vencido&regimen_id=2&desde=2026-01-31&page=2')
  assert.deepEqual(filtrosActivos(p, reporte, opciones).map(filtro => filtro.etiqueta),
    ['Persona: ana', 'Unidad: Oficina de personal', 'Estado: Vencido', 'Desde 31/01/2026', 'Régimen: D.L. 276'])
  assert.deepEqual(filtrosActivos(new URLSearchParams('tipo=contratos&page=2'), reporte, opciones), [])
})

test('limpiar filtros deja solo el reporte y el tamaño de página', () => {
  assert.equal(paramsLimpios(new URLSearchParams('tipo=x&estado=y&q=z&page=2&por_pagina=20'), 'contratos').toString(), 'tipo=contratos&por_pagina=20')
  assert.equal(paramsLimpios(new URLSearchParams('estado=y'), 'contratos').toString(), 'tipo=contratos')
})

test('en las consultas por sección el filtro de estado se llama «Sustento documental»', () => {
  assert.equal(tituloFiltroEstado({ id: 'colegiaturas', seccion_origen: 3 }), 'Sustento documental')
  assert.equal(tituloFiltroEstado({ id: 'contratos' }), 'Estado')
  assert.equal(tituloFiltroEstado({ id: 'plana_docente' }), 'Dedicación')
})
