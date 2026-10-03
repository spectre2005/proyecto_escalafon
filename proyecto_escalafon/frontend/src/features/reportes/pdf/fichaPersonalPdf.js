import { jsPDF } from 'jspdf'
import { personalGroups, familyFields, reportValue } from '../config/fichaPersonal.js'

export function createPersonalPdf({ values = {}, families = [], blank = false, institution = 'Ficha del personal' } = {}) {
  const pdf = new jsPDF({ unit: 'mm', format: 'a4' })
  const margin = 14
  const width = 182
  const bottom = 278
  let y
  const title = blank ? 'Ficha vacía de datos personales' : 'Ficha de datos personales'
  function newPage(first = false) {
    if (!first) pdf.addPage()
    pdf.setTextColor(25)
    pdf.setFont('helvetica', 'bold')
    pdf.setFontSize(11)
    const heading = pdf.splitTextToSize(institution, width)
    pdf.text(heading, 105, 17, { align: 'center' })
    y = 19 + heading.length * 5
    pdf.setFontSize(10)
    pdf.text(title.toUpperCase(), 105, y, { align: 'center' })
    y += 6
    pdf.setFont('helvetica', 'normal')
    pdf.setFontSize(8)
    pdf.setTextColor(80)
    pdf.text(blank ? 'Complete los cuadros con letra clara. En las condiciones, indique Sí o No.' : `Fecha de emisión: ${new Date().toLocaleDateString('es-PE')}`, margin, y)
    y += 7
  }
  function heading(text) {
    pdf.setFont('helvetica', 'bold')
    pdf.setFontSize(9)
    pdf.setTextColor(25)
    pdf.text(text.toUpperCase(), margin, y + 4)
    y += 7
  }
  function group(name, fields, data) {
    if (y + 25 > bottom) newPage()
    heading(name)
    const layouts = [
      ['apellidos_nombres', 'parentesco'],
      ['nombres', 'apellido_paterno', 'apellido_materno'],
      ['tipo_doc_identidad', 'numero_doc_identidad', 'fecha_nacimiento'],
      ['nacionalidad', 'estado_civil'],
      ['email_institucional', 'telefono'],
      ['region', 'provincia', 'distrito'],
      ['domicilio'],
      ['tipo_personal', 'nombre_puesto', 'unidad_organica_id'],
      ['regimen_laboral', 'regimen_normativo_id', 'activo'],
      ['con_discapacidad', 'tiene_derechohabientes', 'exige_colegiatura'],
      ['obligado_djbr', 'tiene_periodo_prueba'],
      ['es_derechohabiente', 'anulado'],
      ['motivo_anulacion'],
      ['familiares'],
      ['fecha', 'firma'],
    ]
    const remaining = new Map(fields)
    const rows = layouts.map(keys => keys.filter(key => remaining.has(key)).map(key => {
      const field = [key, remaining.get(key)]
      remaining.delete(key)
      return field
    })).filter(row => row.length)
    for (const field of remaining) rows.push([field])
    for (const row of rows) {
      const cellWidth = width / row.length
      const cells = row.map(([key, label]) => {
        pdf.setFont('helvetica', 'bold')
        pdf.setFontSize(7)
        const labels = pdf.splitTextToSize(label.toUpperCase(), cellWidth - 4)
        pdf.setFont('helvetica', 'normal')
        pdf.setFontSize(8.5)
        return { labels, lines: pdf.splitTextToSize(reportValue(data[key], blank), cellWidth - 4) }
      })
      const labelHeight = Math.max(...cells.map(cell => cell.labels.length)) * 3 + 2
      const count = Math.max(1, ...cells.map(cell => cell.lines.length))
      // Split unusually long values across pages without dropping text.
      for (let offset = 0; offset < count; offset += 35) {
        const rows = Math.min(35, count - offset)
        const valueHeight = Math.max(blank ? 8 : 6, 3 + rows * 3.8)
        const height = labelHeight + valueHeight
        if (y + height > bottom) { newPage(); heading(`${name} (continuación)`) }
        cells.forEach((cell, index) => {
          const x = margin + index * cellWidth
          pdf.setDrawColor(65)
          pdf.setLineWidth(0.2)
          pdf.rect(x, y, cellWidth, height)
          pdf.line(x, y + labelHeight, x + cellWidth, y + labelHeight)
          pdf.setFont('helvetica', 'bold')
          pdf.setFontSize(7)
          pdf.setTextColor(25)
          pdf.text(cell.labels, x + cellWidth / 2, y + 3.5, { align: 'center', lineHeightFactor: 1.2 })
          pdf.setFont('helvetica', 'normal')
          pdf.setFontSize(8.5)
          pdf.setTextColor(35)
          const lines = cell.lines.slice(offset, offset + 35)
          if (lines.length) pdf.text(lines, x + cellWidth / 2, y + labelHeight + 4.3, { align: 'center', lineHeightFactor: 1.25 })
        })
        y += height + 3
      }
    }
    y += 1
  }
  newPage(true)
  personalGroups.forEach(([name, fields], index) => group(`${index + 1}. ${name}`, fields, values))
  if (blank) group('Información familiar', familyFields.filter(([key]) => !['anulado', 'motivo_anulacion'].includes(key)), {})
  else if (families.length) families.forEach((family, index) => group(`Información familiar · Familiar ${index + 1}`, familyFields, family.values))
  else group('Información familiar', [['familiares', 'Familiares registrados']], { familiares: 'Sin familiares registrados' })
  if (blank) group('Conformidad', [['fecha', 'Fecha'], ['firma', 'Firma del personal']], {})
  const total = pdf.getNumberOfPages()
  for (let page = 1; page <= total; page++) {
    pdf.setPage(page)
    pdf.setFontSize(8)
    pdf.setTextColor(110)
    pdf.text(`Página ${page} de ${total}`, 195, 289, { align: 'right' })
  }
  return pdf
}
