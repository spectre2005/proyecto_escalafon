import test from 'node:test'
import assert from 'node:assert/strict'
import { normalizeInstitution } from './institution.js'
import { institutionPageTheme } from './pageTheme.js'

test('old settings keep default page colors and invalid color values are rejected', () => {
  assert.equal(normalizeInstitution({ name: 'Institucion' }).pageColor, '#0f766e')
  assert.equal(normalizeInstitution({ pageColor: 'red', pageBackground: 'url(evil)' }).pageBackground, '#f6f8fa')
  assert.equal(normalizeInstitution({ pageColor: '#654321', pageBackground: '#faf5ff' }).pageColor, '#654321')
})

test('page colors and report colors are independent', () => {
  const settings = normalizeInstitution({ pageColor: '#654321', pageBackground: '#faf5ff', reportColor: '#123456' })
  const theme = institutionPageTheme(settings)
  assert.equal(theme['--page-background'], '#faf5ff')
  assert.equal(theme['--color-teal-700'], '#654321')
  assert.equal(settings.reportColor, '#123456')
  for (const color of Object.values(theme)) assert.match(color, /^#[a-f0-9]{6}$/)
})

test('even white and yellow primary colors produce readable white-text buttons', () => {
  for (const pageColor of ['#ffffff', '#ffff00', '#00ff00']) {
    const color = institutionPageTheme({ pageColor })['--color-teal-700']
    const linear = color.match(/[a-f0-9]{2}/gi).map(part => parseInt(part, 16) / 255).map(c => c <= .04045 ? c / 12.92 : ((c + .055) / 1.055) ** 2.4)
    const luminance = linear.reduce((sum, channel, index) => sum + channel * [.2126, .7152, .0722][index], 0)
    assert.ok(1.05 / (luminance + .05) >= 4.5)
  }
})
