// Conserva el correo íntegro; solo separa la cita para mostrarla desplegada a petición.
export function separarHistorialCorreo(mensaje) {
  const texto = String(mensaje || '').replace(/\r\n?/g, '\n')
  const inicio = /^(?:(?:El|On) [^\n]+(?:\n[^\n]+){0,2}? (?:escribió|wrote):\s*$|(?:El|On) [^\n]+\n(?:escribió|wrote):\s*$|\s*>|\s*-{2,}\s*(?:Mensaje original|Original Message|Forwarded message|Mensaje reenviado)\s*-{2,})/mi.exec(texto)
  if (!inicio) return { respuesta: texto.trim(), historial: '' }
  return { respuesta: texto.slice(0, inicio.index).trim(), historial: texto.slice(inicio.index).trim() }
}
