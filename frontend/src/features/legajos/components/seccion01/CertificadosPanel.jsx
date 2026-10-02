import { useLiveQuery } from '../../../../shared/queries/useLiveQuery'
import { saveRegistro } from '../../api/seccion01Api'
import { etiqueta, parentescos, personasCertificado } from '../../config/seccion01'
import Campo, { Formulario, Opciones } from './Campo'
import RegistrosPanel from './RegistrosPanel'
import { hoy, useBorrador, useGuardar } from './useGuardar'

const fecha = value => value ? value.split('-').reverse().join('/') : 'No registrada'

function CertificadoForm({ usuario, onSaved, onCancel }) {
  const [draft, set] = useBorrador({ persona: 'servidor', familiar_id: '', numero_conadis: '', fecha_emision: '', vigente_hasta: '' })
  const { busy, error, errors, guardar } = useGuardar(data => saveRegistro(usuario, 'certificados-discapacidad', { ...data, familiar_id: data.persona === 'familiar' ? data.familiar_id : null }), onSaved)
  // Solo familiares vigentes de esta persona (el backend lo vuelve a validar).
  const familiares = useLiveQuery(`/expedientes/${usuario}/familiares?page=1`)
  const opciones = (familiares.data?.data || []).filter(record => !record.values.anulado)
  return <Formulario titulo="Registrar certificado de discapacidad" guardar="Guardar certificado" busy={busy} error={error} onSubmit={() => guardar(draft)} onCancel={onCancel}
    nota="Certificado emitido por el CONADIS. No se puede editar después: si hay un error, se anula y se registra de nuevo.">
    <Campo label="¿De quién es el certificado?" required error={errors.persona}><select required value={draft.persona} onChange={set('persona')}><Opciones lista={personasCertificado} /></select></Campo>
    {draft.persona === 'familiar' && <Campo label="Familiar" required error={errors.familiar_id}><select required value={draft.familiar_id} onChange={set('familiar_id')} disabled={!familiares.data}>
      <option value="">{familiares.data ? (opciones.length ? 'Selecciona' : 'Primero registra al familiar') : 'Cargando familiares…'}</option>
      {opciones.map(record => <option key={record.id} value={record.id}>{record.values.apellidos_nombres} · {etiqueta(parentescos, record.values.parentesco)}</option>)}
    </select></Campo>}
    <Campo label="N.º de certificado CONADIS" required error={errors.numero_conadis}><input required maxLength={30} value={draft.numero_conadis} onChange={set('numero_conadis')} /></Campo>
    <Campo label="Fecha de emisión" error={errors.fecha_emision}><input type="date" max={hoy()} value={draft.fecha_emision} onChange={set('fecha_emision')} /></Campo>
    <Campo label="Vigente hasta" error={errors.vigente_hasta}><input type="date" min={draft.fecha_emision || undefined} value={draft.vigente_hasta} onChange={set('vigente_hasta')} /></Campo>
  </Formulario>
}

export default function CertificadosPanel({ usuario }) {
  return <RegistrosPanel
    usuario={usuario}
    tipo="certificados-discapacidad"
    titulo="Certificados de discapacidad"
    agregar="Registrar certificado"
    vacio="No hay certificados de discapacidad registrados."
    tituloRegistro={record => `Certificado CONADIS ${record.values.numero_conadis}`}
    subtitulo={record => record.values.persona === 'familiar' ? `De un familiar: ${record.values.familiar_id || 'no identificado'}` : 'Del servidor(a)'}
    campos={[
      ['Fecha de emisión', v => fecha(v.fecha_emision)],
      ['Vigente hasta', v => v.vigente_hasta ? fecha(v.vigente_hasta) : 'Sin vencimiento registrado'],
    ]}
    renderForm={props => <CertificadoForm usuario={usuario} {...props} />}
  />
}
