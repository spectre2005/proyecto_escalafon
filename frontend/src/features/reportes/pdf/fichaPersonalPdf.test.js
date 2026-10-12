import test from 'node:test'
import assert from 'node:assert/strict'
import { createPersonalPdf } from './fichaPersonalPdf.js'
import { personalGroups, reportValue } from '../config/fichaPersonal.js'

test('includes institutional logo, identity and contact information on a multipage report', () => {
  const logo = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFklEQVQImWPkL8tjYGBgYmBgYGBgAAALLwD3dKaQQgAAAABJRU5ErkJggg=='
  const pdf = createPersonalPdf({ institution: { name: 'Institucion prueba', area: 'Area de personal', logo, ruc: '12345678901', email: 'contacto@example.test', reportTitle: 'Reporte institucional', reportFooter: 'Uso interno' }, families: Array.from({ length: 15 }, (_, i) => ({ values: { apellidos_nombres: `Familiar ${i}` } })) })
  const output = pdf.output()
  assert.ok(output.includes('/Subtype /Image'))
  assert.ok(output.includes('12345678901'))
  assert.ok(output.includes('contacto@example.test'))
  assert.ok(output.includes('REPORTE INSTITUCIONAL'))
  assert.ok(output.includes('Uso interno'))
  for (let page = 1; page <= pdf.getNumberOfPages(); page++) {
    const text = pdf.internal.pages[page].join('\n')
    assert.ok(text.includes('Institucion prueba'))
    assert.ok(text.includes('Uso interno'))
  }
})

test('report preferences can hide the logo, emission date and numbering', () => {
  const output = createPersonalPdf({ institution: { name: 'Institucion prueba', logo: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFklEQVQImWPkL8tjYGBgYmBgYGBgAAALLwD3dKaQQgAAAABJRU5ErkJggg==', showLogo: false, showEmissionDate: false, showPageNumbers: false } }).output()
  assert.ok(!output.includes('/Subtype /Image'))
  assert.ok(!output.includes('Fecha de'))
  assert.ok(!output.includes(' de 1'))
})

test('exports classified personal data and preserves leading zeros without technical or credential fields', () => {
  const pdf = createPersonalPdf({ values: { nombres: 'Persona ficticia', numero_doc_identidad: '00112233', password: 'SECRET_SENTINEL', foto_path: 'PRIVATE_PATH_SENTINEL' } })
  const output = pdf.output()
  assert.ok(output.startsWith('%PDF-'))
  assert.ok(output.includes('Persona ficticia'))
  assert.ok(output.includes('00112233'))
  assert.ok(!output.includes('SECRET_SENTINEL'))
  assert.ok(!output.includes('PRIVATE_PATH_SENTINEL'))
  assert.equal(personalGroups.length, 4)
  assert.equal(reportValue(false), 'No')
  // Banderas del perfil: Sí / No / Sin responder; lo que no llegó sigue «No registrado».
  assert.equal(reportValue(null, false, 'con_discapacidad'), 'Sin responder')
  assert.equal(reportValue(false, false, 'con_discapacidad'), 'No')
  assert.equal(reportValue(undefined, false, 'con_discapacidad'), 'No registrado')
  assert.equal(reportValue(null, false, 'telefono'), 'No registrado')
  assert.equal(reportValue('2000-01-02'), '02/01/2000')
})

test('blank sheet ignores any supplied personal values and leaves boxes empty', () => {
  const output = createPersonalPdf({ blank: true, values: { nombres: 'PRIVATE_NAME_SENTINEL' } }).output()
  assert.ok(!output.includes('PRIVATE_NAME_SENTINEL'))
  assert.ok(!output.includes('No registrado'))
  assert.ok(output.includes('FIRMA DEL PERSONAL'))
})

test('paginates families and long content without dropping the last record', () => {
  const families = Array.from({ length: 30 }, (_, index) => ({ values: { apellidos_nombres: `Familiar ficticio ${index}`, parentesco: 'Hijo', motivo_anulacion: 'Texto '.repeat(400) + `FINAL_${index}` } }))
  const pdf = createPersonalPdf({ families })
  assert.ok(pdf.getNumberOfPages() > 5)
  assert.ok(pdf.output().includes('FINAL_29'))
})
