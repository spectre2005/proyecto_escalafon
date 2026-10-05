import assert from 'node:assert/strict'
import test from 'node:test'
import { formasPermiso, licenciasOtorgadas, movimientoDe, movimientosLicencia, opcionesLicencia, opcionesMovimiento, opcionesVacacion, tiposLicencia } from './seccion05.js'

// Movimientos que acredita cada tipo de documento (backend: App\Support\Seccion05::TIPOS_DOCUMENTO).
const apruebaLicenciaPermiso = ['permiso', ...licenciasOtorgadas]

test('the 13 schema types split into 4 movements without loss', () => {
  assert.deepEqual(Object.keys(tiposLicencia).map(movimientoDe).filter(m => !m), [])
  assert.deepEqual(Object.keys(tiposLicencia).filter(tipo => movimientoDe(tipo) === 'licencia'), licenciasOtorgadas)
  assert.equal(licenciasOtorgadas.length, 10)
  assert.equal(movimientoDe('vacaciones'), null)
})

test('movement options follow the uploaded document and health access', () => {
  assert.deepEqual(opcionesMovimiento(), ['permiso', 'suspension', 'descanso_medico', 'licencia'])
  assert.deepEqual(opcionesMovimiento(undefined, false), ['permiso', 'suspension', 'licencia'])
  assert.deepEqual(opcionesMovimiento(apruebaLicenciaPermiso), ['permiso', 'licencia'])
  assert.deepEqual(opcionesMovimiento(licenciasOtorgadas), ['licencia'])
  assert.deepEqual(opcionesMovimiento(['suspension']), ['suspension'])
  assert.deepEqual(opcionesMovimiento(['descanso_medico'], false), [])
  assert.deepEqual(opcionesLicencia(apruebaLicenciaPermiso), licenciasOtorgadas)
  assert.deepEqual(opcionesLicencia(['suspension']), [])
})

test('suspension does not show nor send "con goce" until the university validates it', () => {
  assert.equal(movimientosLicencia.suspension.conGoce, false)
  assert.deepEqual(Object.keys(movimientosLicencia).filter(m => movimientosLicencia[m].conGoce), ['permiso', 'descanso_medico', 'licencia'])
})

test('vacation options: rol only without a linked document', () => {
  assert.deepEqual(opcionesVacacion(), ['goce', 'adelanto_postergacion', 'rol'])
  assert.deepEqual(opcionesVacacion(['goce']), ['goce'])
  assert.deepEqual(opcionesVacacion(['adelanto', 'postergacion']), ['adelanto_postergacion'])
})

test('permit forms match the backend list (App\\Support\\Seccion05::FORMAS_PERMISO)', () => {
  assert.deepEqual(Object.keys(formasPermiso), ['papeleta', 'memorandum', 'otro'])
  assert.deepEqual(Object.values(formasPermiso).map(forma => forma.etiqueta), ['Papeleta', 'Memorándum', 'Otro'])
})
