import { jsPDF } from 'jspdf'
import { normalizeInstitution } from '../../../config/institution.js'

// PDF y Excel de los formatos de la directiva (anexos 06 a 09). La carátula sigue el modelo del Anexo 09.

export function createInstitutionalReport({ titulo, columnas, data, generado_en, formato }, institution = {}) {
  const branding = normalizeInstitution(institution)
  const caratula = formato === 'caratula'
  const pdf = new jsPDF({ unit: 'mm', format: 'a4', orientation: caratula ? 'portrait' : 'landscape' })
  const ancho = pdf.internal.pageSize.getWidth()
  const alto = pdf.internal.pageSize.getHeight()
  const margen = 13
  let y = 18
  function cabecera(primera = false) {
    if (!primera) pdf.addPage()
    y = 18
    pdf.setFontSize(11); pdf.setFont('helvetica', 'bold')
    pdf.text(pdf.splitTextToSize(branding.name, ancho - margen * 2 - 30), margen, y)
    if (branding.showLogo && branding.logo) {
      try { const imagen = pdf.getImageProperties(branding.logo); const escala = Math.min(22 / imagen.width, 18 / imagen.height); pdf.addImage(branding.logo, ancho - 35, 10, imagen.width * escala, imagen.height * escala) } catch { /* Un logo antiguo inválido no bloquea el reporte. */ }
    }
    y += 12
    pdf.setFontSize(10); pdf.text(titulo, margen, y); y += 6
    pdf.setFontSize(7); pdf.setFont('helvetica', 'normal')
    pdf.text(`Generado: ${generado_en || new Date().toLocaleString('es-PE', { timeZone: 'America/Lima' })} · ${data.length} registro(s)`, margen, y); y += 8
  }
  cabecera(true)
  if (caratula) {
    const fila = data[0] || {}
    pdf.setFont('helvetica', 'bold'); pdf.setFontSize(22); pdf.text('LEGAJO DE PERSONAL', ancho / 2, y + 22, { align: 'center' })
    pdf.setFontSize(14); pdf.text('(CONFIDENCIAL)', ancho / 2, y + 32, { align: 'center' })
    pdf.setFontSize(16); pdf.text(pdf.splitTextToSize(String(fila.persona ?? ''), ancho - margen * 2), ancho / 2, y + 54, { align: 'center' })
    y += 80
    pdf.setFontSize(12)
    for (const [clave, etiqueta] of Object.entries(columnas)) {
      if (clave === 'persona') continue
      pdf.setFont('helvetica', 'bold'); pdf.text(`${etiqueta.toUpperCase()}:`, margen + 8, y)
      pdf.setFont('helvetica', 'normal'); pdf.text(String(fila[clave] ?? '—'), margen + 72, y); y += 11
    }
  } else {
    const entradas = Object.entries(columnas)
    const celda = (ancho - margen * 2) / entradas.length
    function encabezados() {
      pdf.setFont('helvetica', 'bold'); pdf.setFontSize(6)
      let altura = 8
      entradas.forEach(([, titulo], i) => { const lineas = pdf.splitTextToSize(titulo, celda - 2); pdf.text(lineas, margen + i * celda, y); altura = Math.max(altura, lineas.length * 3 + 3) })
      y += altura; pdf.setFont('helvetica', 'normal')
    }
    encabezados()
    for (const fila of data) {
      const lineas = entradas.map(([clave]) => pdf.splitTextToSize(String(fila[clave] ?? ''), celda - 2))
      // Un campo largo puede continuar en varias páginas sin recortar información.
      const total = Math.max(...lineas.map(texto => texto.length), 1)
      let offset = 0
      while (offset < total) {
        if (y + 7 > alto - 16) { cabecera(); encabezados() }
        const cantidad = Math.max(1, Math.floor((alto - 18 - y) / 3))
        lineas.forEach((texto, i) => { const parte = texto.slice(offset, offset + cantidad); if (parte.length) pdf.text(parte, margen + i * celda, y) })
        const usados = Math.min(total - offset, cantidad)
        y += usados * 3 + 4; offset += usados
      }
      pdf.setDrawColor(220); pdf.line(margen, y - 2, ancho - margen, y - 2)
    }
  }
  if (!data.length) pdf.text('Sin registros para los filtros seleccionados.', margen, y)
  const paginas = pdf.getNumberOfPages()
  for (let pagina = 1; pagina <= paginas; pagina++) {
    pdf.setPage(pagina); pdf.setFontSize(7); pdf.setTextColor(80)
    pdf.text(pdf.splitTextToSize(branding.reportFooter || 'Documento de uso institucional.', ancho - 55).slice(0, 2), margen, alto - 9)
    if (branding.showPageNumbers) pdf.text(`${pagina} / ${paginas}`, ancho - margen, alto - 9, { align: 'right' })
  }
  return pdf
}

export async function createInstitutionalWorkbook({ titulo, columnas, data, generado_en }) {
  const { default: ExcelJS } = await import('exceljs')
  const libro = new ExcelJS.Workbook()
  libro.creator = 'Gestión de legajos'; libro.created = new Date(generado_en || Date.now())
  const hoja = libro.addWorksheet('Reporte')
  hoja.columns = Object.entries(columnas).map(([key, header]) => ({ key, header, width: Math.min(40, Math.max(18, header.length + 2)) }))
  // Strings explícitos conservan ceros del DNI y no se interpretan como fórmulas.
  data.forEach(fila => hoja.addRow(Object.fromEntries(Object.keys(columnas).map(key => [key, fila[key] === null || fila[key] === undefined ? '' : String(fila[key])]))))
  hoja.getRow(1).font = { bold: true }; hoja.views = [{ state: 'frozen', ySplit: 1 }]
  hoja.autoFilter = { from: { row: 1, column: 1 }, to: { row: Math.max(1, data.length + 1), column: Object.keys(columnas).length } }
  libro.subject = titulo
  return libro.xlsx.writeBuffer()
}
