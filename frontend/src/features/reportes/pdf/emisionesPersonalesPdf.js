import { jsPDF } from 'jspdf'
import { presentacionPdf } from './presentacionPdf.js'

// PDF del informe escalafonario y de las constancias, con los datos de /reportes/personales/{usuario}/{documento}. Un dato
// ausente se escribe como tal («No registrado»), nunca se completa; las constancias solo se arman si el backend las da por emitibles.
const texto = (valor, vacio = 'No registrado') => valor === null || valor === undefined || String(valor).trim() === '' ? vacio : String(valor)

function documentoPdf(data, institution, subject) {
  const pdf = new jsPDF({ unit: 'mm', format: 'a4' })
  const base = presentacionPdf(pdf, typeof institution === 'string' ? { name: institution } : institution)
  const { branding, margin, width, bottom, header } = base
  pdf.setProperties({ title: data.titulo, author: branding.name, subject, creator: branding.area })
  let y = 0
  const nuevaPagina = (primera = false) => {
    if (!primera) pdf.addPage()
    y = header({ title: data.titulo, instruction: `Fecha de emisión: ${data.emitido_en}` })
  }
  const espacio = alto => { if (y + alto > bottom) nuevaPagina() }

  function parrafo(contenido, { negrita = false, tamano = 9, color = 35 } = {}) {
    pdf.setFont('helvetica', negrita ? 'bold' : 'normal').setFontSize(tamano).setTextColor(color)
    const lineas = pdf.splitTextToSize(contenido, width)
    for (const linea of lineas) {
      espacio(4.6)
      pdf.text(linea, margin, y + 3.5)
      y += 4.6
    }
    y += 1.5
  }

  function titulo(contenido) {
    espacio(14)
    pdf.setFont('helvetica', 'bold').setFontSize(9).setTextColor(25)
    pdf.text(contenido.toUpperCase(), margin, y + 4)
    y += 7
  }

  // columnas: [clave, etiqueta, ancho relativo, texto si falta]
  function tabla(columnas, filas) {
    const total = columnas.reduce((suma, [, , ancho]) => suma + ancho, 0)
    const anchos = columnas.map(([, , ancho]) => width * ancho / total)
    const celdas = valores => valores.map((valor, i) => pdf.splitTextToSize(valor, anchos[i] - 2.5))
    function fila(lineas, encabezado) {
      pdf.setFont('helvetica', encabezado ? 'bold' : 'normal').setFontSize(encabezado ? 7 : 7.8).setTextColor(encabezado ? 25 : 35)
      const alto = Math.max(...lineas.map(l => l.length)) * 3.3 + 2.4
      let x = margin
      lineas.forEach((contenido, i) => {
        if (encabezado) pdf.setFillColor(242, 245, 244).rect(x, y, anchos[i], alto, 'F')
        pdf.setDrawColor(150).setLineWidth(0.15).rect(x, y, anchos[i], alto)
        pdf.text(contenido, x + 1.25, y + 3.4, { lineHeightFactor: 1.2 })
        x += anchos[i]
      })
      y += alto
    }
    pdf.setFont('helvetica', 'bold').setFontSize(7)
    const cabecera = celdas(columnas.map(([, etiqueta]) => etiqueta.toUpperCase()))
    espacio(16)
    fila(cabecera, true)
    for (const registro of filas) {
      pdf.setFont('helvetica', 'normal').setFontSize(7.8)
      const lineas = celdas(columnas.map(([clave, , , vacio]) => texto(registro[clave], vacio)))
      if (y + Math.max(...lineas.map(l => l.length)) * 3.3 + 2.4 > bottom) {
        nuevaPagina()
        fila(cabecera, true)
      }
      fila(lineas, false)
    }
    y += 4
  }

  // Pares etiqueta-valor en una rejilla; un valor largo ocupa las líneas que necesite (no se corta).
  function datos(pares, columnas = 2) {
    const ancho = width / columnas
    for (let i = 0; i < pares.length; i += columnas) {
      pdf.setFont('helvetica', 'normal').setFontSize(9)
      const fila = pares.slice(i, i + columnas).map(([etiqueta, valor, vacio]) => [etiqueta, pdf.splitTextToSize(texto(valor, vacio), ancho - 4)])
      const alto = 5 + Math.max(...fila.map(([, lineas]) => lineas.length)) * 4
      espacio(alto + 1)
      fila.forEach(([etiqueta, lineas], j) => {
        const x = margin + j * ancho
        pdf.setFont('helvetica', 'bold').setFontSize(7).setTextColor(90)
        pdf.text(etiqueta.toUpperCase(), x, y + 3)
        pdf.setFont('helvetica', 'normal').setFontSize(9).setTextColor(25)
        pdf.text(lineas, x, y + 7.5, { lineHeightFactor: 1.25 })
      })
      y += alto + 1
    }
    y += 2
  }

  // Un registro por bloque (los vínculos tienen demasiados datos para una tabla legible en A4 vertical).
  function fichas(campos, registros, encabezado) {
    registros.forEach((registro, i) => {
      espacio(24)
      pdf.setFont('helvetica', 'bold').setFontSize(8.5).setTextColor(25)
      pdf.text(pdf.splitTextToSize(encabezado(registro, i), width)[0], margin, y + 3.5)
      pdf.setDrawColor(200).setLineWidth(0.15).line(margin, y + 5, margin + width, y + 5)
      y += 7
      datos(campos.map(([clave, etiqueta, vacio]) => [etiqueta, registro[clave], vacio]), 3)
    })
  }

  function firma() {
    espacio(34)
    y += 22
    const x = margin + width / 2
    pdf.setDrawColor(60).setLineWidth(0.2).line(x - 38, y, x + 38, y)
    pdf.setFont('helvetica', 'normal').setFontSize(8).setTextColor(35)
    pdf.text('Firma y sello del responsable', x, y + 4, { align: 'center' })
    pdf.text(branding.area, x, y + 8, { align: 'center' })
    y += 12
  }

  function identificacion() {
    const p = data.persona
    return [['Apellidos y nombres', p.nombre], [p.tipo_documento ? `N.º de ${p.tipo_documento}` : 'Documento de identidad', p.numero_documento],
      ['N.º de legajo', p.numero_legajo], ['Estado del legajo', p.estado_legajo]]
  }

  nuevaPagina(true)
  return { pdf, base, parrafo, titulo, tabla, datos, fichas, firma, identificacion, texto }
}

const CAMPOS_VINCULO = [['ley', 'Régimen laboral'], ['tipo_vinculo', 'Tipo de vínculo'], ['numero_oficial', 'N.º de resolución o contrato'],
  ['unidad', 'Unidad orgánica'], ['tipo_personal', 'Tipo de personal'], ['situacion', 'Situación'],
  ['inicio', 'Inicio'], ['fin_previsto', 'Fin previsto', 'Sin fecha de fin registrada'], ['cese', 'Cese', 'Sin cese registrado']]
const encabezadoVinculo = (v, i) => `Vínculo ${i + 1}: ${texto(v.puesto, 'Puesto no registrado')}`

// Aclaración del estado «Aceptado» (aceptación directa: lo registra quien carga el documento).
export const NOTA_ACEPTADO = 'El estado "Aceptado" indica que el documento fue registrado por el responsable de la carga en el sistema. No implica fiscalización ni revisión posterior.'
const notaDiscreta = { tamano: 7.5, color: 90 }

const COLUMNAS_TIEMPO = [['numero_resolucion', 'N.º de resolución', 2.2], ['fecha_resolucion', 'Fecha', 1.3], ['anios', 'Años', 0.9], ['meses', 'Meses', 0.9], ['dias', 'Días', 0.9]]

const SECCIONES_INFORME = [
  ['vinculos', '1. Vínculos laborales, cargos y régimen', null],
  ['tiempos_reconocidos', '2. Tiempo de servicios reconocido por resolución', [...COLUMNAS_TIEMPO, ['sustento', 'Sustento', 2.6]]],
  ['formacion', '3. Formación académica', [['nivel', 'Nivel', 1.6], ['denominacion', 'Denominación', 3], ['institucion', 'Institución', 2.6], ['fecha_obtencion', 'Obtención', 1.3],
    ['registro_sunedu', 'Registro SUNEDU (declarado)', 1.6], ['sustento', 'Sustento', 2]]],
  ['capacitaciones', '4. Capacitaciones', [['nombre', 'Capacitación', 3.2], ['entidad', 'Entidad', 2.4], ['modalidad', 'Modalidad', 1.4], ['horas', 'Horas', 0.9],
    ['inicio', 'Inicio', 1.3], ['fin', 'Fin', 1.3], ['sustento', 'Sustento', 2]]],
  ['reconocimientos', '5. Reconocimientos', [['tipo', 'Tipo', 1.6], ['motivo', 'Motivo', 3.4], ['otorgado_por', 'Otorgado por', 2.2], ['numero_documento', 'N.º de documento', 1.6],
    ['fecha', 'Fecha', 1.3], ['sustento', 'Sustento', 2]]],
  ['sanciones', '6. Sanciones', [['tipo', 'Tipo', 1.8], ['numero_resolucion', 'N.º de resolución', 2], ['fecha_resolucion', 'Fecha', 1.3], ['inicio', 'Inicio', 1.3],
    ['fin', 'Fin', 1.3], ['dias_suspension', 'Días de suspensión', 1.2], ['sustento', 'Sustento', 2]]],
  ['licencias', '7. Licencias y permisos', [['tipo', 'Tipo', 2], ['goce', 'Goce', 1.2, 'Sin definir'], ['inicio', 'Inicio', 1.3], ['fin', 'Fin', 1.3], ['dias', 'Días', 0.9],
    ['numero_documento', 'N.º de documento', 1.8], ['sustento', 'Sustento', 2]]],
  ['evaluaciones', '8. Evaluaciones de desempeño', [['ciclo', 'Ciclo', 1], ['fecha', 'Fecha de evaluación', 1.5], ['plan_mejora', 'Plan de mejora', 1.3], ['sustento', 'Sustento', 2]]],
]

export function createInformeEscalafonarioPdf(data, institution) {
  const { pdf, base, parrafo, titulo, tabla, datos, fichas, identificacion } = documentoPdf(data, institution, 'Informe escalafonario del legajo')
  parrafo('Informe del expediente: reproduce los datos registrados en el legajo a la fecha de emisión, con el estado del documento que sustenta cada uno. No constituye una certificación de los datos.', { tamano: 8, color: 80 })
  const muestraAceptado = SECCIONES_INFORME.some(([clave]) => (data[clave] || []).some(fila => /^Documento (de vinculación )?aceptado$/.test(fila.sustento || '')))
  if (muestraAceptado) parrafo(NOTA_ACEPTADO, notaDiscreta)
  titulo('Identificación')
  datos([...identificacion(), ['Tipo de personal', data.persona.tipo_personal], ['Apertura del legajo', data.persona.fecha_apertura]])
  for (const [clave, nombre, columnas] of SECCIONES_INFORME) {
    if (data[clave] === null || data[clave] === undefined) continue
    titulo(nombre)
    if (data[clave].length && !columnas) fichas([...CAMPOS_VINCULO, ['sustento', 'Sustento']], data[clave], encabezadoVinculo)
    else if (data[clave].length) tabla(columnas, data[clave])
    else parrafo('Sin registros en el legajo.', { color: 90 })
    if (clave === 'tiempos_reconocidos' && data[clave].length) parrafo('Cada fila es una resolución de reconocimiento tal como se registró; el informe no suma resoluciones ni calcula tiempo a partir de vínculos, licencias o estudios.', { tamano: 7.5, color: 90 })
  }
  if (data.omitidas?.length) parrafo(`No incluido por los permisos de quien emite: ${data.omitidas.join(', ')}.`, { tamano: 7.5, color: 90 })
  parrafo('No incluye descansos médicos ni otros datos de salud.', { tamano: 7.5, color: 90 })
  base.footer()
  return pdf
}

function exigirEmitible(data) {
  if (!data?.emitible) throw new Error(data?.motivo || 'El documento no se puede emitir con los datos registrados.')
}

export function createConstanciaTrabajoPdf(data, institution) {
  exigirEmitible(data)
  const { pdf, base, parrafo, titulo, datos, fichas, firma, identificacion, texto } = documentoPdf(data, institution, 'Constancia de trabajo')
  const p = data.persona
  parrafo(`${base.branding.name} hace constar que ${p.nombre}, con ${p.tipo_documento || 'documento de identidad'} N.º ${texto(p.numero_documento)}, registra en su legajo N.º ${texto(p.numero_legajo)} los vínculos laborales que se detallan, sustentados con documento de vinculación aceptado:`)
  titulo('Identificación')
  datos(identificacion())
  titulo('Vínculos laborales')
  fichas(CAMPOS_VINCULO, data.vinculos, encabezadoVinculo)
  parrafo(`Situación a la fecha de emisión: ${data.situacion}`, { negrita: true })
  parrafo('Solo se consignan los vínculos sustentados con documento aceptado en el legajo. Un dato no registrado se indica como tal.', notaDiscreta)
  parrafo(NOTA_ACEPTADO, notaDiscreta)
  firma()
  base.footer()
  return pdf
}

export function createConstanciaTiempoServiciosPdf(data, institution) {
  exigirEmitible(data)
  const { pdf, base, parrafo, titulo, tabla, datos, firma, identificacion, texto } = documentoPdf(data, institution, 'Constancia de tiempo de servicios')
  const p = data.persona
  parrafo(`${base.branding.name} hace constar que ${p.nombre}, con ${p.tipo_documento || 'documento de identidad'} N.º ${texto(p.numero_documento)}, tiene reconocido el siguiente tiempo de servicios mediante resolución, según su legajo N.º ${texto(p.numero_legajo)}:`)
  titulo('Identificación')
  datos(identificacion())
  titulo('Tiempo de servicios reconocido')
  tabla(COLUMNAS_TIEMPO, data.tiempos)
  parrafo('Cada fila corresponde a una resolución de reconocimiento sustentada con documento aceptado en el legajo, tal como fue registrada. Esta constancia no suma resoluciones ni calcula tiempo a partir de vínculos, licencias, suspensiones o estudios.', notaDiscreta)
  parrafo(NOTA_ACEPTADO, notaDiscreta)
  firma()
  base.footer()
  return pdf
}
