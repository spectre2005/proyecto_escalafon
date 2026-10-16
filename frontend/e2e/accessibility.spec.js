import { test, expect } from '@playwright/test'
import { jsPDF } from 'jspdf'

async function mockApi(page, authenticated = true) {
  await page.route('**/sanctum/csrf-cookie', route => route.fulfill({ status: 204 }))
  await page.route('**/api/v1/**', route => {
    const path = new URL(route.request().url()).pathname
    const fulfill = (body, status = 200) => route.fulfill({ status, json: body })
    if (path.endsWith('/auth/me')) return authenticated
      ? fulfill({ data: { usuario: { id: 'test-person', nombres: 'Persona', apellido_paterno: 'Prueba', email_institucional: 'persona@example.test' }, permisos: ['personal.ver', 'usuarios.gestionar', 'sistema.administrar'], ve_datos_sensibles: false } })
      : fulfill({ message: 'No autenticado' }, 401)
    if (path.endsWith('/auth/login')) return fulfill({ errors: { email: ['Correo o contraseña incorrectos.'] } }, 422)
    if (path.endsWith('/institucion')) return fulfill({ configured: false, data: {}, version: 'test', updated_at: null })
    if (path.endsWith('/usuarios/resumen')) return fulfill({ data: { total: 0, activos: 0, inactivos: 0, docentes: 0, administrativos: 0, con_correo: 0 } })
    if (path.endsWith('/usuarios')) return fulfill({ data: [], meta: { current_page: 1, last_page: 1, total: 0 }, resumen: { total: 0, docentes: 0, administrativos: 0 } })
    return fulfill({ data: [] })
  })
}

test('login labels, password toggle and recoverable error work with keyboard', async ({ page }) => {
  await mockApi(page, false)
  await page.goto('/login_principal')
  const email = page.getByLabel('Correo institucional', { exact: true })
  await email.focus()
  await email.fill('persona@example.test')
  await page.keyboard.press('Tab')
  const password = page.getByLabel('Contraseña', { exact: true })
  await expect(password).toBeFocused()
  await password.fill('clave-de-prueba')
  await page.keyboard.press('Tab')
  await page.keyboard.press('Enter')
  await expect(password).toHaveAttribute('type', 'text')
  await page.keyboard.press('Tab')
  await page.keyboard.press('Enter')
  await expect(page.getByRole('alert')).toContainText('Correo o contraseña incorrectos.')
  await expect(page.getByRole('button', { name: /Ingresar al sistema/ })).toBeEnabled()
})

test('registration dialog traps focus, closes on Escape and restores its trigger', async ({ page }) => {
  await mockApi(page)
  await page.goto('/personal')
  const trigger = page.getByRole('button', { name: 'Agregar personal', exact: true })
  await trigger.focus()
  await page.keyboard.press('Enter')
  const dialog = page.getByRole('dialog')
  await expect(dialog).toBeVisible()
  for (let i = 0; i < 18; i++) {
    await page.keyboard.press('Tab')
    await expect.poll(() => dialog.evaluate(element => element.contains(document.activeElement))).toBe(true)
  }
  await page.keyboard.press('Escape')
  await expect(dialog).not.toBeVisible()
  await expect(trigger).toBeFocused()
})

test('account disclosure closes on Escape and restores focus', async ({ page }) => {
  await mockApi(page)
  await page.goto('/personal')
  const trigger = page.getByRole('button', { name: /cuenta/i })
  await trigger.focus()
  await page.keyboard.press('Enter')
  await expect(trigger).toHaveAttribute('aria-expanded', 'true')
  await page.keyboard.press('Escape')
  await expect(trigger).toHaveAttribute('aria-expanded', 'false')
  await expect(trigger).toBeFocused()
})

test('directory requests only the selected server page and resets it when filtering', async ({ page }) => {
  await mockApi(page)
  const requests = []
  await page.route('**/api/v1/usuarios?**', route => {
    const params = new URL(route.request().url()).searchParams
    requests.push(params.toString())
    const current = Number(params.get('page'))
    return route.fulfill({ json: { data: [{ id: `person-${current}`, nombres: `Persona página ${current}`, apellido_paterno: 'Prueba', apellido_materno: '', email_institucional: 'persona@example.test', numero_doc_identidad: '99000001', tipo_personal: 'docente', regimen_laboral: 'carrera', activo: true }], meta: { current_page: current, last_page: 3, total: 21 }, resumen: { total: 21, docentes: 21, administrativos: 0 } } })
  })
  await page.goto('/personal')
  await expect(page.getByRole('link', { name: 'Persona página 1 Prueba', exact: true })).toBeVisible()
  expect(requests.every(query => new URLSearchParams(query).get('page') === '1')).toBe(true)
  expect(new URLSearchParams(requests[0]).get('per_page')).toBe('10')
  await page.getByRole('button', { name: 'Siguiente', exact: true }).click()
  await expect(page.getByRole('link', { name: 'Persona página 2 Prueba', exact: true })).toBeVisible()
  await page.getByLabel('Buscar usuarios', { exact: true }).fill('Persona')
  await expect.poll(() => requests.some(query => {
    const params = new URLSearchParams(query)
    return params.get('page') === '1' && params.get('q') === 'Persona'
  })).toBe(true)
})

test('PDF viewer can download and close using the keyboard', async ({ page }) => {
  const pdf = new jsPDF()
  pdf.text('Documento de prueba', 20, 20)
  const content = Buffer.from(pdf.output('arraybuffer'))
  await page.route('**/api/v1/expedientes/**/archivo?**', route => route.fulfill({ contentType: 'application/pdf', body: content }))
  await page.goto('/e2e/visor.html')
  await expect(page.getByTitle('Documento de prueba')).toBeVisible()
  const downloadButton = page.getByRole('button', { name: 'Descargar', exact: true })
  await downloadButton.focus()
  const downloadEvent = page.waitForEvent('download')
  await page.keyboard.press('Enter')
  const download = await downloadEvent
  expect(download.suggestedFilename()).toBe('prueba.pdf')
  await expect(downloadButton).toBeEnabled()
  await downloadButton.focus()
  await page.keyboard.press('Tab')
  await expect(page.getByRole('button', { name: 'Cerrar visor' })).toBeFocused()
  await page.getByRole('button', { name: 'Cerrar visor' }).press('Enter')
  await expect(page.getByRole('status')).toHaveText('Visor cerrado')
})

test('PDF quarantine error is announced and the viewer remains closable', async ({ page }) => {
  await page.route('**/api/v1/expedientes/**/archivo?**', route => route.fulfill({ status: 423, json: { message: 'Archivo en cuarentena' } }))
  await page.goto('/e2e/visor.html')
  await expect(page.getByRole('alert')).toContainText('cuarentena')
  await page.getByRole('button', { name: 'Cerrar visor' }).press('Enter')
  await expect(page.getByRole('status')).toHaveText('Visor cerrado')
})
