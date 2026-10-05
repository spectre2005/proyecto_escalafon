import { useState } from 'react'
import { saveRegistroPdf } from '../../api/seccion01Api'
import { etiqueta, resultadosDeclaracion, tiposDeclaracion } from '../../config/seccion01'
import Campo, { Formulario, Opciones, PdfRegistrado } from './Campo'
import RegistrosPanel from './RegistrosPanel'
import RegistroPdf from './RegistroPdf'
import { hoy, useBorrador, useGuardar } from './useGuardar'

const fecha = value => value ? value.split('-').reverse().join('/') : 'No registrada'

function DeclaracionForm({ usuario, record, onSaved, onCancel }) {
  const [draft, set] = useBorrador({ tipo: record?.values.tipo || '', anio: record?.values.anio ?? '', fecha: record?.values.fecha || '', resultado: record?.values.resultado || '', vigente_hasta: record?.values.vigente_hasta || '', observacion: record?.values.observacion || '' })
  const [pdf, setPdf] = useState(null)
  const [pdfError, setPdfError] = useState('')
  const { busy, error, errors, guardar } = useGuardar(data => saveRegistroPdf(usuario, 'declaraciones-verificaciones', record?.id, { ...data, anio: data.anio ? Number(data.anio) : null }, pdf), onSaved)
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
  return <Formulario titulo={record ? 'Editar declaración o verificación' : 'Registrar declaración o verificación'} guardar={record ? 'Guardar cambios' : 'Guardar registro'} busy={busy} error={error} onSubmit={() => { if (!pdfError) guardar(draft) }} onCancel={onCancel}
    nota="Puedes adjuntar un PDF de hasta 2 MB. Un PDF ya registrado se conserva y no se reemplaza.">
    <Campo label="Tipo" required error={errors.tipo} className="sm:col-span-2"><select required value={draft.tipo} onChange={set('tipo')}><Opciones lista={tiposDeclaracion} vacio="Selecciona" /></select></Campo>
    <Campo label="Fecha de emisión o consulta" required error={errors.fecha}><input type="date" required max={hoy()} value={draft.fecha} onChange={set('fecha')} /></Campo>
    <Campo label="Resultado" required error={errors.resultado}><select required value={draft.resultado} onChange={set('resultado')}><Opciones lista={resultadosDeclaracion} vacio="Selecciona" /></select></Campo>
    <Campo label="Año al que corresponde" error={errors.anio}><input type="number" min={1950} max={new Date().getFullYear() + 1} placeholder="Ej.: año de la declaración de bienes" value={draft.anio} onChange={set('anio')} /></Campo>
    <Campo label="Vigente hasta" error={errors.vigente_hasta}><input type="date" min={draft.fecha || undefined} value={draft.vigente_hasta} onChange={set('vigente_hasta')} /></Campo>
    <Campo label="Observación" error={errors.observacion} className="sm:col-span-2"><textarea rows={2} maxLength={300} value={draft.observacion} onChange={set('observacion')} /></Campo>
    {record?.values.pdf_nombre
      ? <PdfRegistrado nombre={record.values.pdf_nombre} error={errors.pdf} />
      : <Campo label={'Adjuntar PDF (opcional)'} error={pdfError || errors.pdf} className="col-span-full"><input type="file" accept=".pdf,application/pdf" onChange={seleccionarPdf} /></Campo>}
  </Formulario>
}

export default function DeclaracionesPanel({ usuario }) {
  return <RegistrosPanel
    usuario={usuario}
    tipo="declaraciones-verificaciones"
    titulo="Declaraciones y verificaciones"
    agregar="Registrar declaración"
    vacio="No hay declaraciones ni verificaciones registradas."
    editable
    editarLabel="Editar"
    tituloRegistro={record => etiqueta(tiposDeclaracion, record.values.tipo)}
    subtitulo={record => `${fecha(record.values.fecha)}${record.values.anio ? ` · Año ${record.values.anio}` : ''}`}
    campos={[
      ['Resultado', v => etiqueta(resultadosDeclaracion, v.resultado)],
      ['Vigente hasta', v => v.vigente_hasta ? fecha(v.vigente_hasta) : 'Sin vencimiento registrado'],
      ['Observación', v => v.observacion || 'Sin observación'],
    ]}
    renderForm={props => <DeclaracionForm key={props.record?.id || 'nuevo'} usuario={usuario} {...props} />}
    renderAdjunto={record => <RegistroPdf usuario={usuario} record={record} tipo="declaraciones-verificaciones" />}
  />
}
