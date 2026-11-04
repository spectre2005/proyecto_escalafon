import test from 'node:test'
import assert from 'node:assert/strict'
import { FORMULA_CIERRE, createConstanciaTiempoServiciosPdf, createConstanciaTrabajoPdf, createInformeEscalafonarioPdf, fechaEnLetras } from './emisionesPersonalesPdf.js'

const institution = { name: 'Institucion prueba', area: 'Oficina de personal', reportFooter: 'Uso interno' }
const persona = { nombre: 'FICTICIA EJEMPLO, ANA PRUEBA', tipo_documento: 'DNI', numero_documento: '00112233', tipo_personal: 'Administrativo',
  numero_legajo: 'LEG-0001', estado_legajo: 'Activo', fecha_apertura: '02/03/2020' }
const vinculo = { puesto: 'ESPECIALISTA EN ESCALAFON', unidad: 'UNIDAD DE PRUEBA', tipo_personal: 'Administrativo', ley: 'Decreto Legislativo 1057 (CAS)',
  tipo_vinculo: 'Plazo determinado', numero_oficial: 'CONTRATO-CAS-045-2021', inicio: '04/01/2021', fin_previsto: null, cese: null,
  situacion_codigo: 'vigente', situacion: 'Vigente a la fecha de emision', sustento: 'Documento de vinculacion aceptado' }
const tiempo = { numero_resolucion: 'RD-0150-2024', fecha_resolucion: '10/05/2024', anios: 12, meses: 3, dias: 9 }
const vacio = { formacion: [], capacitaciones: [], reconocimientos: [], sanciones: [], licencias: [], evaluaciones: [], omitidas: [] }

// Texto visible de una página (o de todas), con las líneas partidas unidas por un espacio.
const textoPagina = (pdf, pagina) => [...pdf.internal.pages[pagina].join('\n').matchAll(/\(((?:\\.|[^\\)])*)\) Tj/g)].map(m => m[1].replace(/\\(.)/g, '$1')).join(' ')
const textoPdf = pdf => Array.from({ length: pdf.getNumberOfPages() }, (_, i) => textoPagina(pdf, i + 1)).join(' ')

test('the report shows identification, each section and what is missing without inventing values', () => {
  const pdf = createInformeEscalafonarioPdf({ titulo: 'Informe escalafonario', emitido_en: '22/10/2026', persona, ...vacio,
    vinculos: [vinculo, { ...vinculo, puesto: 'SIN NUMERO', numero_oficial: null }], tiempos_reconocidos: [{ ...tiempo, sustento: 'Sin documento de sustento' }],
    capacitaciones: [{ nombre: 'GESTION DE LEGAJOS', entidad: null, modalidad: 'Curso', horas: 24, inicio: '01/02/2023', fin: '10/02/2023', sustento: 'Documento aceptado' }] }, institution)
  const output = pdf.output()
  const texto = textoPdf(pdf)
  assert.ok(output.startsWith('%PDF-'))
  assert.ok(output.includes('INFORME ESCALAFONARIO'))
  assert.ok(texto.includes('FICTICIA EJEMPLO, ANA PRUEBA'))
  assert.ok(texto.includes('00112233'))
  assert.ok(texto.includes('LEG-0001'))
  assert.ok(texto.includes('22/10/2026'))
  assert.ok(texto.includes('CONTRATO-CAS-045-2021'))
  assert.ok(texto.includes('GESTION DE LEGAJOS'))
  assert.ok(texto.includes('RD-0150-2024'))
  assert.ok(texto.includes('Sin documento de sustento'))
  // El N.º oficial ausente no se reemplaza por otro valor; las fechas que faltan se nombran como tales.
  assert.ok(texto.includes('No registrado'))
  assert.ok(texto.includes('Sin cese'))
  assert.ok(texto.includes('Sin registros en el legajo.'))
  assert.ok(texto.includes('No constituye una certificaci'))
})

test('sections hidden by permissions are left out and declared', () => {
  const texto = textoPdf(createInformeEscalafonarioPdf({ titulo: 'Informe escalafonario', emitido_en: '22/10/2026', persona, ...vacio, vinculos: [], tiempos_reconocidos: [],
    sanciones: null, evaluaciones: null, omitidas: ['Sanciones disciplinarias', 'Evaluaciones de desempeño'] }, institution))
  assert.ok(!texto.includes('6. SANCIONES'))
  assert.ok(texto.includes('Sanciones disciplinarias'))
  assert.ok(texto.includes('5. RECONOCIMIENTOS'))
})

test('the work certificate needs supported links and states the situation given by the backend', () => {
  assert.throws(() => createConstanciaTrabajoPdf({ titulo: 'Constancia de trabajo', emitido_en: '22/10/2026', persona, emitible: false, motivo: 'Sin vinculo sustentado' }, institution), /Sin vinculo sustentado/)
  const pdf = createConstanciaTrabajoPdf({ titulo: 'Constancia de trabajo', emitido_en: '22/10/2026', persona, emitible: true, vinculos: [vinculo],
    situacion: 'Mantiene un vinculo laboral vigente a la fecha de emision.', no_incluidos: [] }, institution)
  const texto = textoPdf(pdf)
  assert.ok(pdf.output().includes('CONSTANCIA DE TRABAJO'))
  assert.ok(texto.includes('ESPECIALISTA EN ESCALAFON'))
  assert.ok(texto.includes('CONTRATO-CAS-045-2021'))
  assert.ok(texto.includes('Mantiene un vinculo laboral vigente'))
  assert.ok(texto.includes('Firma y sello del responsable'))
})

test('the service time certificate lists each resolution and never adds them up', () => {
  assert.throws(() => createConstanciaTiempoServiciosPdf({ titulo: 'Constancia de tiempo de servicios', emitido_en: '22/10/2026', persona, emitible: false, motivo: 'Sin tiempo reconocido' }, institution), /Sin tiempo reconocido/)
  const texto = textoPdf(createConstanciaTiempoServiciosPdf({ titulo: 'Constancia de tiempo de servicios', emitido_en: '22/10/2026', persona, emitible: true,
    tiempos: [tiempo, { numero_resolucion: 'RD-0200-2020', fecha_resolucion: '01/02/2020', anios: 5, meses: 0, dias: 0 }], no_incluidos: [] }, institution))
  assert.ok(texto.includes('RD-0150-2024'))
  assert.ok(texto.includes('RD-0200-2020'))
  assert.ok(!/total/i.test(texto))
  assert.ok(!texto.includes('(17)'))
  assert.ok(texto.includes('no suma resoluciones'))
})

test('long tables and link blocks continue on new pages with their header', () => {
  const pdf = createInformeEscalafonarioPdf({ titulo: 'Informe escalafonario', emitido_en: '22/10/2026', persona, ...vacio, tiempos_reconocidos: [],
    vinculos: Array.from({ length: 12 }, (_, i) => ({ ...vinculo, puesto: `PUESTO ${i}` })),
    formacion: Array.from({ length: 60 }, (_, i) => ({ nivel: 'Curso', denominacion: `GRADO ${i}`, institucion: 'UNIVERSIDAD FICTICIA', fecha_obtencion: null, registro_sunedu: null, sustento: 'Documento aceptado' })) }, institution)
  assert.ok(pdf.getNumberOfPages() > 2)
  const texto = textoPdf(pdf)
  assert.ok(texto.includes('PUESTO 11'))
  // La página donde termina la tabla no es la primera de la tabla: repite su encabezado y el institucional.
  const paginas = Array.from({ length: pdf.getNumberOfPages() }, (_, i) => textoPagina(pdf, i + 1))
  const fin = paginas.findIndex(texto => texto.includes('GRADO 59'))
  assert.ok(fin > 0 && !paginas[fin].includes('GRADO 0 '))
  assert.ok(paginas[fin].includes('DENOMINACI'))
  assert.ok(paginas[fin].includes('Institucion prueba'))
})

test('long values are wrapped, not cut', () => {
  const largo = 'UNIDAD DE PRUEBA CON UN NOMBRE MUY LARGO QUE NO CABE EN UNA SOLA LINEA DE LA COLUMNA FINAL'
  const texto = textoPdf(createConstanciaTrabajoPdf({ titulo: 'Constancia de trabajo', emitido_en: '22/10/2026', persona, emitible: true,
    vinculos: [{ ...vinculo, unidad: largo }], situacion: 'x', no_incluidos: [] }, institution))
  assert.ok(texto.includes(largo))
})

test('the note on the "Aceptado" state appears in both certificates and in the report only when it shows that state', () => {
  const nota = 'No implica fiscalizaci'
  const base = { emitido_en: '22/10/2026', persona, emitible: true, no_incluidos: [] }
  assert.ok(textoPdf(createConstanciaTrabajoPdf({ ...base, titulo: 'Constancia de trabajo', vinculos: [vinculo], situacion: 'x' }, institution)).includes('El estado "Aceptado" indica que el documento fue registrado por el responsable de la carga'))
  assert.ok(textoPdf(createConstanciaTiempoServiciosPdf({ ...base, titulo: 'Constancia de tiempo de servicios', tiempos: [tiempo] }, institution)).includes(nota))
  const informe = sustento => textoPdf(createInformeEscalafonarioPdf({ titulo: 'Informe escalafonario', emitido_en: '22/10/2026', persona, ...vacio, vinculos: [],
    tiempos_reconocidos: [{ ...tiempo, sustento }] }, institution))
  assert.ok(informe('Documento aceptado').includes(nota))
  assert.ok(!informe('Sin documento de sustento').includes(nota))
  assert.ok(!informe('Documento no aceptado (Observado)').includes(nota))
})

// Textos de una página con su altura en mm desde arriba (jsPDF escribe «x y Td (texto) Tj» en puntos desde abajo).
const textosConAltura = (pdf, pagina) => {
  const alto = pdf.internal.pageSize.getHeight()
  return [...pdf.internal.pages[pagina].join('\n').matchAll(/[\d.]+ ([\d.]+) Td\n\(((?:\\.|[^\\)])*)\) Tj/g)]
    .map(m => ({ y: alto - Number(m[1]) * 25.4 / 72, texto: m[2].replace(/\\(.)/g, '$1') }))
}

test('a section title never stays alone at the foot of a page', () => {
  for (let vinculos = 1; vinculos <= 4; vinculos++) {
    for (let filas = 0; filas <= 12; filas++) {
      const pdf = createInformeEscalafonarioPdf({ titulo: 'Informe escalafonario', emitido_en: '22/10/2026', persona, ...vacio,
        vinculos: Array.from({ length: vinculos }, () => vinculo), tiempos_reconocidos: Array.from({ length: filas }, () => tiempo),
        formacion: [{ nivel: 'Doctorado', denominacion: 'DOCTOR FICTICIO', institucion: 'INSTITUCION DE PRUEBA', fecha_obtencion: '01/12/2019' }] }, institution)
      for (let pagina = 1; pagina <= pdf.getNumberOfPages(); pagina++) {
        // El pie («Uso interno», paginación) queda por debajo de 280 mm en A4 con este pie de una línea.
        const contenido = textosConAltura(pdf, pagina).filter(t => t.y < 280)
        for (const t of contenido.filter(t => /^(\d\. [A-Z]|IDENTIFICACI)/.test(t.texto))) {
          assert.ok(contenido.some(o => o.y > t.y + 1), `«${t.texto}» sin contenido debajo (${vinculos} vínculos, ${filas} filas, página ${pagina})`)
        }
      }
    }
  }
})

test('certificates close with the formula and the date in words before the signature, not in the header', () => {
  const pdfs = [
    createConstanciaTrabajoPdf({ titulo: 'Constancia de trabajo', emitido_en: '22/10/2026', persona, emitible: true, vinculos: [vinculo], situacion: 'Vigente', no_incluidos: [] }, institution),
    createConstanciaTiempoServiciosPdf({ titulo: 'Constancia de tiempo de servicios', emitido_en: '22/10/2026', persona, emitible: true, tiempos: [tiempo], no_incluidos: [] }, institution),
  ]
  for (const pdf of pdfs) {
    const texto = textoPdf(pdf)
    assert.ok(texto.includes(FORMULA_CIERRE))
    assert.ok(!texto.includes('Fecha de emisión'))
    const fecha = texto.indexOf('22 de octubre de 2026')
    assert.ok(fecha > texto.indexOf(FORMULA_CIERRE) && fecha < texto.indexOf('Firma y sello del responsable'))
  }
  // El informe no es una constancia: conserva la fecha de emisión en la cabecera y no lleva fórmula de cierre.
  const informe = textoPdf(createInformeEscalafonarioPdf({ titulo: 'Informe escalafonario', emitido_en: '22/10/2026', persona, ...vacio, vinculos: [], tiempos_reconocidos: [] }, institution))
  assert.ok(informe.includes('Fecha de emisión: 22/10/2026') && !informe.includes(FORMULA_CIERRE))
})

test('the date in words never invents a date', () => {
  assert.equal(fechaEnLetras('04/11/2026'), '4 de noviembre de 2026')
  assert.equal(fechaEnLetras('01/09/2026'), '1 de setiembre de 2026')
  assert.equal(fechaEnLetras('2026-11-04'), '2026-11-04')
  assert.equal(fechaEnLetras('31/13/2026'), '31/13/2026')
  assert.equal(fechaEnLetras(null), 'No registrado')
})
