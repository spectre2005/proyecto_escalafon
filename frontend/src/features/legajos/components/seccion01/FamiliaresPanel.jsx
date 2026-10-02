import { etiqueta, parentescos, tiposDocIdentidad } from '../../config/seccion01'
import FamiliarForm from './FamiliarForm'
import RegistrosPanel from './RegistrosPanel'

const fecha = value => value ? value.split('-').reverse().join('/') : 'No registrada'

export default function FamiliaresPanel({ usuario }) {
  return <RegistrosPanel
    usuario={usuario}
    tipo="familiares"
    titulo="Familiares y parentesco"
    agregar="Agregar familiar"
    vacio="No hay familiares registrados."
    editable
    tituloRegistro={record => record.values.apellidos_nombres}
    subtitulo={record => etiqueta(parentescos, record.values.parentesco)}
    campos={[
      ['Documento', v => v.numero_doc_identidad ? `${etiqueta(tiposDocIdentidad, v.tipo_doc_identidad)} ${v.numero_doc_identidad}` : 'No registrado'],
      ['Fecha de nacimiento', v => fecha(v.fecha_nacimiento)],
      ['Derechohabiente', v => v.es_derechohabiente ? 'Sí' : 'No'],
    ]}
    renderForm={props => <FamiliarForm key={props.record?.id || 'nuevo'} usuario={usuario} {...props} />}
  />
}
