import { disenoDocumento } from './pdf/disenoDocumento.js'
import { presentacionPdf } from './pdf/presentacionPdf.js'

const fecha = valor => valor && /^\d{4}-\d{2}-\d{2}$/.test(valor) ? valor.split('-').reverse().join('/') : valor
export const valorReporte = (fila, columna) => fila[columna.key] === null || fila[columna.key] === undefined || fila[columna.key] === '' ? '—' : columna.type === 'date' ? fecha(fila[columna.key]) : fila[columna.key]

export function describirFiltros(filtros, opciones) {
  const nombres = { q: 'Persona', contenido: 'Contenido', tipo_personal: 'Personal', unidad_id: 'Unidad', estado: 'Estado', seccion: 'Sección', desde: 'Desde', hasta: 'Hasta', dias: 'Horizonte', origen: 'Motivo', anio: 'Año / ciclo', regimen_id: 'Ley / régimen', nivel: 'Nivel académico', modalidad: 'Tipo de capacitación' }
  return Object.entries(filtros).filter(([, valor]) => valor !== '' && valor !== null && valor !== undefined).map(([clave, valor]) => {
    let texto = valor
    if (clave === 'unidad_id') texto = opciones.unidades?.find(item => item.id === valor)?.nombre || valor
    if (clave === 'seccion') texto = opciones.secciones?.find(item => String(item.id) === String(valor))?.nombre || valor
    if (clave === 'estado') texto = opciones.reporte?.estados?.[valor] || valor
    if (clave === 'regimen_id') texto = opciones.regimenes?.find(item => item.id === valor)?.nombre || valor
    if (clave === 'nivel') texto = opciones.niveles?.find(item => item.id === valor)?.nombre || valor
    if (clave === 'modalidad') texto = opciones.modalidades?.find(item => item.id === valor)?.nombre || valor
    if (clave === 'desde' || clave === 'hasta') texto = fecha(valor)
    if (clave === 'dias') texto = `${valor} días`
    if (clave === 'origen') texto = opciones.origenes?.find(item => item.id === valor)?.nombre || valor
    return `${nombres[clave] || clave}: ${texto}`
  }).join(' · ') || 'Sin filtros adicionales'
}

export async function crearExcelReporte(reporte, institucion, filtros) {
  const { default: ExcelJS } = await import('exceljs')
  const libro = new ExcelJS.Workbook()
  libro.creator = reporte.meta.generado_por
  libro.created = new Date(reporte.meta.generado_en)
  const hoja = libro.addWorksheet('Reporte', { views: [{ state: 'frozen', ySplit: 6 }] })
  const columnas = reporte.columnas
  hoja.columns = columnas.map(columna => ({ key: columna.key, width: ['nombre', 'detalle', 'motivo', 'unidad', 'puesto', 'por_seccion'].includes(columna.key) ? 38 : 23 }))
  const unir = (fila, texto) => {
    hoja.mergeCells(fila, 1, fila, columnas.length)
    hoja.getCell(fila, 1).value = texto
  }
  unir(1, institucion.name)
  unir(2, reporte.meta.titulo)
  unir(3, `Generado: ${new Date(reporte.meta.generado_en).toLocaleString('es-PE', { timeZone: 'America/Lima' })} · Por: ${reporte.meta.generado_por}`)
  unir(4, filtros)
  unir(5, `${reporte.meta.total} resultados · ${institucion.reportFooter || 'Uso institucional'}`)
  hoja.getRow(1).font = { size: 15, bold: true, color: { argb: `FF${institucion.reportColor?.slice(1).toUpperCase() || '0F766E'}` } }
  hoja.getRow(2).font = { size: 12, bold: true }
  hoja.getRow(4).alignment = { wrapText: true, vertical: 'top' }
  hoja.getRow(4).height = 38
  hoja.getRow(6).values = columnas.map(columna => columna.label)
  hoja.getRow(6).height = 42
  hoja.getRow(6).alignment = { wrapText: true, vertical: 'middle' }
  hoja.getRow(6).font = { bold: true, color: { argb: 'FFFFFFFF' } }
  hoja.getRow(6).fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: `FF${institucion.reportColor?.slice(1).toUpperCase() || '0F766E'}` } }
  for (const fila of reporte.data) {
    const row = hoja.addRow(columnas.map(columna => valorReporte(fila, columna)))
    row.alignment = { wrapText: true, vertical: 'top' }
    row.height = Math.max(36, ...columnas.map((columna, indice) => Math.ceil(String(valorReporte(fila, columna)).length / Math.max(10, hoja.columns[indice].width - 3)) * 15 + 8))
    columnas.forEach((columna, indice) => { if (columna.type !== 'number') row.getCell(indice + 1).numFmt = '@' })
    if (row.number % 2) row.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFF1F5F9' } }
  }
  hoja.autoFilter = { from: { row: 6, column: 1 }, to: { row: 6, column: columnas.length } }
  hoja.pageSetup = { orientation: 'landscape', fitToPage: true, fitToWidth: 1, fitToHeight: 0 }
  const criterios = libro.addWorksheet('Resumen y alcance')
  criterios.columns = [{ width: 30 }, { width: 110 }]
  criterios.addRows([['Reporte', reporte.meta.titulo], ['Institución', institucion.name], ['Filtros', filtros], ['Alcance', reporte.meta.alcance || 'Consulta de registros institucionales.'], ['Personas incluidas', reporte.resumen?.personas ?? ''], ['Registros', reporte.meta.total]])
  criterios.eachRow(row => { row.alignment = { vertical: 'top', wrapText: true }; row.getCell(1).font = { bold: true }; row.height = row.number === 4 ? 65 : 30 })
  return libro.xlsx.writeBuffer()
}

export async function crearPdfReporte(reporte, institucion, filtros) {
  const { jsPDF } = await import('jspdf')
  const pdf = new jsPDF({ orientation: 'portrait', format: 'a4' })
  const base = presentacionPdf(pdf, institucion)
  const doc = disenoDocumento(pdf, base, { subtitle: reporte.meta.titulo, emittedAt: reporte.meta.generado_en })
  pdf.setProperties({ title: reporte.meta.titulo, author: base.branding.name, creator: base.branding.area })
  doc.paragraph(`${reporte.meta.total} resultados · Responsable: ${reporte.meta.generado_por}`)
  doc.paragraph(filtros)
  if (reporte.data.length) doc.heading('Resultados del reporte')
  reporte.data.forEach((fila, index) => {
    const nombre = fila.nombre || reporte.meta.titulo
    doc.record(`${String(index + 1).padStart(2, '0')}. ${nombre}`,
      reporte.columnas.map(columna => [columna.label, valorReporte(fila, columna)]))
  })
  if (!reporte.data.length) doc.paragraph('Sin registros para los filtros seleccionados.')
  if (reporte.meta.alcance) { doc.heading('Alcance del reporte'); doc.paragraph(reporte.meta.alcance) }
  base.footer()
  return pdf
}

export function descargarExcel(buffer, nombre) {
  const url = URL.createObjectURL(new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }))
  const enlace = document.createElement('a')
  enlace.href = url
  enlace.download = nombre
  enlace.click()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
