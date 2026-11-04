import { jsPDF } from 'jspdf'
import { fechaPdf, presentacionPdf } from '../pdf/presentacionPdf.js'

// PDF y Excel de los formatos de la directiva (anexos 06 a 09). Los anexos tabulares (06, 07 y 08) usan la presentación
// institucional común (cabecera, pie, paginación); la carátula sigue el modelo del Anexo 09.

export const NORMA_FORMATOS = 'Formato de la Directiva N.º 001-2023-SERVIR-GDSRH'

// Ancho relativo de cada columna según lo que suele contener; lo no listado pesa 1.
const PESOS = {
  persona: 2.2, documento: 2, motivo: 2, unidad: 1.8, puesto: 1.6, seccion: 1.6, responsable: 1.6, firma: 1.6, regimen: 1.4,
  ubicacion: 1.4, tipo_documento_identidad: 1.1, folios: 1.1, fedateado: 0.9, fiscalizado: 0.9, estado: 0.9,
}

export function createInstitutionalReport(reporte, institution = {}) {
  if (reporte.formato === 'caratula') return crearCaratula(reporte, institution)
  return crearAnexoTabular(reporte, institution)
}

function crearAnexoTabular({ titulo, columnas, data, generado_en }, institution) {
  const entradas = [['__n', 'N.º'], ...Object.entries(columnas)]
  // Hasta 12 columnas caben legibles en A4 horizontal (Anexos 06 y 08); el Anexo 07 (15 más el N.º) pasa a A3.
  const pdf = new jsPDF({ unit: 'mm', orientation: 'landscape', format: entradas.length > 12 ? 'a3' : 'a4' })
  const { branding, margin, width, accent, accentText, bottom, header, footer } = presentacionPdf(pdf, institution)
  pdf.setProperties({ title: titulo, author: branding.name, subject: NORMA_FORMATOS, creator: branding.area })
  const pesos = entradas.map(([clave]) => clave === '__n' ? 0.5 : PESOS[clave] ?? 1)
  const suma = pesos.reduce((total, peso) => total + peso, 0)
  const anchos = pesos.map(peso => width * peso / suma)
  const linea = 3.2
  const relleno = 1.6
  // La columna de firma queda en blanco (sin «—») y con alto para firmar a mano.
  const altoMinimo = 'firma' in columnas ? 12 : 0
  let y

  function celdas(valores, negrita) {
    pdf.setFont('helvetica', negrita ? 'bold' : 'normal').setFontSize(negrita ? 6.8 : 7.2)
    return valores.map((valor, i) => pdf.splitTextToSize(valor, anchos[i] - relleno * 2))
  }
  const encabezado = celdas(entradas.map(([, etiqueta]) => etiqueta), true)
  const altoEncabezado = Math.max(...encabezado.map(lineas => lineas.length)) * linea + relleno * 2 + 0.6

  function dibujarFila(lineas, alto, esEncabezado) {
    let x = margin
    pdf.setDrawColor(150).setLineWidth(0.15)
    lineas.forEach((contenido, i) => {
      if (esEncabezado) pdf.setFillColor(...accent).rect(x, y, anchos[i], alto, 'FD')
      else pdf.rect(x, y, anchos[i], alto)
      if (esEncabezado) pdf.setFont('helvetica', 'bold').setFontSize(6.8).setTextColor(...accentText)
      else pdf.setFont('helvetica', 'normal').setFontSize(7.2).setTextColor(35)
      // Encabezados centrados; el N.º también, el resto alineado a la izquierda para leer de corrido.
      const centrado = esEncabezado || i === 0
      if (contenido.length) pdf.text(contenido, centrado ? x + anchos[i] / 2 : x + relleno, y + relleno + 2.3, { align: centrado ? 'center' : 'left', lineHeightFactor: 1.3 })
      x += anchos[i]
    })
    y += alto
  }

  function pagina(primera = false) {
    if (!primera) pdf.addPage()
    y = header({ title: titulo, subtitle: NORMA_FORMATOS,
      instruction: branding.showEmissionDate ? `Fecha de emisión: ${generado_en || fechaPdf(new Date(), true)} · ${data.length} registro(s)` : `${data.length} registro(s)` })
    dibujarFila(encabezado, altoEncabezado, true)
  }

  pagina(true)
  data.forEach((fila, indice) => {
    const lineas = celdas(entradas.map(([clave]) => clave === '__n' ? String(indice + 1) : clave === 'firma' ? String(fila[clave] ?? '') : fila[clave] === null || fila[clave] === undefined || fila[clave] === '' ? '—' : String(fila[clave])), false)
    const total = Math.max(1, ...lineas.map(texto => texto.length))
    if (y + Math.min(total * linea + relleno * 2, 20) > bottom) pagina()
    // Un campo largo continúa en las páginas siguientes (con el encabezado repetido) sin recortar información.
    let offset = 0
    while (offset < total) {
      const cantidad = Math.min(total - offset, Math.max(1, Math.floor((bottom - y - relleno * 2) / linea)))
      dibujarFila(lineas.map(texto => texto.slice(offset, offset + cantidad)), Math.max(altoMinimo, cantidad * linea + relleno * 2), false)
      offset += cantidad
      if (offset < total) pagina()
    }
  })
  if (!data.length) {
    pdf.setFont('helvetica', 'normal').setFontSize(8).setTextColor(90)
    pdf.text('Sin registros para los filtros seleccionados.', margin, y + 6)
  }
  footer()
  return pdf
}

// Carátula según el modelo del Anexo 09: portada enmarcada, logo de la entidad centrado, «LEGAJO DE PERSONAL (CONFIDENCIAL)»,
// el nombre del servidor y sus datos sobre líneas. Es una portada: sin fecha de emisión ni paginación.
function crearCaratula({ titulo, columnas, data }, institution) {
  const pdf = new jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait' })
  const { branding, accent } = presentacionPdf(pdf, institution)
  pdf.setProperties({ title: titulo, author: branding.name, subject: NORMA_FORMATOS, creator: branding.area })
  const ancho = pdf.internal.pageSize.getWidth()
  const alto = pdf.internal.pageSize.getHeight()
  const centro = ancho / 2
  const marco = 14
  const fila = data[0] || {}
  const texto = valor => valor === null || valor === undefined ? '' : String(valor).trim()

  // Doble marco: línea exterior en el color institucional y una interior fina.
  pdf.setDrawColor(...accent).setLineWidth(0.8).rect(marco, marco, ancho - marco * 2, alto - marco * 2)
  pdf.setDrawColor(120).setLineWidth(0.2).rect(marco + 2, marco + 2, ancho - (marco + 2) * 2, alto - (marco + 2) * 2)

  let y = 32
  if (branding.showLogo && branding.logo) {
    try {
      const imagen = pdf.getImageProperties(branding.logo)
      const escala = Math.min(55 / imagen.width, 38 / imagen.height)
      pdf.addImage(branding.logo, centro - imagen.width * escala / 2, y, imagen.width * escala, imagen.height * escala, 'institution-logo')
      y += imagen.height * escala + 8
    } catch { /* Un logo antiguo inválido no impide emitir la carátula. */ }
  }
  pdf.setFont('helvetica', 'bold').setFontSize(14).setTextColor(...accent)
  const nombre = pdf.splitTextToSize(branding.name, ancho - 70)
  pdf.text(nombre, centro, y + 4, { align: 'center', lineHeightFactor: 1.25 })
  y += nombre.length * 6.2 + 2
  pdf.setFont('helvetica', 'normal').setFontSize(10).setTextColor(80)
  pdf.text(pdf.splitTextToSize(branding.area, ancho - 70), centro, y + 3, { align: 'center' })

  pdf.setFont('helvetica', 'bold').setFontSize(26).setTextColor(25)
  pdf.text('LEGAJO DE PERSONAL', centro, 122, { align: 'center' })
  pdf.setFont('helvetica', 'normal').setFontSize(13).setTextColor(60)
  pdf.text('(CONFIDENCIAL)', centro, 131, { align: 'center' })

  pdf.setFont('helvetica', 'bold').setFontSize(16).setTextColor(25)
  const persona = pdf.splitTextToSize(texto(fila.persona) || 'APELLIDOS Y NOMBRES DEL/LA SERVIDOR/A', ancho - 60)
  pdf.text(persona, centro, 160, { align: 'center', lineHeightFactor: 1.3 })

  // Datos del modelo, cada uno con su valor escrito sobre una línea.
  const izquierda = marco + 18
  const derecha = ancho - marco - 18
  pdf.setFont('helvetica', 'bold').setFontSize(10.5)
  const etiquetas = Object.entries(columnas).filter(([clave]) => clave !== 'persona').map(([clave, etiqueta]) => [clave, etiqueta.toUpperCase()])
  const dosPuntos = izquierda + Math.max(...etiquetas.map(([, etiqueta]) => pdf.getTextWidth(etiqueta))) + 2
  const inicioValor = dosPuntos + 4
  y = Math.max(196, 160 + persona.length * 7 + 22)
  for (const [clave, etiqueta] of etiquetas) {
    pdf.setFont('helvetica', 'normal').setFontSize(10.5)
    const valor = pdf.splitTextToSize(texto(fila[clave]), derecha - inicioValor - 2)
    const lineas = Math.max(1, valor.length)
    pdf.setFont('helvetica', 'bold').setTextColor(25).text(etiqueta, izquierda, y)
    pdf.text(':', dosPuntos, y)
    pdf.setFont('helvetica', 'normal').setTextColor(35)
    for (let i = 0; i < lineas; i++) {
      if (valor[i]) pdf.text(valor[i], inicioValor + 1, y + i * 6.5 - 0.8)
      pdf.setDrawColor(60).setLineWidth(0.25).line(inicioValor, y + i * 6.5 + 0.9, derecha, y + i * 6.5 + 0.9)
    }
    y += lineas * 6.5 + 4.5
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
