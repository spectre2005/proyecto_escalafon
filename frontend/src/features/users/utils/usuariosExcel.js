import { columns, parseUsersCsv } from './usuariosCsv.js'

export const excelFields = [
  ['numero_doc_identidad', 'DNI *', 14], ['nombres', 'Nombres *', 25],
  ['apellido_paterno', 'Apellido paterno *', 24], ['apellido_materno', 'Apellido materno', 24],
  ['email_institucional', 'Correo institucional *', 38], ['telefono', 'Teléfono', 16],
  ['tipo_personal', 'Tipo de personal *', 24], ['regimen_laboral', 'Régimen laboral *', 24],
  ['region', 'Región', 24], ['provincia', 'Provincia', 24], ['distrito', 'Distrito', 24],
]

async function workbook() {
  const { default: ExcelJS } = await import('exceljs')
  return new ExcelJS.Workbook()
}

export async function createUsersTemplate() {
  const book = await workbook()
  const sheet = book.addWorksheet('Personal', { views: [{ state: 'frozen', ySplit: 1 }] })
  sheet.columns = excelFields.map(([key, header, width]) => ({ key, header, width, style: { numFmt: '@' } }))
  sheet.getRow(1).height = 32
  sheet.getRow(1).eachCell(cell => {
    cell.font = { bold: true, color: { argb: 'FFFFFFFF' }, size: 11 }
    cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF0F766E' } }
    cell.alignment = { vertical: 'middle', wrapText: true }
  })
  sheet.autoFilter = 'A1:K1001'
  excelFields.forEach(([, label], index) => { sheet.getCell(1, index + 1).note = label.includes('*') ? 'Campo obligatorio.' : 'Campo opcional.' })
  for (let row = 2; row <= 1001; row++) {
    sheet.getCell(row, 7).dataValidation = { type: 'list', allowBlank: false, formulae: ['"docente,administrativo"'], showErrorMessage: true, errorTitle: 'Tipo inválido', error: 'Selecciona docente o administrativo.' }
    sheet.getCell(row, 8).dataValidation = { type: 'list', allowBlank: false, formulae: [`IF(G${row}="docente","30220","CAS,276,728,30057")`], showErrorMessage: true, errorTitle: 'Régimen inválido', error: 'Selecciona un régimen correspondiente al tipo de personal.' }
  }
  const help = book.addWorksheet('Instrucciones')
  help.columns = [{ width: 30 }, { width: 100 }]
  const instructions = [
    ['Campo', 'Cómo completar la plantilla'],
    ['Registro', 'Completa la hoja Personal desde la fila 2. Una fila por usuario. Máximo 1,000 usuarios. No cambies los encabezados.'],
    ['Obligatorios', 'DNI, nombres, apellido paterno, correo institucional, tipo de personal y régimen laboral.'],
    ['DNI', '8 dígitos. Las celdas están en formato texto para conservar ceros iniciales.'],
    ['Teléfono', 'Opcional. 9 dígitos, sin espacios ni prefijos.'],
    ['Docentes', 'Tipo: docente. Régimen: 30220 (Ley Universitaria).'],
    ['Administrativos', 'Tipo: administrativo. Régimen: CAS, 276, 728 o 30057.'],
    ['Ubicación', 'Opcional. Completa región, provincia y distrito juntos, con los nombres del catálogo del formulario manual.'],
    ['Validación', 'No repitas DNI ni correo. Revisa la vista previa y corrige los errores antes de guardar.'],
    ['Ejemplo ficticio', 'DNI: 99000001 | Nombres: Ana | Apellido paterno: Ejemplo | Correo: ana.ejemplo@example.test | Tipo: docente | Régimen: 30220'],
  ]
  instructions.forEach(row => help.addRow(row))
  help.getRow(1).font = { bold: true }
  help.eachRow(row => { row.alignment = { wrapText: true, vertical: 'top' }; row.height = 40 })
  return book.xlsx.writeBuffer()
}

export async function downloadUsersTemplate() {
  const buffer = await createUsersTemplate()
  const url = URL.createObjectURL(new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }))
  const link = document.createElement('a')
  link.href = url
  link.download = 'plantilla_personal.xlsx'
  link.click()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

export async function parseUsersExcel(buffer) {
  const book = await workbook()
  await book.xlsx.load(buffer)
  const sheet = book.getWorksheet('Personal')
  if (!sheet) throw new Error('No se encontró la hoja Personal. Utiliza la plantilla de Excel.')
  const headers = sheet.getRow(1).values.slice(1)
  const keys = headers.map(label => excelFields.find(field => field[1] === label)?.[0])
  if (keys.length !== excelFields.length || keys.some(key => !key) || new Set(keys).size !== keys.length) throw new Error('Los encabezados no coinciden con la plantilla de Excel.')
  const records = []
  sheet.eachRow((row, number) => {
    if (number === 1) return
    const record = {}
    keys.forEach((key, index) => {
      const cell = row.getCell(index + 1)
      if (cell.type === 6 || cell.type === 10) throw new Error(`Fila ${number}: utiliza valores de texto, sin fórmulas ni errores.`)
      record[key] = cell.text.trim()
    })
    if (!Object.values(record).some(Boolean)) return
    records.push(record)
  })
  if (!records.length) throw new Error('El archivo no contiene usuarios.')
  if (records.length > 1000) throw new Error('Se permiten hasta 1,000 usuarios por archivo.')
  return records
}

export async function parseUsersFile(file) {
  if (/\.xlsx$/i.test(file.name)) return parseUsersExcel(await file.arrayBuffer())
  if (/\.csv$/i.test(file.name)) return parseUsersCsv(await file.text())
  throw new Error('Selecciona un archivo Excel (.xlsx) o CSV (.csv).')
}

export function resolveUsersLocations(records, locations) {
  const normalize = text => text.normalize('NFD').replace(/\p{M}/gu, '').trim().toLocaleLowerCase()
  return records.map((record, index) => {
    const user = Object.fromEntries(columns.map(key => [key, record[key] || '']))
    user.tipo_personal = user.tipo_personal.toLowerCase()
    if (user.regimen_laboral.toLowerCase() === 'cas') user.regimen_laboral = 'CAS'
    const fields = ['region', 'provincia', 'distrito']
    if (!fields.some(key => record[key])) return user
    if (fields.some(key => !record[key])) throw new Error(`Registro ${index + 1}: completa región, provincia y distrito juntos.`)
    const match = locations.find(location => fields.every(key => normalize(location[key]) === normalize(record[key])))
    if (!match) throw new Error(`Registro ${index + 1}: la ubicación no existe en el catálogo.`)
    return { ...user, ubigeo_id: match.id }
  })
}
