export const CAMPOS_ESCANER_INGRESO = {
  numero_documento_vinculo: { label: 'N.º de resolución o contrato', max: 60 },
  proceso_seleccion: { label: 'Proceso de selección', max: 100 },
  fecha_resultado_seleccion: { label: 'Fecha del resultado de selección', max: 10, fecha: true },
}

export function separarPropuestasIngreso(campos) {
  const vinculo = {}
  const documento = {}
  for (const item of campos || []) {
    if (!CAMPOS_ESCANER_INGRESO[item.clave] || typeof item.valor !== 'string' || !item.valor.trim()) continue
    const destino = ['emisor', 'fecha_emision'].includes(item.clave) ? documento : vinculo
    destino[item.clave] = item.valor.trim()
  }
  return { vinculo, documento }
}
