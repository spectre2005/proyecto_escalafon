import { useState } from 'react'
import { useLiveQuery } from '../../../../shared/queries/useLiveQuery'
import { QueryStatus } from '../../../../shared/WorkflowControls'
import { usePuede } from '../../../auth/session'
import ListaDocumentos from '../documentos/ListaDocumentos'
import SubirDocumentoForm from '../documentos/SubirDocumentoForm'

export default function Seccion12Panel({ usuario }) {
  const request = useLiveQuery(`/expedientes/${usuario}/seccion-12`)
  const data = request.data?.data
  const permiso = usePuede('documento.subir')
  const [form, setForm] = useState(null)
  const [mensaje, setMensaje] = useState('')
  const editable = permiso && data?.legajo?.estado_legajo === 'activo'
  return <section className="space-y-5 rounded-2xl border border-slate-200 bg-white p-6"><h2 className="text-xl font-semibold">Otros documentos institucionales</h2><p className="text-sm text-slate-600">Documentación complementaria que la institución incorpora al legajo. Estos tipos no constituyen un requisito obligatorio para todo el personal. La revisión se realiza en Revisión documental.</p><QueryStatus request={request} />{mensaje && <p role="status" className="text-teal-800">{mensaje}</p>}{data && <>{!data.tipos.length && <p>No hay tipos documentales disponibles para tu perfil.</p>}{data.tipos.map(tipo => <article key={tipo.id} className="space-y-3 rounded-xl border border-slate-200 p-4"><h3 className="font-semibold">{tipo.nombre}</h3><p className="text-sm text-slate-600">{tipo.descripcion}</p>{editable && tipo.activo && <button type="button" className="rounded-lg border border-teal-300 px-3 py-2 text-sm text-teal-800" onClick={() => setForm({ tipo })}>Incorporar PDF</button>}<ListaDocumentos usuario={usuario} tipo={tipo} onSubsanar={editable ? documento => setForm({ tipo, subsanar: documento }) : undefined} /></article>)}</>}{form && <SubirDocumentoForm usuario={usuario} seccion={12} tipo={form.tipo} titular={data.titular} subsanar={form.subsanar} onCancel={() => setForm(null)} onSaved={() => { setForm(null); setMensaje('Documento incorporado. Registra su recepción y revisión para completar el trámite.'); request.retry() }} />}</section>
}
