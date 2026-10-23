const permitidos = ['nombre', 'emisor', 'nivel', 'pais', 'fecha_emision', 'fecha_obtencion', 'numero_diploma', 'numero_registro']

/** Un resultado OCR propone datos; nunca cambia identidad y requiere confirmar los campos seleccionados. */
export function seleccionInicial(campos, niveles) {
  return Object.fromEntries(permitidos.filter(key => campos[key] && (key !== 'nivel' || Object.hasOwn(niveles, campos[key]))).map(key => [key, true]))
}

export function camposSeleccionados(campos, seleccion, niveles) {
  return Object.fromEntries(permitidos.filter(key => seleccion[key] && campos[key] && (key !== 'nivel' || Object.hasOwn(niveles, campos[key]))).map(key => [key, campos[key]]))
}
