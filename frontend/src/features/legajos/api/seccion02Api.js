import api from '../../../axios'

// tipo: vinculos-laborales, vinculo-adendas, inducciones o periodos-prueba. Con id: corrección (solo registros abiertos).
export function saveSeccion02(usuario, tipo, id, data) {
  const url = `/expedientes/${usuario}/${tipo}`
  return id ? api.patch(`${url}/${id}`, data) : api.post(url, data)
}

// Datos del puesto (perfil docente o administrativo) del vínculo: registra o corrige el mismo registro.
export function saveDatosPuesto(usuario, vinculo, data) {
  return api.put(`/expedientes/${usuario}/vinculos-laborales/${vinculo}/datos-puesto`, data)
}

// tipo: vinculos-laborales, vinculo-adendas, inducciones o periodos-prueba. No se elimina: se anula.
export function anularSeccion02(usuario, tipo, id, motivo) {
  return api.post(`/expedientes/${usuario}/seccion-02/${tipo}/${id}/anular`, { motivo_anulacion: motivo })
}
