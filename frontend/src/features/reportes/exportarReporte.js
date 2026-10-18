const fecha = valor => valor && /^\d{4}-\d{2}-\d{2}$/.test(valor) ? valor.split('-').reverse().join('/') : valor
export const valorReporte = (fila, columna) => fila[columna.key] === null || fila[columna.key] === undefined || fila[columna.key] === '' ? '—' : columna.type === 'date' ? fecha(fila[columna.key]) : fila[columna.key]

export function describirFiltros(filtros, opciones) {
  const nombres = { q: 'Persona', contenido: 'Contenido', tipo_personal: 'Personal', unidad_id: 'Unidad', estado: 'Estado', seccion: 'Sección', desde: 'Desde', hasta: 'Hasta', dias: 'Horizonte', anio: 'Año / ciclo', regimen_id: 'Ley / régimen', nivel: 'Nivel académico', modalidad: 'Tipo de capacitación' }
  return Object.entries(filtros).filter(([, valor]) => valor !== '' && valor !== null && valor !== undefined).map(([clave, valor]) => {
    let texto = valor
    if (clave === 'unidad_id') texto = opciones.unidades.find(item => item.id === valor)?.nombre || valor
    if (clave === 'seccion') texto = opciones.secciones.find(item => String(item.id) === String(valor))?.nombre || valor
    if (clave === 'estado') texto = opciones.reporte?.estados?.[valor] || valor
    if (clave === 'regimen_id') texto = opciones.regimenes?.find(item => item.id === valor)?.nombre || valor
    if (clave === 'nivel') texto = opciones.niveles?.find(item => item.id === valor)?.nombre || valor
    if (clave === 'modalidad') texto = opciones.modalidades?.find(item => item.id === valor)?.nombre || valor
    if (clave === 'desde' || clave === 'hasta') texto = fecha(valor)
    if (clave === 'dias') texto = `${valor} días`
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
  hoja.getRow(1).font = { size: 15, bold: true, color: { argb: 'FF0F766E' } }
  hoja.getRow(2).font = { size: 12, bold: true }
  hoja.getRow(4).alignment = { wrapText: true, vertical: 'top' }
  hoja.getRow(4).height = 38
  hoja.getRow(6).values = columnas.map(columna => columna.label)
  hoja.getRow(6).height = 42
  hoja.getRow(6).alignment = { wrapText: true, vertical: 'middle' }
  hoja.getRow(6).font = { bold: true, color: { argb: 'FFFFFFFF' } }
  hoja.getRow(6).fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF0F766E' } }
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
  const columnas = reporte.columnas
  const pdf = new jsPDF({ orientation: 'landscape', format: columnas.length > 9 ? 'a3' : 'a4' })
  const ancho = pdf.internal.pageSize.getWidth(), alto = pdf.internal.pageSize.getHeight(), margen = 12
  const peso = columna => ['nombre', 'detalle', 'motivo', 'por_seccion'].includes(columna.key) ? 2.3 : ['unidad', 'puesto', 'origen_puesto'].includes(columna.key) ? 1.6 : 1
  const total = columnas.reduce((suma, columna) => suma + peso(columna), 0)
  const anchos = columnas.map(columna => (ancho - margen * 2) * peso(columna) / total)
  let y
  function cabecera() {
    pdf.setFont('helvetica', 'bold').setFontSize(13).setTextColor(15, 118, 110).text(institucion.name, margen, 15)
    pdf.setTextColor(30, 41, 59).setFontSize(11).text(reporte.meta.titulo, margen, 22)
    pdf.setFont('helvetica', 'normal').setFontSize(7.5)
    pdf.text(`Generado: ${new Date(reporte.meta.generado_en).toLocaleString('es-PE', { timeZone: 'America/Lima' })} · ${reporte.meta.generado_por} · ${reporte.meta.total} resultados`, margen, 28)
    const lineas = pdf.splitTextToSize(filtros, ancho - 2 * margen)
    pdf.text(lineas, margen, 33)
    y = 35 + lineas.length * 3.5
    const altoCabecera = Math.max(...columnas.map((columna, i) => pdf.splitTextToSize(columna.label, anchos[i] - 4).length)) * 3.5 + 5
    pdf.setFillColor(15, 118, 110).rect(margen, y, ancho - margen * 2, altoCabecera, 'F')
    let x = margen
    pdf.setFont('helvetica', 'bold').setTextColor(255, 255, 255)
    columnas.forEach((columna, i) => { pdf.text(pdf.splitTextToSize(columna.label, anchos[i] - 4), x + 2, y + 4); x += anchos[i] })
    y += altoCabecera
  }
  cabecera()
  reporte.data.forEach((fila, indice) => {
    pdf.setFont('helvetica', 'normal').setFontSize(7.5)
    const celdas = columnas.map((columna, i) => pdf.splitTextToSize(String(valorReporte(fila, columna)), anchos[i] - 4))
    const altura = Math.max(...celdas.map(lineas => lineas.length)) * 3.5 + 5
    if (y + altura > alto - 15) { pdf.addPage(); cabecera() }
    if (indice % 2 === 0) pdf.setFillColor(248, 250, 252).rect(margen, y, ancho - margen * 2, altura, 'F')
    pdf.setFont('helvetica', 'normal').setTextColor(51, 65, 85)
    let x = margen
    celdas.forEach((lineas, i) => { pdf.text(lineas, x + 2, y + 4); x += anchos[i] })
    y += altura
  })
  if (reporte.meta.alcance) {
    const lineas = pdf.splitTextToSize(`Alcance: ${reporte.meta.alcance}`, ancho - margen * 2)
    if (y + lineas.length * 3.5 + 12 > alto - 15) { pdf.addPage(); y = 15 }
    pdf.setFont('helvetica', 'normal').setFontSize(7.5).setTextColor(100, 116, 139).text(lineas, margen, y + 8)
  }
  const paginas = pdf.getNumberOfPages()
  for (let pagina = 1; pagina <= paginas; pagina++) {
    pdf.setPage(pagina).setFontSize(7).setTextColor(100, 116, 139)
    pdf.text(String(institucion.reportFooter || 'Documento de uso institucional').slice(0, 180), margen, alto - 6)
    pdf.text(`${pagina} / ${paginas}`, ancho - margen, alto - 6, { align: 'right' })
  }
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
