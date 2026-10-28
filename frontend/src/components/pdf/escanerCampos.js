export const camposEscaner = ['nombre', 'emisor', 'fecha_emision', 'num_resolucion', 'numero_documento', 'fecha_caducidad', 'fecha_inicio', 'fecha_fin', 'fecha_vencimiento', 'vigente_hasta', 'numero_registro', 'numero_colegiatura', 'horas', 'cargo', 'funcion_asignada', 'anios', 'meses', 'dias', 'concepto', 'fecha', 'entidad', 'motivo']

export function datosAplicables(campos, inicial, actual, permitidos) {
  return Object.fromEntries(Object.entries(campos || {}).filter(([key, dato]) =>
    camposEscaner.includes(key) && permitidos.includes(key) && inicial[key] === actual[key]
    && typeof dato?.valor === 'string' && dato.valor.trim() && typeof dato.evidencia === 'string',
  ).map(([key, dato]) => [key, dato.valor]))
}
