import { regimenesPorTipo } from './regimenesLaborales.js'

export const columns = ['numero_doc_identidad', 'nombres', 'apellido_paterno', 'apellido_materno', 'email_institucional', 'tipo_personal', 'regimen_laboral', 'telefono']

export function validateUser(user) {
  const errors = []
  if (!/^\d{8}$/.test(user.numero_doc_identidad)) errors.push('El DNI debe contener 8 dígitos.')
  if (!user.nombres || user.nombres.length > 120) errors.push('Los nombres son obligatorios (máximo 120 caracteres).')
  if (!user.apellido_paterno || user.apellido_paterno.length > 80) errors.push('El apellido paterno es obligatorio (máximo 80 caracteres).')
  if (user.apellido_materno.length > 80) errors.push('El apellido materno admite hasta 80 caracteres.')
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(user.email_institucional) || user.email_institucional.length > 255) errors.push('El correo no es válido.')
  if (!['docente', 'administrativo'].includes(user.tipo_personal)) errors.push('El tipo debe ser docente o administrativo.')
  const regimenes = (regimenesPorTipo[user.tipo_personal] || []).map(regimen => regimen.value)
  if (regimenes.length && !regimenes.includes(user.regimen_laboral)) errors.push(`El régimen laboral debe ser uno de: ${regimenes.join(', ')}.`)
  if (user.telefono && !/^[0-9]{9}$/.test(user.telefono)) errors.push('El teléfono debe contener 9 dígitos.')
  return errors
}

export function parseUsersCsv(text) {
  const source = text.replace(/^\uFEFF/, '')
  const delimiter = source.split(/\r?\n/)[0].includes(';') ? ';' : ','
  const rows = []
  let row = [], value = '', quoted = false, closed = false
  for (let i = 0; i < source.length; i++) {
    const char = source[i]
    if (quoted) {
      if (char === '"' && source[i + 1] === '"') { value += '"'; i++ }
      else if (char === '"') { quoted = false; closed = true }
      else value += char
    } else if (char === '"' && value === '' && !closed) quoted = true
    else if (char === delimiter || char === '\n' || char === '\r') {
      row.push(value.trim()); value = ''; closed = false
      if (char !== delimiter) {
        if (row.some(Boolean)) rows.push(row)
        row = []
        if (char === '\r' && source[i + 1] === '\n') i++
      }
    } else {
      if (closed || char === '"') throw new Error('El CSV contiene comillas mal ubicadas.')
      value += char
    }
  }
  if (quoted) throw new Error('El CSV contiene comillas sin cerrar.')
  row.push(value.trim())
  if (row.some(Boolean)) rows.push(row)
  const header = rows.shift() || []
  if (header.length !== columns.length || new Set(header).size !== columns.length || columns.some(key => !header.includes(key))) {
    throw new Error('Las columnas no coinciden con la plantilla. Descarga la plantilla e inténtalo nuevamente.')
  }
  if (!rows.length) throw new Error('El archivo no contiene usuarios.')
  if (rows.length > 1000) throw new Error('Se permiten hasta 1,000 usuarios por archivo.')
  return rows.map((cells, index) => {
    if (cells.length !== header.length) throw new Error(`Registro ${index + 1}: cantidad de columnas incorrecta.`)
    return Object.fromEntries(header.map((key, i) => [key, cells[i]]))
  })
}

export function downloadCsv(users, filename) {
  const escape = value => `"${String(value ?? '').replace(/"/g, '""').replace(/^[=+@-]/, "'$&")}"`
  const content = [columns.join(','), ...users.map(user => columns.map(key => escape(user[key])).join(','))].join('\r\n')
  const url = URL.createObjectURL(new Blob(['\uFEFF' + content], { type: 'text/csv;charset=utf-8;' }))
  const link = document.createElement('a')
  link.href = url; link.download = filename; link.click()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
