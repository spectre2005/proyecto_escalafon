import { useState } from 'react'
import { useLiveQuery } from '../../../../shared/queries/useLiveQuery'
import { saveCertificado } from '../../api/seccion01Api'
import { etiqueta, parentescos, personasCertificado } from '../../config/seccion01'
import Campo, { Formulario, Opciones } from './Campo'
import RegistrosPanel from './RegistrosPanel'
import RegistroPdf from './RegistroPdf'
import { hoy, useBorrador, useGuardar } from './useGuardar'

const fecha = value => value ? value.split('-').reverse().join('/') : 'No registrada'

function CertificadoForm({ usuario, record, onSaved, onCancel }) {
  const [draft, set] = useBorrador({ persona: record?.values.persona || 'servidor', familiar_id: record?.familiar_id || '', numero_conadis: record?.values.numero_conadis || '', fecha_emision: record?.values.fecha_emision || '', vigente_hasta: record?.values.vigente_hasta || '' })
  const [pdf, setPdf] = useState(null)
  const [pdfError, setPdfError] = useState('')
  const { busy, error, errors, guardar } = useGuardar(data => saveCertificado(usuario, record?.id, { ...data, familiar_id: data.persona === 'familiar' ? data.familiar_id : null }, pdf), onSaved)
  // Solo familiares vigentes de esta persona (el backend lo vuelve a validar).
  const familiares = useLiveQuery(`/expedientes/${usuario}/familiares?page=1`)
  const opciones = (familiares.data?.data || []).filter(record => !record.values.anulado)
  function seleccionarPdf(event) {
    const file = event.target.files?.[0]
    setPdf(null)
    setPdfError('')
    if (!file) return
    if (!file.name.toLowerCase().endsWith('.pdf') || file.size > 2 * 1024 * 1024) {
      setPdfError('Selecciona un archivo PDF de hasta 2 MB.')
      event.target.value = ''
      return
    }
    setPdf(file)
  }
  return <Formulario titulo={record ? 'Editar certificado de discapacidad' : 'Registrar certificado de discapacidad'} guardar={record ? 'Guardar cambios' : 'Guardar certificado'} busy={busy} error={error} onSubmit={() => { if (!pdfError) guardar(draft) }} onCancel={onCancel}
    nota="Puedes adjuntar un PDF de hasta 2 MB. Al editar, el archivo actual se conserva si no seleccionas uno nuevo.">
    <Campo label="¿De quién es el certificado?" required error={errors.persona}><select required value={draft.persona} onChange={set('persona')}><Opciones lista={personasCertificado} /></select></Campo>
    {draft.persona === 'familiar' && <Campo label="Familiar" required error={errors.familiar_id}><select required value={draft.familiar_id} onChange={set('familiar_id')} disabled={!familiares.data}>
      <option value="">{familiares.data ? (opciones.length ? 'Selecciona' : 'Primero registra al familiar') : 'Cargando familiares…'}</option>
      {record?.familiar_id && !opciones.some(item => item.id === record.familiar_id) && <option value={record.familiar_id}>{record.values.familiar_id || 'Familiar registrado'}</option>}
      {opciones.map(record => <option key={record.id} value={record.id}>{record.values.apellidos_nombres} · {etiqueta(parentescos, record.values.parentesco)}</option>)}
    </select></Campo>}
    <Campo label="N.º de certificado CONADIS" required error={errors.numero_conadis}><input required maxLength={30} value={draft.numero_conadis} onChange={set('numero_conadis')} /></Campo>
    <Campo label="Fecha de emisión" error={errors.fecha_emision}><input type="date" max={hoy()} value={draft.fecha_emision} onChange={set('fecha_emision')} /></Campo>
    <Campo label="Vigente hasta" error={errors.vigente_hasta}><input type="date" min={draft.fecha_emision || undefined} value={draft.vigente_hasta} onChange={set('vigente_hasta')} /></Campo>
    <Campo label={record?.values.pdf_nombre ? 'Reemplazar PDF (opcional)' : 'Adjuntar certificado en PDF (opcional)'} error={pdfError || errors.pdf} className="col-span-full"><input type="file" accept=".pdf,application/pdf" onChange={seleccionarPdf} /></Campo>
    {record?.values.pdf_nombre && <p className="col-span-full break-words text-xs text-slate-600">PDF actual: {record.values.pdf_nombre}</p>}
  </Formulario>
}

export default function CertificadosPanel({ usuario }) {
  return <RegistrosPanel
    usuario={usuario}
    tipo="certificados-discapacidad"
    titulo="Certificados de discapacidad"
    agregar="Registrar certificado"
    vacio="No hay certificados de discapacidad registrados."
    editable
    editarLabel="Editar"
    tituloRegistro={record => `Certificado CONADIS ${record.values.numero_conadis}`}
    subtitulo={record => record.values.persona === 'familiar' ? `De un familiar: ${record.values.familiar_id || 'no identificado'}` : 'Del servidor(a)'}
    campos={[
      ['Fecha de emisión', v => fecha(v.fecha_emision)],
      ['Vigente hasta', v => v.vigente_hasta ? fecha(v.vigente_hasta) : 'Sin vencimiento registrado'],
    ]}
    renderForm={props => <CertificadoForm key={props.record?.id || 'nuevo'} usuario={usuario} {...props} />}
    renderAdjunto={record => <RegistroPdf usuario={usuario} record={record} tipo="certificados-discapacidad" />}
  />
}
