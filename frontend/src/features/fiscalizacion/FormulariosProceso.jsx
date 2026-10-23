import EnviarCorreoFiscalizacion from './EnviarCorreoFiscalizacion'
import { prepararConsulta } from './mediosConsulta'
import ConsultaEmisorCampos from './ConsultaEmisorCampos'
import { useState } from 'react'
import DocumentoModal from '../legajos/components/documentos/DocumentoModal'
import { Link } from 'react-router-dom'
import { useManualQuery } from '../../shared/queries/useManualQuery'
import { Campo, campo, DocumentosSelect, ErrorConsulta, Formulario, panel } from './componentes'
import { fecha, hoy, nombreDocumento, persona, resultados } from './config'

export default function FormulariosProceso({ accion, proceso, alcance, documentos, onSaved, onCancel }) {
  const [correoNuevo, setCorreoNuevo] = useState(null)
  const [ocupado, setOcupado] = useState(false)
  const [leyendo, setLeyendo] = useState(false)
  const [modalidad, setModalidad] = useState('individual')
  const opciones = useManualQuery(accion.tipo === 'informe' && modalidad === 'delegada' ? '/fiscalizacion/opciones' : null)
  const base = `/fiscalizacion/procesos/${proceso.id}`
  const titulos = { documento: 'Ampliar el alcance', consulta: 'Registrar consulta enviada', respuesta: accion.item?.medio === 'registro_oficial' ? 'Registrar contraste del registro oficial' : 'Registrar respuesta del emisor', evaluar: 'Evaluar documento', informe: 'Finalizar informe de fiscalización', revisar: 'Revisar y resolver informe', derivacion: 'Registrar derivación realizada', cargo: 'Registrar cargo de recepción' }
  const urls = { documento: `${base}/documentos`, consulta: `${base}/consultas`, respuesta: `${base}/consultas/${accion.item?.id}/respuesta`, evaluar: `${base}/documentos/${accion.item?.id}/evaluar`, informe: `${base}/presentar`, revisar: `${base}/revisar`, derivacion: `${base}/derivaciones`, cargo: `${base}/derivaciones/${accion.item?.id}/cargo` }
  if (accion.tipo === 'enviar_correo' || correoNuevo) return <EnviarCorreoFiscalizacion proceso={proceso} alcance={alcance} inicial={correoNuevo || {}} onSaved={onSaved} onCancel={onCancel} />
  return <DocumentoModal titulo={titulos[accion.tipo]} busy={ocupado || leyendo} onCancel={onCancel} compacto><section className={panel} aria-label={titulos[accion.tipo]}>
    <p className="mb-4 text-xs leading-relaxed text-slate-500">Los oficios, respuestas, evidencias e informes se incorporan primero al legajo. <Link className="font-semibold text-teal-700 underline" to={`/legajos/${proceso.usuario_id}`}>Abrir legajo</Link>. Las actuaciones registradas conservan su historial.</p>
    <ErrorConsulta request={opciones} />
    <Formulario key={`${accion.tipo}:${accion.item?.id || ''}`} url={urls[accion.tipo]} preparar={accion.tipo === 'consulta' ? prepararConsulta : undefined} disabled={leyendo} onBusyChange={setOcupado} onSaved={onSaved} titulo={accion.tipo === 'informe' ? modalidad === 'individual' ? 'Confirmar cierre como responsable' : 'Enviar a otro revisor' : accion.tipo === 'revisar' ? 'Registrar decisión' : 'Guardar actuación'}>
      {accion.tipo === 'documento' && <Campo titulo="Documento adicional" amplio><DocumentosSelect documentos={documentos.filter(d => !alcance.some(a => a.documento_id === d.id))} name="documento_id" /></Campo>}
      {accion.tipo === 'consulta' && <ConsultaEmisorCampos proceso={proceso} alcance={alcance} documentos={documentos} onBusy={setLeyendo} onCompose={setCorreoNuevo} />}
      {accion.tipo === 'respuesta' && <>
        {accion.item.medio === 'registro_oficial' && <p className="rounded-xl bg-teal-50 p-3 text-xs text-teal-900 sm:col-span-2">Confirma lo observado en el registro y adjunta su reporte. Este paso registra el contraste; no requiere recibir un correo de la entidad.</p>}
        <Campo titulo={accion.item.medio === 'registro_oficial' ? 'Fecha del reporte o consulta' : 'Fecha de respuesta'}><input required type="date" name="fecha_respuesta" min={accion.item.fecha_envio.slice(0, 10)} max={hoy()} className={campo} /></Campo>
        <Campo titulo={accion.item.medio === 'registro_oficial' ? 'Resultado del contraste' : 'Resultado informado'}><select name="resultado" className={campo}>{Object.entries(resultados).filter(([k]) => k !== 'pendiente').map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Campo>
        <Campo titulo={accion.item.medio === 'registro_oficial' ? 'Reporte del registro incorporado al legajo' : 'Respuesta o reporte incorporado al legajo'} amplio><DocumentosSelect documentos={documentos} excluir={accion.item.documento_id} name="documento_respuesta_id" /></Campo>
        <Campo titulo="Hallazgo, fuente y datos contrastados" amplio><textarea required maxLength={5000} name="descripcion_hallazgo" rows={4} className={campo} /></Campo>
      </>}
      {accion.tipo === 'evaluar' && <>
        <div className="rounded-xl bg-slate-50 p-3 text-sm sm:col-span-2">{nombreDocumento(accion.item.datos_snapshot)}</div>
        <Campo titulo="Conclusión propuesta"><select name="resultado" defaultValue={accion.item.resultado === 'pendiente' ? 'conforme' : accion.item.resultado} className={campo}>{Object.entries(resultados).filter(([k]) => k !== 'pendiente').map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Campo>
        <Campo titulo="Documento que sustenta la evaluación"><DocumentosSelect documentos={documentos} excluir={accion.item.documento_id} /></Campo>
        <Campo titulo="Datos contrastados, alcance y fundamento de la conclusión" amplio><textarea required maxLength={5000} defaultValue={accion.item.conclusion || ''} name="conclusion" rows={5} className={campo} /></Campo>
        <p className="text-xs leading-relaxed text-slate-500 sm:col-span-2">Una huella coincidente solo prueba integridad respecto al archivo de referencia. Una firma válida no confirma por sí sola el contenido. Si no hubo respuesta, explica las actuaciones y sustenta un resultado no concluyente.</p>
      </>}
      {accion.tipo === 'informe' && <>
        <Campo titulo="Informe técnico incorporado al legajo" amplio><DocumentosSelect documentos={documentos} name="informe_documento_id" /></Campo>
        <Campo titulo="Cómo deseas finalizar el informe" amplio><select name="modalidad" className={campo} value={modalidad} onChange={event => setModalidad(event.target.value)}><option value="individual">Cerrar como responsable</option><option value="delegada">Enviar a otro revisor</option></select></Campo>
        {modalidad === 'delegada' ? <>
          <Campo titulo="Revisor autorizado distinto del responsable" amplio><select required name="revisor_id" className={campo}><option value="">Seleccionar revisor</option>{(opciones.data?.revisores || []).filter(r => r.id !== proceso.responsable_id).map(r => <option key={r.id} value={r.id}>{persona(r)}</option>)}</select></Campo>
          {!opciones.loading && !(opciones.data?.revisores || []).some(r => r.id !== proceso.responsable_id) && <p className="rounded-xl bg-amber-50 p-3 text-xs text-amber-900 sm:col-span-2">Puedes asignar un revisor en Accesos y custodia → Equipo. Si el proceso incluye documentos sensibles, el revisor también debe tener acceso a ellos.</p>}
        </> : <p className="rounded-xl bg-teal-50 p-3 text-xs leading-relaxed text-teal-900 sm:col-span-2">Revisarás y cerrarás personalmente el proceso. El historial dejará constancia de que el cierre fue realizado por el responsable. No necesitas otra cuenta.</p>}
        <Campo titulo="Conclusiones y recomendaciones del informe" amplio><textarea required maxLength={15000} name="conclusiones" defaultValue={proceso.conclusiones || ''} rows={6} className={campo} /></Campo>
        {modalidad === 'individual' && <label className="flex items-start gap-3 rounded-xl border border-slate-200 p-4 text-sm text-slate-700 sm:col-span-2"><input required name="confirmacion" value="1" type="checkbox" className="mt-1 accent-teal-700" /><span>Confirmo que revisé las evidencias, las conclusiones y el informe técnico. Asumo la revisión y el cierre como responsable. El proceso cerrado conservará sus conclusiones sin modificaciones.</span></label>}
      </>}
      {accion.tipo === 'revisar' && <>
        <Campo titulo="Decisión"><select name="decision" className={campo}><option value="devolver">Devolver para corregir</option><option value="aprobar">Aprobar cierre</option></select></Campo>
        <Campo titulo="Observaciones y fundamento de la decisión" amplio><textarea required maxLength={5000} name="observaciones" rows={5} className={campo} /></Campo>
        <p className="rounded-xl bg-amber-50 p-3 text-xs leading-relaxed text-amber-900 sm:col-span-2">Al aprobar se conservarán el informe y las evaluaciones, y se actualizará el resultado de fiscalización en el legajo. El proceso cerrado no admite cambios en sus conclusiones.</p>
      </>}
      {accion.tipo === 'derivacion' && <>
        <Campo titulo="Área destinataria"><input required maxLength={150} name="area" placeholder="Asesoría Jurídica, Procuraduría, ST PAD…" className={campo} /></Campo>
        <Campo titulo="Número de oficio o referencia"><input required maxLength={150} name="referencia" className={campo} /></Campo>
        <Campo titulo="Fecha de envío"><input required type="date" name="fecha_envio" min={proceso.fecha_fin?.slice(0, 10)} max={hoy()} defaultValue={hoy()} className={campo} /></Campo>
        <Campo titulo="Documento de derivación"><DocumentosSelect documentos={documentos} name="documento_id" /></Campo>
        <Campo titulo="Motivo y actuación solicitada" amplio><textarea required maxLength={3000} name="motivo" rows={4} className={campo} /></Campo>
      </>}
      {accion.tipo === 'cargo' && <><Campo titulo="Fecha de recepción"><input required type="date" min={accion.item.fecha_envio.slice(0, 10)} max={hoy()} name="fecha_cargo" className={campo} /></Campo><Campo titulo="Cargo incorporado al legajo"><DocumentosSelect documentos={documentos} name="documento_id" /></Campo><p className="text-xs text-slate-500 sm:col-span-2">Envío registrado el {fecha(accion.item.fecha_envio)}.</p></>}
    </Formulario>
  </section></DocumentoModal>
}
