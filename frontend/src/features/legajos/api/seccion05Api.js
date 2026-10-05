import api from '../../../axios'

// tipo: licencias-permisos, periodos-vacacionales o goces-vacacionales. Los registros no se corrigen: se anulan y se registran de nuevo.
export function registrarSeccion05(usuario, tipo, data) {
  return api.post(`/expedientes/${usuario}/seccion-05/${tipo}`, data)
}

// No se elimina: se anula con motivo (sistema.administrar).
export function anularSeccion05(usuario, tipo, id, motivo) {
  return api.post(`/expedientes/${usuario}/seccion-05/${tipo}/${id}/anular`, { motivo_anulacion: motivo })
}
