/** Convierte una imagen local a PDF sin transmitirla a servicios externos. */
export async function imagenAPdf(file, maxMB) {
  if (!['image/jpeg', 'image/png'].includes(file.type)) throw new Error('Selecciona una imagen JPG o PNG.')
  if (file.size > maxMB * 1024 * 1024) throw new Error(`La imagen supera el máximo de ${maxMB} MB.`)
  const url = URL.createObjectURL(file)
  try {
    const image = new Image()
    image.src = url
    await image.decode()
    if (!image.naturalWidth || image.naturalWidth * image.naturalHeight > 20000000) throw new Error('La imagen supera los 20 megapíxeles o no es válida.')
    const { jsPDF } = await import('jspdf')
    const landscape = image.naturalWidth > image.naturalHeight
    const pdf = new jsPDF({ orientation: landscape ? 'landscape' : 'portrait', unit: 'mm', format: 'a4', compress: true })
    const width = pdf.internal.pageSize.getWidth()
    const height = pdf.internal.pageSize.getHeight()
    const factor = Math.min((width - 10) / image.naturalWidth, (height - 10) / image.naturalHeight)
    const w = image.naturalWidth * factor
    const h = image.naturalHeight * factor
    const bytes = new Uint8Array(await file.arrayBuffer())
    pdf.addImage(bytes, file.type === 'image/png' ? 'PNG' : 'JPEG', (width - w) / 2, (height - h) / 2, w, h)
    const result = new File([pdf.output('arraybuffer')], file.name.replace(/\.[^.]+$/, '') + '.pdf', { type: 'application/pdf' })
    if (result.size > maxMB * 1024 * 1024) throw new Error('El PDF generado supera el tamaño permitido. Reduce el tamaño de la imagen e intenta de nuevo.')
    return result
  } finally { URL.revokeObjectURL(url) }
}
