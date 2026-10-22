// Campos cortos en mayúsculas (observación del docente, 21/10/2026): lo que se escribe en un <input> de texto del sistema
// institucional queda en mayúsculas aunque se escriba en minúsculas. Fuera: los textarea (textos largos), correos,
// contraseñas (también la visible), búsquedas y los campos marcados con data-sin-mayusculas (dominios, motivos). Solo
// cambia lo que se escribe: lo que llega del servidor o se completa por código se muestra tal cual.
const BUSQUEDA = /^\s*buscar/i
const AUTOCOMPLETADO_EXCLUIDO = /password|email|username|url/i

export const aMayusculas = texto => texto.toLocaleUpperCase('es-PE')

export function convierteAMayusculas(campo) {
  if (campo?.tagName !== 'INPUT' || campo.type !== 'text' || campo.readOnly || campo.disabled) return false
  if (campo.closest('[data-sin-mayusculas], [role="search"]')) return false
  if (AUTOCOMPLETADO_EXCLUIDO.test(campo.getAttribute('autocomplete') || '') || campo.name === 'q') return false
  return !BUSQUEDA.test(campo.getAttribute('aria-label') || '') && !BUSQUEDA.test(campo.closest('label')?.textContent || '')
}

// El valor se escribe con el setter nativo, no con campo.value: así React aún ve el cambio y su onChange recibe el texto
// en mayúsculas. Con acentos por tecla muerta o teclados móviles (composición) se espera al final y se avisa con un input.
export function activarMayusculas(documento = document) {
  const escribir = Object.getOwnPropertyDescriptor(documento.defaultView.HTMLInputElement.prototype, 'value').set
  function convertir(campo) {
    if (!convierteAMayusculas(campo)) return false
    const actual = campo.value
    const nuevo = aMayusculas(actual)
    if (nuevo === actual) return false
    const { selectionStart: inicio, selectionEnd: fin } = campo
    escribir.call(campo, nuevo)
    if (nuevo.length === actual.length && inicio !== null) campo.setSelectionRange(inicio, fin)
    return true
  }
  const alEscribir = event => { if (!event.isComposing) convertir(event.target) }
  const alTerminarComposicion = event => {
    if (convertir(event.target)) event.target.dispatchEvent(new documento.defaultView.Event('input', { bubbles: true }))
  }
  documento.addEventListener('input', alEscribir, true)
  documento.addEventListener('compositionend', alTerminarComposicion, true)
  return () => {
    documento.removeEventListener('input', alEscribir, true)
    documento.removeEventListener('compositionend', alTerminarComposicion, true)
  }
}
