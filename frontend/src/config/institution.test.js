import test from 'node:test'
import assert from 'node:assert/strict'
import { normalizeInstitution } from './institution.js'

test('keeps existing identity when upgrading legacy browser settings', () => {
  const settings = normalizeInstitution({ name: 'Universidad de prueba', area: 'Recursos Humanos', initials: 'UP' })
  assert.equal(settings.name, 'Universidad de prueba')
  assert.equal(settings.area, 'Recursos Humanos')
  assert.equal(settings.showLogo, true)
  assert.equal(settings.logo, '')
})

test('preserves disabled report options and rejects unsafe logo URLs', () => {
  const settings = normalizeInstitution({ logo: 'javascript:alert(1)', reportColor: 'invalid', showLogo: false, showEmissionDate: false, showPageNumbers: false })
  assert.equal(settings.logo, '')
  assert.equal(settings.reportColor, '#0f766e')
  assert.equal(settings.showLogo, false)
  assert.equal(settings.showEmissionDate, false)
  assert.equal(settings.showPageNumbers, false)
})
