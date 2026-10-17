import { test, expect } from '@playwright/test'

const persona = { id: 'persona', nombres: 'Ana', apellido_paterno: 'Prueba', apellido_materno: '', numero_doc_identidad: '00000001', regimen_laboral: 'CAS', tipo_personal: 'administrativo', activo: true }
const incorporacion = { id: 'ingreso', usuario_id: 'persona', usuario: persona, cargo: 'Analista', estado: 'registrado', version: 1, revisados: 0, tareas: [], historial: [], documentos: [{ id: 'dni', nombre: 'Identificación', estado: 'pendiente', versiones: [] }] }

async function mock(page) {
  await page.route('**/api/v1/**', route => {
    const path = new URL(route.request().url()).pathname
    let data = {}
    if (path.endsWith('/seccion-03')) data = { legajo: { numero_legajo: 'L-01' }, resumen: { aceptados: 0, exigidos: 1, porcentaje: 0, pendientes: 0, observados: 0, faltantes: 1 }, perfil: { tipos_personal: ['administrativo'], puestos: [] }, folio_sugerido: 1, tamano_maximo_kb: 20480, items: [{ id: 'estudios', letra: 'a', nombre: 'Estudios básicos', grupo: 0, estado: 'falta', cantidad: 0, aplica: true, documentos: [] }] }
    if (path.endsWith('/seccion-10')) data = { persona: { nombre_completo: 'Ana Prueba' }, subseccion: '10.1', legajo: { numero_legajo: 'L-01' }, fecha_actual: '2026-10-16', folio_sugerido: 1, tamano_maximo_kb: 20480, folios: { sst: 0, bienestar: 0, total: 0, sin_clasificar: 0 }, items: [{ id: 'sst', nombre: 'Constancia SST', subseccion: '10.1', activo: true, estado: 'no', cantidad: 0, numero_folios: 0, documentos: [] }] }
    if (path.endsWith('/incorporaciones')) return route.fulfill({ json: { data: [incorporacion], total: 1, current_page: 1, last_page: 1 } })
    if (path.endsWith('/incorporaciones/ingreso')) data = incorporacion
    if (path.endsWith('/incorporaciones/opciones')) data = { responsables: [], roles: [] }
    return route.fulfill({ json: { data } })
  })
}

for (const [vista, nombre] of [['formacion', 'Estudios básicos'], ['sst', 'Constancia SST']]) {
  test(`${vista}: adjuntar y consultar usan ventanas con retorno de foco`, async ({ page }) => {
    await mock(page)
    await page.goto(`/e2e/expedientes.html?vista=${vista}`)
    const attach = page.getByRole('button', { name: `Adjuntar documento: ${nombre}` })
    await attach.click()
    await expect(page.getByRole('dialog')).toHaveCount(1)
    await expect(page.getByRole('dialog')).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(page.getByRole('dialog')).toHaveCount(0)
    await expect(attach).toBeFocused()
    const view = page.getByRole('button', { name: vista === 'sst' ? `Ver documentos: ${nombre}` : 'Ver documentos', exact: true })
    await view.click()
    await expect(page.getByRole('dialog', { name: `Documentos de ${nombre}`, exact: true })).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(view).toBeFocused()
    await expect.poll(() => page.evaluate(() => document.body.style.overflow)).toBe('')
  })
}

test('incorporación: detalle organizado, adjunto anidado, validación y retorno al tablero en móvil', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await mock(page)
  await page.goto('/e2e/expedientes.html?vista=incorporacion')
  const trigger = page.getByRole('button', { name: /Ana Prueba/ })
  await trigger.click()
  const detalle = page.getByRole('dialog', { name: 'Detalle de incorporación', exact: true })
  await expect(detalle).toBeVisible()
  await expect(detalle.getByRole('heading', { name: 'Información personal y laboral' })).toBeVisible()
  await expect(detalle.getByRole('heading', { name: 'Resolución y vínculo laboral' })).toBeVisible()
  await page.screenshot({ path: 'test-results/incorporacion-movil.png', fullPage: true })
  await detalle.getByRole('button', { name: 'Adjuntar documento', exact: true }).click()
  const adjunto = page.getByRole('dialog', { name: 'Adjuntar documento: Identificación', exact: true })
  await expect(adjunto).toBeVisible()
  await adjunto.getByLabel('Archivo PDF (máximo 20 MB)').setInputFiles({ name: 'archivo.txt', mimeType: 'text/plain', buffer: Buffer.from('no es pdf') })
  await expect(adjunto.getByRole('alert')).toContainText('Selecciona un PDF válido')
  await expect(adjunto.getByRole('button', { name: 'Guardar documento' })).toBeDisabled()
  await page.keyboard.press('Escape')
  await expect(adjunto).toHaveCount(0)
  await expect(detalle).toBeVisible()
  await expect.poll(() => detalle.evaluate(element => element.scrollWidth <= element.clientWidth + 1)).toBe(true)
  await page.keyboard.press('Escape')
  await expect(trigger).toBeFocused()
  await expect.poll(() => page.evaluate(() => document.body.style.overflow)).toBe('')
})
