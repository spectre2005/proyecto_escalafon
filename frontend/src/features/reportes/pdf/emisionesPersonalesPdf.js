import { disenoDocumento } from './disenoDocumento.js'
import { jsPDF } from 'jspdf'
import { presentacionPdf } from './presentacionPdf.js'

// PDF del informe escalafonario y de las constancias, con los datos de /reportes/personales/{usuario}/{documento}. Un dato
// ausente se escribe como tal («No registrado»), nunca se completa; las constancias solo se arman si el backend las da por emitibles.
const texto = (valor, vacio = 'No registrado') => valor === null || valor === undefined || String(valor).trim() === '' ? vacio : String(valor)

function documentoPdf(data, institution, subject) {
  const pdf = new jsPDF({ unit: 'mm', format: 'a4' })
  const base = presentacionPdf(pdf, typeof institution === 'string' ? { name: institution } : institution)
  const { branding } = base
  pdf.setProperties({ title: data.titulo, author: branding.name, subject, creator: branding.area })
  const informe = subject === 'Informe escalafonario del legajo'
  const doc = disenoDocumento(pdf, base, { layout: informe ? 'informe' : undefined, title: data.titulo, subtitle: informe ? 'Información registrada en el legajo' : '', instruction: `Fecha de emisión: ${data.emitido_en}` })
  function parrafo(contenido, { negrita = false, tamano = 9, color = 65 } = {}) {
    doc.paragraph(contenido, { bold: negrita, size: tamano, color })
  }
  const titulo = doc.heading
  const firma = doc.signature
  function datos(pares, columnas = 2) {
    const first = pares[0]
    if (first?.[0] === 'Apellidos y nombres') {
      doc.fields([[first[0], texto(first[1], first[2])]], 1)
      pares = pares.slice(1)
    }
    doc.fields(pares.map(([etiqueta, valor, vacio]) => [etiqueta, texto(valor, vacio)]), columnas)
  }
  function tabla(columnas, filas) {
    filas.forEach((registro, index) => {
      if (informe) {
        const principal = columnas.find(([key]) => ['denominacion', 'nombre', 'motivo', 'numero_resolucion'].includes(key)) || columnas[0]
        doc.record(`${principal[1].toUpperCase()}: ${texto(registro[principal[0]], `Registro ${index + 1}`)}`, columnas.filter(col => col !== principal).map(([key, label, , vacio]) => [label, texto(registro[key], vacio)]))
        return
      }
      doc.paragraph(`Registro ${index + 1}`, { bold: true })
      for (let i = 0; i < columnas.length; i++) {
        const [clave, etiqueta, peso, vacio] = columnas[i]
        const next = columnas[i + 1]
        if (peso < 2 && next && next[2] < 2) {
          datos([[etiqueta, registro[clave], vacio], [next[1], registro[next[0]], next[3]]])
          i++
        } else datos([[etiqueta, registro[clave], vacio]], 1)
      }
    })
  }
  function fichas(campos, registros, encabezado) {
    registros.forEach((registro, index) => {
      if (informe) {
        doc.record(encabezado(registro, index), campos.map(([key, label, vacio]) => [label, texto(registro[key], vacio)]))
        return
      }
      titulo(encabezado(registro, index))
      for (let i = 0; i < campos.length; i++) {
        const [clave, etiqueta, vacio] = campos[i]
        const full = ['unidad', 'numero_oficial', 'sustento', 'situacion'].includes(clave)
        const next = campos[i + 1]
        if (!full && next && !['unidad', 'numero_oficial', 'sustento', 'situacion'].includes(next[0])) {
          datos([[etiqueta, registro[clave], vacio], [next[1], registro[next[0]], next[2]]])
          i++
        } else datos([[etiqueta, registro[clave], vacio]], 1)
      }
    })
  }

  function identificacion() {
    const p = data.persona
    return [['Apellidos y nombres', p.nombre], [p.tipo_documento ? `N.º de ${p.tipo_documento}` : 'Documento de identidad', p.numero_documento],
      ['N.º de legajo', p.numero_legajo], ['Estado del legajo', p.estado_legajo]]
  }

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
  titulo('Alcance del informe')
  parrafo('Informe del expediente: reproduce los datos registrados en el legajo a la fecha de emisión, con el estado del documento que sustenta cada uno. No constituye una certificación de los datos.', { tamano: 8, color: 80 })
  const muestraAceptado = SECCIONES_INFORME.some(([clave]) => (data[clave] || []).some(fila => /^Documento (de vinculación )?aceptado$/.test(fila.sustento || '')))
  if (muestraAceptado) parrafo(NOTA_ACEPTADO, notaDiscreta)
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
  const { pdf, base, parrafo, fichas, firma, texto } = documentoPdf(data, institution, 'Constancia de trabajo')
  const p = data.persona
  parrafo(`${base.branding.name} hace constar que ${p.nombre}, con ${p.tipo_documento || 'documento de identidad'} N.º ${texto(p.numero_documento)}, registra en su legajo N.º ${texto(p.numero_legajo)} los vínculos laborales que se detallan, sustentados con documento de vinculación aceptado:`)
  parrafo(`Estado del legajo: ${texto(p.estado_legajo)}`, notaDiscreta)
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
