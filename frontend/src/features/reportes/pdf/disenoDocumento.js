// Flujo vertical compartido: ningún valor se recorta al alcanzar el pie de página.
export function disenoDocumento(pdf, base, options = {}) {
  const { margin, width, bottom, accent, accentText, header } = base
  let y = 0
  let section = ''
  let sectionNumber = 0
  const report = options.layout !== 'simple'
  function newPage() {
    if (y) pdf.addPage()
    y = header(options)
    if (section) drawSection(`${section} (continuación)`)
  }
  function ensure(height) { if (y + height > bottom) newPage() }
  function drawSection(text) {
    pdf.setFont('helvetica', 'bold').setFontSize(report ? 11 : 10).setTextColor(30)
    const displayText = report ? text.replace(/^\d+\.\s*/, '') : text
    const lines = pdf.splitTextToSize(displayText.toUpperCase(), width - (report ? 22 : 10))
    const height = Math.max(10, lines.length * 4.5 + 5)
    pdf.setFillColor(236, 240, 239).rect(margin, y, width, height, 'F')
    pdf.setFillColor(...accent).rect(margin, y, report ? 14 : 1.5, height, 'F')
    if (report) {
      pdf.setTextColor(...accentText).setFontSize(12)
      pdf.text(String(sectionNumber).padStart(2, '0'), margin + 7, y + 6.5, { align: 'center' })
    }
    pdf.setTextColor(30).setFontSize(report ? 11 : 10)
    pdf.text(lines, margin + (report ? 18 : 5), y + 6, { lineHeightFactor: 1.25 })
    y += height + 4
  }
  function heading(text) {
    section = ''
    ensure(32)
    section = text
    sectionNumber++
    drawSection(text)
  }
  function paragraph(text, { size = 9, bold = false, color = 65 } = {}) {
    pdf.setFont('helvetica', bold ? 'bold' : 'normal').setFontSize(size)
    const lines = pdf.splitTextToSize(String(text), width)
    for (const line of lines) {
      ensure(5)
      pdf.setFont('helvetica', bold ? 'bold' : 'normal').setFontSize(size).setTextColor(color)
      pdf.text(line, margin, y + 3.7)
      y += 5
    }
    y += 3
  }
  function fields(pairs, columns = 2) {
    for (let i = 0; i < pairs.length; i += columns) {
      const row = pairs.slice(i, i + columns)
      const cellWidth = width / columns
      pdf.setFont('helvetica', report ? 'bold' : 'normal').setFontSize(report ? 11 : 10)
      const cells = row.map(([label, value]) => ({ label, lines: pdf.splitTextToSize(String(value ?? 'No registrado'), cellWidth - 9) }))
      let offset = 0
      const count = Math.max(1, ...cells.map(cell => cell.lines.length))
      while (offset < count) {
        ensure(20)
        pdf.setFont('helvetica', 'bold').setFontSize(7.5)
        const labels = cells.map(cell => pdf.splitTextToSize(cell.label.toUpperCase(), cellWidth - 9))
        const labelHeight = Math.max(...labels.map(lines => lines.length)) * 3.5 + 3
        const available = Math.floor((bottom - y - labelHeight - 5) / 4.6)
        if (available < 1) { newPage(); continue }
        const take = Math.min(count - offset, available)
        const height = labelHeight + take * 4.6 + 2
        cells.forEach((cell, index) => {
          const x = margin + index * cellWidth
          pdf.setFont('helvetica', 'bold').setFontSize(7.5).setTextColor(85)
          pdf.text(labels[index], x + 3, y + 3.5, { lineHeightFactor: 1.3 })
          pdf.setFont('helvetica', report ? 'bold' : 'normal').setFontSize(report ? 11 : 10).setTextColor(30)
          const part = cell.lines.slice(offset, offset + take)
          if (part.length) pdf.text(part, x + 3, y + labelHeight + 3.5, { lineHeightFactor: 1.3 })
          pdf.setDrawColor(185).setLineWidth(.15)
          if (report) pdf.rect(x, y, cellWidth, height)
          else pdf.line(x, y + height - 2, x + cellWidth - 4, y + height - 2)
        })
        y += height + (report ? 0 : 1)
        offset += take
      }
    }
  }
  function record(title, pairs) {
    pdf.setFont('helvetica', 'bold').setFontSize(10.5)
    const titleLines = pdf.splitTextToSize(String(title), width - 10)
    pdf.setFont('helvetica', 'normal').setFontSize(9)
    const details = pairs.flatMap(([label, value]) => pdf.splitTextToSize(`${label}: ${value ?? 'No registrado'}`, width - 10))
    const lines = [...titleLines.map(text => ({ text, bold: true })), ...details.map(text => ({ text, bold: false }))]
    ensure(Math.min(bottom - 80, lines.length * 4.7 + 8))
    let offset = 0
    while (offset < lines.length) {
      ensure(18)
      const take = Math.min(lines.length - offset, Math.max(1, Math.floor((bottom - y - 7) / 4.7)))
      const height = take * 4.7 + 6
      pdf.setDrawColor(190).setLineWidth(.15).rect(margin, y, width, height)
      pdf.setFillColor(...accent).rect(margin, y, 1.5, height, 'F')
      lines.slice(offset, offset + take).forEach((line, index) => {
        pdf.setFont('helvetica', line.bold ? 'bold' : 'normal').setFontSize(line.bold ? 10.5 : 9).setTextColor(30)
        pdf.text(line.text, margin + 4, y + 5 + index * 4.7)
      })
      y += height + 3
      offset += take
    }
  }
  function signature() {
    section = ''
    ensure(40)
    y += 24
    pdf.setDrawColor(...accent).setLineWidth(.3).line(margin + width / 2 - 38, y, margin + width / 2 + 38, y)
    pdf.setFont('helvetica', 'normal').setFontSize(9).setTextColor(45)
    pdf.text('Firma y sello del responsable', margin + width / 2, y + 5, { align: 'center' })
    pdf.text(base.branding.area, margin + width / 2, y + 10, { align: 'center', maxWidth: width })
    y += 15
  }
  newPage()
  return { heading, paragraph, fields, record, signature, accentText }
}
