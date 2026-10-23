import test from 'node:test'
import assert from 'node:assert/strict'
import { Buffer } from 'node:buffer'
import { imagenAPdf } from './imagenAPdf.js'

test('rechaza formatos no admitidos y archivos grandes antes de decodificarlos', async () => {
  await assert.rejects(imagenAPdf(new File(['contenido'], 'titulo.svg', { type: 'image/svg+xml' }), 2), /JPG o PNG/)
  await assert.rejects(imagenAPdf(new File([new Uint8Array(2048)], 'titulo.png', { type: 'image/png' }), 0.001), /supera/)
})

test('convierte una imagen a un PDF real y libera su URL temporal', async () => {
  const originalImage = globalThis.Image
  const originalRevoke = URL.revokeObjectURL
  let revocada = false
  globalThis.Image = class { naturalWidth = 1; naturalHeight = 1; async decode() {} }
  URL.revokeObjectURL = function (url) { revocada = true; originalRevoke.call(URL, url) }
  try {
    const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4//8/AAX+Av4N70a4AAAAAElFTkSuQmCC', 'base64')
    const pdf = await imagenAPdf(new File([png], 'titulo.png', { type: 'image/png' }), 2)
    assert.equal(pdf.name, 'titulo.pdf')
    assert.equal(pdf.type, 'application/pdf')
    assert.match(await pdf.slice(0, 8).text(), /^%PDF-/)
    assert.equal(revocada, true)
  } finally { globalThis.Image = originalImage; URL.revokeObjectURL = originalRevoke }
})
