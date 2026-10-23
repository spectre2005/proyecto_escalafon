import { mediosConsulta } from './mediosConsulta.js'

export function datosConsultaIniciales(alcance, documentos) {
  const documento = alcance.length === 1 ? alcance[0] : null
  const pruebas = documentos.filter(d => !alcance.some(a => a.documento_id === d.id) && /oficio|constancia|correo|consulta|comunicación/i.test(d.nombre || ''))
  return { documento_id: documento?.documento_id || '', entidad_consultada: documento?.datos_snapshot.emisor || '', fuente: 'emisor', medio: '', numero_oficio: '', fecha_envio: '', evidencia_documento_id: pruebas.length === 1 ? pruebas[0].id : '' }
}

export function propuestasConsulta(campos, inicio, hoy) {
  const extras = Object.keys(mediosConsulta[campos.medio]?.campos || {})
  const permitidos = ['entidad_consultada', 'fuente', 'medio', 'numero_oficio', 'fecha_envio', ...extras]
  return Object.fromEntries(permitidos.filter(key => campos[key] && (key !== 'fecha_envio' || (campos[key] >= inicio && campos[key] <= hoy))).map(key => [key, campos[key]]))
}
