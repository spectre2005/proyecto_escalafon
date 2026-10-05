import { saveRegistro } from '../../api/seccion01Api'
import { etiqueta, parentescos, personasCertificado, resultadosDeclaracion, tiposDeclaracion } from '../../config/seccion01'
import Campo, { Formulario, Opciones } from './Campo'
import { conDocumento, solo } from './enlace'
import { hoy, useBorrador, useGuardar } from './useGuardar'

export function NotaDocumento({ documentoId }) {
  return documentoId ? <p className="rounded-lg bg-teal-50 p-3 text-xs text-teal-800 sm:col-span-2">El registro quedará enlazado al documento que acabas de subir.</p> : null
}

const nota = 'El PDF se sube como documento del legajo, con sus folios y metadatos. Quien registra queda guardado con tu sesión.'

// Declaración jurada o verificación (declaraciones_verificaciones). tipos: tipos de declaración que se ofrecen.
export function DeclaracionForm({ usuario, record, documentoId, tipos, onSaved, onCancel }) {
  const v = record?.values || {}
  const opciones = solo(tiposDeclaracion, tipos)
  const unico = Object.keys(opciones).length === 1 ? Object.keys(opciones)[0] : ''
  const [draft, set] = useBorrador({ tipo: v.tipo || unico, anio: v.anio ?? '', fecha: v.fecha || '', resultado: v.resultado || '', vigente_hasta: v.vigente_hasta || '', observacion: v.observacion || '' })
  const { busy, error, errors, guardar } = useGuardar(data => saveRegistro(usuario, 'declaraciones-verificaciones', record?.id, conDocumento({ ...data, anio: data.anio ? Number(data.anio) : null }, documentoId)), onSaved)
  return <Formulario titulo={record ? 'Corregir declaración o verificación' : 'Registrar declaración o verificación'} guardar={record ? 'Guardar cambios' : 'Guardar registro'} busy={busy} error={error} onSubmit={() => guardar(draft)} onCancel={onCancel} nota={nota}>
    <NotaDocumento documentoId={documentoId} />
    <Campo label="Tipo" required error={errors.tipo} className="sm:col-span-2"><select required value={draft.tipo} onChange={set('tipo')}><Opciones lista={opciones} actual={draft.tipo} vacio="Selecciona" /></select></Campo>
    <Campo label="Fecha de emisión o consulta" required error={errors.fecha}><input type="date" required max={hoy()} value={draft.fecha} onChange={set('fecha')} /></Campo>
    <Campo label="Resultado" required error={errors.resultado}><select required value={draft.resultado} onChange={set('resultado')}><Opciones lista={resultadosDeclaracion} vacio="Selecciona" /></select></Campo>
    <Campo label="Año al que corresponde" error={errors.anio}><input type="number" min={1950} max={new Date().getFullYear() + 1} placeholder="Ej.: año de la declaración de bienes" value={draft.anio} onChange={set('anio')} /></Campo>
    <Campo label="Vigente hasta" error={errors.vigente_hasta}><input type="date" min={draft.fecha || undefined} value={draft.vigente_hasta} onChange={set('vigente_hasta')} /></Campo>
    <Campo label="Observación" error={errors.observacion} className="sm:col-span-2"><textarea rows={2} maxLength={300} value={draft.observacion} onChange={set('observacion')} /></Campo>
  </Formulario>
}

// Certificado de discapacidad CONADIS (certificados_discapacidad), del servidor o de un familiar vigente.
export function CertificadoForm({ usuario, record, documentoId, familiares, onSaved, onCancel }) {
  const v = record?.values || {}
  const [draft, set] = useBorrador({ persona: v.persona || 'servidor', familiar_id: v.familiar_id || '', numero_conadis: v.numero_conadis || '', fecha_emision: v.fecha_emision || '', vigente_hasta: v.vigente_hasta || '' })
  const { busy, error, errors, guardar } = useGuardar(data => saveRegistro(usuario, 'certificados-discapacidad', record?.id, conDocumento({ ...data, familiar_id: data.persona === 'familiar' ? data.familiar_id : null }, documentoId)), onSaved)
  return <Formulario titulo={record ? 'Corregir certificado de discapacidad' : 'Registrar certificado de discapacidad'} guardar={record ? 'Guardar cambios' : 'Guardar certificado'} busy={busy} error={error} onSubmit={() => guardar(draft)} onCancel={onCancel} nota={nota}>
    <NotaDocumento documentoId={documentoId} />
    <Campo label="¿De quién es el certificado?" required error={errors.persona}><select required value={draft.persona} onChange={set('persona')}><Opciones lista={personasCertificado} /></select></Campo>
    {draft.persona === 'familiar' && <Campo label="Familiar" required error={errors.familiar_id}><select required value={draft.familiar_id} onChange={set('familiar_id')}>
      <option value="">{familiares.length ? 'Selecciona' : 'Primero registra al familiar'}</option>
      {v.familiar_id && !familiares.some(item => item.id === v.familiar_id) && <option value={v.familiar_id}>{record.familiar || 'Familiar registrado'}</option>}
      {familiares.map(item => <option key={item.id} value={item.id}>{item.values.apellidos_nombres} · {etiqueta(parentescos, item.values.parentesco)}</option>)}
    </select></Campo>}
    <Campo label="N.º de certificado CONADIS" required error={errors.numero_conadis}><input required maxLength={30} value={draft.numero_conadis} onChange={set('numero_conadis')} /></Campo>
    <Campo label="Fecha de emisión" error={errors.fecha_emision}><input type="date" max={hoy()} value={draft.fecha_emision} onChange={set('fecha_emision')} /></Campo>
    <Campo label="Vigente hasta" error={errors.vigente_hasta}><input type="date" min={draft.fecha_emision || undefined} value={draft.vigente_hasta} onChange={set('vigente_hasta')} /></Campo>
  </Formulario>
}
