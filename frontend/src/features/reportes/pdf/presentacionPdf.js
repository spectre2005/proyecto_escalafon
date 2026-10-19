import { normalizeInstitution } from '../../../config/institution.js'

/** Identidad y preferencias comunes a todos los PDF institucionales. */
export function presentacionPdf(pdf, institution) {
  const branding = normalizeInstitution(institution)
  const margin = 14
  const pageWidth = pdf.internal.pageSize.getWidth()
  const pageHeight = pdf.internal.pageSize.getHeight()
  const width = pageWidth - margin * 2
  const accent = branding.reportColor.match(/[0-9a-f]{2}/gi).map(value => parseInt(value, 16))
  const linear = accent.map(channel => channel / 255).map(channel => channel <= .04045 ? channel / 12.92 : ((channel + .055) / 1.055) ** 2.4)
  const light = linear.reduce((sum, channel, index) => sum + channel * [.2126, .7152, .0722][index], 0)
  const accentText = light > .179 ? [25, 25, 25] : [255, 255, 255]
  pdf.setFont('helvetica', 'normal').setFontSize(6.5)
  const contacts = [branding.ruc && `RUC: ${branding.ruc}`, branding.email, branding.phone, branding.website].filter(Boolean).join(' | ')
  const footerLines = [branding.reportFooter, branding.address, contacts].filter(Boolean).flatMap(text => pdf.splitTextToSize(text, width))
  const footerTop = pageHeight - 10 - footerLines.length * 3
  const bottom = Math.min(pageHeight - 19, footerTop - 6)
  let logoSize = null
  if (branding.showLogo && branding.logo) {
    try {
      const image = pdf.getImageProperties(branding.logo)
      const scale = Math.min(28 / image.width, 22 / image.height)
      logoSize = { width: image.width * scale, height: image.height * scale }
    } catch { /* Una imagen antigua no válida no debe impedir descargar el reporte. */ }
  }

  function header({ title = branding.reportTitle, subtitle = '', emittedAt = new Date(), instruction = '' } = {}) {
    pdf.setFont('helvetica', 'bold').setFontSize(11).setTextColor(...accent)
    const textX = margin + (logoSize ? 34 : 0)
    const headingWidth = width - (logoSize ? 34 : 0)
    const heading = pdf.splitTextToSize(branding.name, headingWidth)
    if (logoSize) pdf.addImage(branding.logo, margin, 14, logoSize.width, logoSize.height, 'institution-logo')
    pdf.text(heading, textX + headingWidth / 2, 17, { align: 'center' })
    pdf.setFont('helvetica', 'normal').setFontSize(8).setTextColor(80)
    const area = pdf.splitTextToSize(branding.area, headingWidth)
    pdf.text(area, textX + headingWidth / 2, 19 + heading.length * 5, { align: 'center' })
    let y = Math.max(22 + heading.length * 5 + area.length * 3, logoSize ? 39 : 0)
    pdf.setDrawColor(...accent).setLineWidth(0.6).line(margin, y, margin + width, y)
    y += 6
    pdf.setFont('helvetica', 'bold').setTextColor(25).setFontSize(10)
    const titles = pdf.splitTextToSize(title.toUpperCase(), width)
    pdf.text(titles, pageWidth / 2, y, { align: 'center' })
    y += titles.length * 4.5 + 1.5
    if (subtitle && subtitle !== title) {
      pdf.setFontSize(9)
      const lines = pdf.splitTextToSize(subtitle, width)
      pdf.text(lines, pageWidth / 2, y, { align: 'center' })
      y += lines.length * 4 + 2
    }
    pdf.setFont('helvetica', 'normal').setFontSize(8).setTextColor(80)
    if (instruction || branding.showEmissionDate) {
      const lines = pdf.splitTextToSize(instruction || `Fecha de emisión: ${new Date(emittedAt).toLocaleDateString('es-PE', { timeZone: 'America/Lima' })}`, width)
      pdf.text(lines, margin, y)
      y += lines.length * 3.5 + 3.5
    } else y += 3
    return y
  }

  function footer() {
    const total = pdf.getNumberOfPages()
    for (let page = 1; page <= total; page++) {
      pdf.setPage(page).setFont('helvetica', 'normal').setFontSize(6.5).setTextColor(110)
      if (footerLines.length) {
        pdf.setDrawColor(...accent).setLineWidth(0.2).line(margin, footerTop - 3, margin + width, footerTop - 3)
        pdf.text(footerLines, pageWidth / 2, footerTop, { align: 'center', lineHeightFactor: 1.3 })
      }
      if (branding.showPageNumbers) pdf.text(`Página ${page} de ${total}`, pageWidth - margin, pageHeight - 5, { align: 'right' })
    }
  }
  return { branding, margin, width, accent, accentText, bottom, header, footer }
}
