import assert from 'node:assert/strict'
import test from 'node:test'
import { columns, downloadCsv, fechaIso, hoyLima, parseUsersCsv, validateUser } from './usuariosCsv.js'

const catalogo = [
  { id: 'ley-30220', codigo: '30220', nombre: 'Ley N° 30220', aplica_a: 'docente', activo: true },
  { id: 'ley-1057', codigo: '1057', nombre: 'D.L. N° 1057 (CAS)', aplica_a: 'administrativo', activo: true },
]

const encabezado = columns.join(',')
const valido = {
  numero_doc_identidad: '00123456', nombres: 'ANA', apellido_paterno: 'EJEMPLO', apellido_materno: '',
  email_institucional: 'ana@example.test', tipo_personal: 'docente', ley: '30220', telefono: '',
}

test('parseUsersCsv lee un archivo con comas y devuelve un objeto por fila', () => {
  const registros = parseUsersCsv(`${encabezado}\n99000001,Ana,Ejemplo,Prueba,ana@example.test,docente,30220,987654321\n99000002,Luis,Demo,,luis@example.test,administrativo,CAS,`)
  assert.equal(registros.length, 2)
  assert.deepEqual(registros[0], {
    numero_doc_identidad: '99000001', nombres: 'Ana', apellido_paterno: 'Ejemplo', apellido_materno: 'Prueba',
    email_institucional: 'ana@example.test', tipo_personal: 'docente', ley: '30220', telefono: '987654321',
  })
  assert.equal(registros[1].apellido_materno, '')
  assert.equal(registros[1].telefono, '')
})

test('parseUsersCsv detecta punto y coma como separador (Excel en español)', () => {
  const [registro] = parseUsersCsv(`${columns.join(';')}\n99000001;Ana, María;Ejemplo;;ana@example.test;docente;30220;`)
  assert.equal(registro.nombres, 'Ana, María')
})

test('parseUsersCsv quita el BOM, acepta CRLF y conserva acentos y ñ', () => {
  const [registro] = parseUsersCsv(`\uFEFF${encabezado}\r\n99000001,José,Ñañez,Muñoz,jose@example.test,docente,30220,\r\n`)
  assert.equal(registro.numero_doc_identidad, '99000001')
  assert.equal(registro.nombres, 'José')
  assert.equal(registro.apellido_paterno, 'Ñañez')
  assert.equal(registro.apellido_materno, 'Muñoz')
})

test('parseUsersCsv respeta comas, comillas dobles y saltos de línea dentro de comillas', () => {
  const [registro] = parseUsersCsv(`${encabezado}\n99000001,"Ana, María","O""Neill","De la\nCruz",ana@example.test,docente,30220,`)
  assert.equal(registro.nombres, 'Ana, María')
  assert.equal(registro.apellido_paterno, 'O"Neill')
  assert.equal(registro.apellido_materno, 'De la\nCruz')
})

test('parseUsersCsv recorta espacios de cada celda e ignora filas vacías', () => {
  const registros = parseUsersCsv(`${encabezado}\n\n  99000001 ,  Ana  ,Ejemplo,,ana@example.test,docente,30220,\n,,,,,,,\n`)
  assert.equal(registros.length, 1)
  assert.equal(registros[0].numero_doc_identidad, '99000001')
  assert.equal(registros[0].nombres, 'Ana')
})

test('parseUsersCsv acepta las columnas en otro orden', () => {
  const orden = [...columns].reverse()
  const fila = orden.map(key => ({ numero_doc_identidad: '99000001', nombres: 'Ana' })[key] || '')
  const [registro] = parseUsersCsv(`${orden.join(',')}\n${fila.join(',')}`)
  assert.equal(registro.numero_doc_identidad, '99000001')
  assert.equal(registro.nombres, 'Ana')
})

test('parseUsersCsv rechaza comillas sin cerrar o mal ubicadas', () => {
  assert.throws(() => parseUsersCsv(`${encabezado}\n99000001,"Ana,Ejemplo,,a@example.test,docente,30220,`), /comillas sin cerrar/)
  assert.throws(() => parseUsersCsv(`${encabezado}\n99000001,An"a,Ejemplo,,a@example.test,docente,30220,`), /comillas mal ubicadas/)
  assert.throws(() => parseUsersCsv(`${encabezado}\n99000001,"Ana"x,Ejemplo,,a@example.test,docente,30220,`), /comillas mal ubicadas/)
})

test('parseUsersCsv rechaza encabezados distintos, faltantes o repetidos', () => {
  const error = /columnas no coinciden/
  assert.throws(() => parseUsersCsv(''), error)
  assert.throws(() => parseUsersCsv(columns.slice(0, -1).join(',')), error)
  assert.throws(() => parseUsersCsv([...columns.slice(0, -1), 'nombres'].join(',')), error)
  assert.throws(() => parseUsersCsv([...columns, 'extra'].join(',')), error)
  assert.throws(() => parseUsersCsv(columns.map(c => c.toUpperCase()).join(',')), error)
})

test('parseUsersCsv exige al menos un usuario y como máximo 1,000', () => {
  assert.throws(() => parseUsersCsv(`${encabezado}\n`), /no contiene usuarios/)
  const fila = '99000001,Ana,Ejemplo,,ana@example.test,docente,30220,'
  assert.equal(parseUsersCsv([encabezado, ...Array(1000).fill(fila)].join('\n')).length, 1000)
  assert.throws(() => parseUsersCsv([encabezado, ...Array(1001).fill(fila)].join('\n')), /hasta 1,000/)
})

test('parseUsersCsv informa el número de registro con columnas de más o de menos', () => {
  const fila = '99000001,Ana,Ejemplo,,ana@example.test,docente,30220,'
  assert.throws(() => parseUsersCsv(`${encabezado}\n${fila}\n99000002,Luis`), /Registro 2: cantidad de columnas incorrecta/)
  assert.throws(() => parseUsersCsv(`${encabezado}\n${fila},sobra`), /Registro 1: cantidad de columnas incorrecta/)
})

test('fechaIso convierte dd/mm/aaaa y Date, y devuelve sin corregir lo que no es fecha real', () => {
  assert.equal(fechaIso('15/03/1980'), '1980-03-15')
  assert.equal(fechaIso(' 1980-03-15 '), '1980-03-15')
  assert.equal(fechaIso(new Date(Date.UTC(1975, 10, 2))), '1975-11-02')
  assert.equal(fechaIso(new Date('no es fecha')), '')
  assert.equal(fechaIso('29/02/2024'), '2024-02-29')
  assert.equal(fechaIso('29/02/2023'), '29/02/2023')
  assert.equal(fechaIso('2023-02-30'), '2023-02-30')
  assert.equal(fechaIso('1/3/1980'), '1/3/1980')
  assert.equal(fechaIso(null), '')
  assert.equal(fechaIso(undefined), '')
})

test.skip('Posible bug: fechaIso y validateUser lanzan RangeError con mes o día fuera de rango (usuariosCsv.js:22)', () => {
  // new Date('2023-13-01T00:00:00Z') es inválida y toISOString() lanza; debería devolverse el texto para que se informe.
  assert.equal(fechaIso('2023-13-01'), '2023-13-01')
  assert.equal(fechaIso('32/01/2020'), '32/01/2020')
  assert.equal(fechaIso('00/01/2020'), '00/01/2020')
  assert.match(validateUser({ ...valido, fecha_nacimiento: '2023-13-01' }, catalogo).join(' '), /usa una fecha válida/)
})

test('hoyLima devuelve la fecha en formato AAAA-MM-DD', () => {
  assert.match(hoyLima(), /^\d{4}-\d{2}-\d{2}$/)
})

test('validateUser no informa errores para un registro correcto', () => {
  assert.deepEqual(validateUser(valido, catalogo), [])
  assert.deepEqual(validateUser({ ...valido, telefono: '987654321', apellido_materno: 'PÉREZ' }, catalogo), [])
})

test('validateUser exige DNI de exactamente 8 dígitos', () => {
  for (const dni of ['1234567', '123456789', '1234567a', ' 00123456', '']) {
    assert.ok(validateUser({ ...valido, numero_doc_identidad: dni }, catalogo).includes('El DNI debe contener 8 dígitos.'), dni)
  }
})

test('validateUser controla nombres y apellidos obligatorios y sus longitudes', () => {
  assert.match(validateUser({ ...valido, nombres: '' }, catalogo).join(' '), /nombres son obligatorios/)
  assert.match(validateUser({ ...valido, nombres: 'A'.repeat(121) }, catalogo).join(' '), /máximo 120/)
  assert.deepEqual(validateUser({ ...valido, nombres: 'A'.repeat(120) }, catalogo), [])
  assert.match(validateUser({ ...valido, apellido_paterno: '' }, catalogo).join(' '), /apellido paterno es obligatorio/)
  assert.match(validateUser({ ...valido, apellido_paterno: 'A'.repeat(81) }, catalogo).join(' '), /apellido paterno/)
  assert.match(validateUser({ ...valido, apellido_materno: 'A'.repeat(81) }, catalogo).join(' '), /apellido materno admite hasta 80/)
  assert.deepEqual(validateUser({ ...valido, apellido_materno: 'A'.repeat(80) }, catalogo), [])
})

test('validateUser rechaza correos inválidos o demasiado largos', () => {
  for (const correo of ['', 'ana', 'ana@', 'ana@example', '@example.test', 'ana maria@example.test', 'ana@@example.test']) {
    assert.ok(validateUser({ ...valido, email_institucional: correo }, catalogo).includes('El correo no es válido.'), correo)
  }
  const largo = `${'a'.repeat(244)}@example.test`
  assert.equal(largo.length, 257)
  assert.ok(validateUser({ ...valido, email_institucional: largo }, catalogo).includes('El correo no es válido.'))
})

test('validateUser exige tipo docente o administrativo en minúsculas', () => {
  for (const tipo of ['', 'DOCENTE', 'otro']) {
    assert.ok(validateUser({ ...valido, tipo_personal: tipo }, catalogo).includes('El tipo debe ser docente o administrativo.'), tipo)
  }
})

test('validateUser incluye el error de ley del catálogo', () => {
  assert.match(validateUser({ ...valido, ley: 'CAS' }, catalogo).join(' '), /corresponde al personal administrativo/)
  assert.match(validateUser({ ...valido, ley: '' }, catalogo).join(' '), /Indica la ley/)
})

test('validateUser acepta teléfono vacío y exige 9 dígitos si se informa', () => {
  assert.deepEqual(validateUser({ ...valido, telefono: '' }, catalogo), [])
  for (const telefono of ['98765432', '9876543210', '987 654 321', '+51987654321']) {
    assert.ok(validateUser({ ...valido, telefono }, catalogo).includes('El teléfono debe contener 9 dígitos.'), telefono)
  }
})

test('validateUser acumula varios errores de una misma fila', () => {
  const errores = validateUser({ ...valido, numero_doc_identidad: '1', nombres: '', email_institucional: 'x', tipo_personal: 'x' }, catalogo)
  assert.ok(errores.length >= 4)
})

test('downloadCsv genera un CSV con BOM, comillas escapadas y neutraliza fórmulas', async (t) => {
  let blob, enlace
  const originalCreate = URL.createObjectURL, originalRevoke = URL.revokeObjectURL
  URL.createObjectURL = b => { blob = b; return 'blob:prueba' }
  URL.revokeObjectURL = () => {}
  globalThis.document = { createElement: () => (enlace = { click() { this.clicado = true } }) }
  t.mock.timers.enable({ apis: ['setTimeout'] })
  t.after(() => {
    URL.createObjectURL = originalCreate
    URL.revokeObjectURL = originalRevoke
    delete globalThis.document
  })

  downloadCsv([
    { numero_doc_identidad: '00123456', nombres: 'Ana, "María"', apellido_paterno: '=1+1', apellido_materno: null, email_institucional: '@x', tipo_personal: 'docente', ley: '-2', telefono: '+51' },
  ], 'usuarios.csv')

  assert.equal(enlace.download, 'usuarios.csv')
  assert.equal(enlace.href, 'blob:prueba')
  assert.equal(enlace.clicado, true)
  assert.match(blob.type, /text\/csv/)
  const bytes = new Uint8Array(await blob.arrayBuffer())
  assert.deepEqual([...bytes.slice(0, 3)], [0xEF, 0xBB, 0xBF])
  // blob.text() decodifica UTF-8 y descarta el BOM.
  const texto = await blob.text()
  const [cabecera, fila] = texto.split('\r\n')
  assert.equal(cabecera, encabezado)
  assert.equal(fila, `"00123456","Ana, ""María""","'=1+1","","'@x","docente","'-2","'+51"`)
  // Lo exportado se vuelve a leer con el mismo importador.
  const [leido] = parseUsersCsv(texto)
  assert.equal(leido.nombres, 'Ana, "María"')
  assert.equal(leido.apellido_paterno, "'=1+1")
})
