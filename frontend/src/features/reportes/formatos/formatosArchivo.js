import { presentacionPdf } from '../pdf/presentacionPdf.js'
import { disenoDocumento } from '../pdf/disenoDocumento.js'
import { jsPDF } from 'jspdf'
import { normalizeInstitution } from '../../../config/institution.js'

// PDF y Excel de los formatos de la directiva (anexos 06 a 09). La carátula sigue el modelo del Anexo 09.

export function createInstitutionalReport({ titulo, columnas, data, generado_en, formato }, institution = {}) {
  const branding = normalizeInstitution(institution)
  const caratula = formato === 'caratula'
  if (!caratula) {
    const pdf = new jsPDF({ unit: 'mm', format: 'a4' })
    const base = presentacionPdf(pdf, institution)
    const doc = disenoDocumento(pdf, base, { title: titulo, instruction: `Generado: ${generado_en || new Date().toLocaleString('es-PE', { timeZone: 'America/Lima' })}` })
    pdf.setProperties({ title: titulo, author: branding.name, creator: branding.area })
    doc.paragraph(`${data.length} registro(s)`)
    if (data.length) doc.heading('Registros del documento')
    data.forEach((fila, index) => {
      doc.record(`${String(index + 1).padStart(2, '0')}. ${fila.persona || titulo}`,
        Object.entries(columnas).map(([key, label]) => [label, fila[key] ?? '—']))
    })
    if (!data.length) doc.paragraph('Sin registros para los filtros seleccionados.')
    base.footer()
    return pdf
  }
  const pdf = new jsPDF({ unit: 'mm', format: 'a4' })
  const base = presentacionPdf(pdf, institution)
  const doc = disenoDocumento(pdf, base, { title: 'LEGAJO DE PERSONAL', subtitle: titulo,
    instruction: `Generado: ${generado_en || new Date().toLocaleString('es-PE', { timeZone: 'America/Lima' })}` })
  pdf.setProperties({ title: titulo, author: branding.name, creator: branding.area })
  doc.paragraph('(CONFIDENCIAL)', { bold: true })
  const fila = data[0] || {}
  doc.heading(fila.persona || 'Apellidos y nombres')
  Object.entries(columnas).forEach(([key, label]) => {
    if (key !== 'persona') doc.fields([[`${label}:`, fila[key] ?? '—']], 1)
  })
  if (!data.length) doc.paragraph('Sin registros para los filtros seleccionados.')
  base.footer()
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
