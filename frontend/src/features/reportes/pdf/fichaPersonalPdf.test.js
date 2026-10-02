import test from 'node:test'
import assert from 'node:assert/strict'
import { createPersonalPdf } from './fichaPersonalPdf.js'
import { personalGroups, reportValue } from '../config/fichaPersonal.js'

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
