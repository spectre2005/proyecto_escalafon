export function advanceOnEnter(event) {
  if (event.key !== 'Enter' || event.nativeEvent?.isComposing) return
  const field = event.target
  if (!field.matches('input, select') || field.matches('input[type="submit"], input[type="button"]')) return
  event.preventDefault()
  const form = event.currentTarget
  // Las listas desplegables propias (botón con role="combobox") también son campos: Enter no se las salta.
  const fields = Array.from(form.querySelectorAll('input, select, textarea, [role="combobox"]')).filter(element =>
    !element.matches(':disabled, [readonly], [type="hidden"]') && element.getClientRects().length > 0,
  )
  const index = fields.indexOf(field)
  const next = index >= 0 ? fields[index + 1] : fields.find(element => field.compareDocumentPosition(element) & 4)
  const destination = next || form.querySelector('button[type="submit"]:not(:disabled)')
  destination?.focus()
}
