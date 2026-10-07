import { useState } from 'react'
import { apiErrorMessage } from '../../../../axios'
import { Link } from 'react-router-dom'
import { useLiveQuery } from '../../../../shared/queries/useLiveQuery'
import { usePuede } from '../../../auth/session'
import { estados03, antivirus03 } from '../../config/seccion03'
import { folios } from '../../config/documentos'
import AbrirLegajo from '../AbrirLegajo'
import VisorPdf from '../documentos/VisorPdf'
import Documento06Form from './Documento06Form'
import { revisarDocumento06 } from '../../api/seccion06Api'
import Revision03Form from '../seccion03/Revision03Form'

const boton = 'rounded-xl border border-teal-200 bg-teal-50 px-3 py-2 text-xs font-semibold text-teal-800 hover:bg-teal-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 disabled:opacity-50'
const fecha = value => value ? value.split('-').reverse().join('/') : 'No registrada'

function Estado({ estado }) {
  const [texto, clase] = estados03[estado] || estados03.por_determinar
  return <span className={`inline-block rounded-full px-2.5 py-1 text-xs font-medium ${clase}`}>{texto}</span>
}

export default function Seccion06Panel({ usuario }) {
  const request = useLiveQuery(`/expedientes/${usuario}/seccion-06`)
  const data = request.data?.data
  const puedeAgregar = usePuede('documento.subir')
  const puedeAnular = usePuede('sistema.administrar')
  const puedeRevisar = usePuede('documento.revisar')
  const [formulario, setFormulario] = useState(null)
  const [visor, setVisor] = useState(null)
  const [aviso, setAviso] = useState('')
  function cerrar(mensaje = '') {
    const origen = mensaje ? `item-${formulario?.item}` : formulario?.origen
    setFormulario(null)
    if (mensaje) { setAviso(mensaje); request.retry() }
    setTimeout(() => document.getElementById(origen)?.focus(), 0)
  }
  const error = request.error && <p role="alert" className="mb-4 rounded-xl bg-rose-50 p-4 text-sm text-rose-800">{request.error.response?.status === 403 ? 'No tienes permiso para ver esta sección.' : apiErrorMessage(request.error)} <button className="font-semibold underline" onClick={request.retry}>Reintentar</button></p>
  if (!data) return error || <p role="status" className="p-6 text-sm text-slate-500">Cargando compensaciones…</p>
  const resumen = data.resumen
  const atencion = data.items.filter(item => ['falta', 'observado', 'por_determinar'].includes(item.estado))
  return <section aria-label="Compensaciones" className="min-w-0 space-y-5">
    {error}
    <nav aria-label="Ruta de la sección" className="flex flex-wrap gap-1 text-xs text-slate-500"><Link to="/legajos" className="text-teal-700 underline">Legajos</Link><span>› {data.legajo?.numero_legajo || 'Sin legajo abierto'} › Sección 06</span></nav>
    <div><h2 className="text-xl font-semibold text-slate-800">Sección 06 — Compensaciones</h2><p className="mt-2 text-sm text-slate-500">Documentación relacionada a compensaciones económicas y no económicas, pagos de aportes del empleador, entre otros.</p></div>
    {!data.legajo && <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><p className="mb-3">Primero abre el legajo para agregar documentos.</p><AbrirLegajo usuario={usuario} /></div>}
    {aviso && <p role="status" className="rounded-xl bg-teal-50 p-4 text-sm text-teal-800">{aviso}</p>}
    <div className="grid gap-5 rounded-2xl border border-slate-200 bg-white p-5 sm:grid-cols-2">
      <div><p className="text-xs text-slate-500">Documentos vigentes aceptados</p><p className="mt-2 text-2xl font-bold text-slate-800">{resumen.aceptados} de {resumen.registrados}</p>
        {resumen.porcentaje !== null ? <><div role="progressbar" aria-label="Avance de revisión documental" aria-valuenow={resumen.porcentaje} aria-valuemin={0} aria-valuemax={100} className="mt-3 h-2 overflow-hidden rounded-full bg-slate-100"><div className="h-full bg-teal-700" style={{ width: `${resumen.porcentaje}%` }} /></div><p className="mt-2 text-xs text-slate-500">{resumen.porcentaje}% revisado y aceptado.</p></> : <p className="mt-2 text-xs text-slate-500">Sin documentos vigentes para calcular avance.</p>}
        <p className="mt-2 text-xs text-slate-500">El avance refleja los documentos registrados; no determina obligaciones individuales no configuradas.</p>
      </div>
      <div className="flex flex-wrap content-center items-center gap-2 text-xs">{data.perfil.tipos_personal.map(tipo => <span key={tipo} className="rounded-full bg-slate-100 px-3 py-1.5">{tipo === 'docente' ? 'Docente' : 'Administrativo'}</span>)}{(data.perfil.puestos.length ? data.perfil.puestos : ['Puesto no registrado']).map(puesto => <span key={puesto} className="max-w-full break-words rounded-full bg-slate-100 px-3 py-1.5">{puesto}</span>)}
        <span className="rounded-full bg-amber-50 px-3 py-1.5 text-amber-900">{resumen.pendientes} en revisión</span><span className="rounded-full bg-rose-50 px-3 py-1.5 text-rose-800">{resumen.observados} observado(s)</span><span className="rounded-full bg-rose-50 px-3 py-1.5 text-rose-800">{resumen.faltantes} faltante(s)</span>
      </div>
    </div>
    {atencion.length > 0 && <div role="status" className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><strong>Necesita atención:</strong><ul className="mt-2 list-disc space-y-1 pl-5">{atencion.map(item => <li key={item.id}>{item.nombre}: {estados03[item.estado][0].toLowerCase()}.</li>)}</ul></div>}
    {data.items.length === 0 && <p className="rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-600">No hay tipos documentales disponibles para tu perfil. Solicita a administración revisar el catálogo de la Sección 06.</p>}
    {[['Documentos de compensaciones', 'Reconocimiento de tiempo de servicios, pagos, retenciones, liquidaciones y otros documentos institucionales.']].map(([titulo, descripcion], grupo) => <section key={titulo} aria-label={titulo} className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
      <header className="border-b border-slate-100 p-5"><h3 className="font-semibold text-slate-800">{titulo}</h3><p className="mt-1 text-xs text-slate-500">{descripcion}</p></header>
      {data.items.filter(item => item.grupo === grupo).map(item => <details key={item.id} open={item.letra === 'a'} className="border-b border-slate-100 last:border-b-0">
        <summary id={`item-${item.id}`} className="grid cursor-pointer grid-cols-[28px_minmax(0,1fr)] items-start gap-3 p-4 focus-visible:outline-2 focus-visible:outline-teal-700 sm:grid-cols-[28px_minmax(0,1fr)_auto]">
          <span className="grid size-7 place-items-center rounded-lg bg-teal-50 text-sm font-bold text-teal-800">{item.letra}</span>
          <span className="min-w-0"><span className="block break-words text-sm font-semibold text-slate-800">{item.nombre}</span><span className="mt-1 block text-xs text-slate-500">{item.motivo}{item.requiere_fedateo && ' Requiere fedateo.'}</span><span className="mt-1 block text-xs text-slate-500">{item.cantidad} documento(s) vigente(s)</span></span>
          <span className="col-start-2 sm:col-start-auto"><Estado estado={item.estado} /></span>
        </summary>
        <div className="space-y-4 px-4 pb-5 sm:pl-14">
          {item.documentos.length === 0 && <p className="text-sm text-slate-500">{item.estado === 'falta' ? 'Todavía no se registró este documento. Agrégalo para que RR. HH. lo revise.' : item.estado === 'no_aplica' ? 'Este documento no se exige para el puesto registrado.' : 'No hay documentos registrados. Puedes agregar uno si corresponde.'}</p>}
          <ul className="space-y-3">{item.documentos.map(doc => <li key={doc.id} className={`min-w-0 rounded-xl border border-slate-200 p-4 ${doc.subsanado ? 'bg-slate-50' : ''}`}>
            <div className="flex flex-wrap items-start justify-between gap-2"><h4 className="min-w-0 break-words text-sm font-semibold text-slate-800">{doc.nombre}</h4><Estado estado={doc.estado} /></div>
            <p className="mt-2 break-words text-xs text-slate-600">{doc.emisor} · Emitido el {fecha(doc.fecha_emision)}{doc.num_resolucion && ` · Registro ${doc.num_resolucion}`}</p>
            {doc.tiempos_reconocidos.map(p => <p key={p.id} className="mt-2 break-words text-xs text-slate-600">Tiempo reconocido: {p.anios} años, {p.meses} meses y {p.dias} días · Resolución {p.numero_resolucion} · {fecha(p.fecha_resolucion)}{p.anulado && ' · Registro anulado'}</p>)}
            {doc.compensaciones.map(p => <p key={p.id} className="mt-2 break-words text-xs text-slate-600">{p.tipo === 'economica' ? 'Económica' : 'No económica'} · {p.concepto} · {fecha(p.fecha)}{p.numero_documento && ` · Documento ${p.numero_documento}`}{p.anulado && ' · Registro anulado'}</p>)}
            <p className="mt-2 break-words text-xs text-slate-500">Incorporado el {fecha(doc.fecha_incorporacion)} · Titular: {doc.titular_nombre || 'No registrado'}</p>
            <div className="mt-2 flex flex-wrap gap-x-4 gap-y-2 text-xs text-slate-500"><span>Folios {folios(doc.folio_inicio, doc.folio_fin)}</span><span>{doc.fedateado ? 'Fedateado' : 'Sin fedatear'}</span><span>{doc.fiscalizado ? 'Verificado con la entidad emisora' : 'Sin fiscalización registrada'}</span><span>{doc.archivo ? `PDF · ${antivirus03[doc.archivo.estado_antivirus] || 'Estado antivirus no confirmado'}` : 'Sin archivo'}</span><span>Recepción: {fecha(doc.fecha_recepcion)}</span></div>
            {doc.subsanado && <p className="mt-3 text-xs text-slate-500">Subsanado por otro documento. Se conserva en el historial y no cuenta en el avance.</p>}
            {doc.observaciones.map((obs, indice) => <p key={indice} className="mt-3 break-words rounded-lg bg-rose-50 p-3 text-xs text-rose-800"><strong>Observación:</strong> {obs.descripcion} · Plazo: {fecha(obs.plazo_subsanacion)}</p>)}
            <div className="mt-3 flex flex-wrap gap-2">
              {doc.archivo && doc.archivo.estado_antivirus !== 'infectado' && <button className={boton} onClick={() => setVisor(visor?.id === doc.id ? null : doc)}>Ver PDF</button>}
              {puedeRevisar && doc.puede_revisar && doc.estado !== 'aceptado' && <button id={`aceptar-${doc.id}`} className={boton} onClick={() => setFormulario({ item: item.id, documento: doc.id, estado: 'aceptado', origen: `aceptar-${doc.id}` })}>Aceptar</button>}
              {puedeAnular && puedeRevisar && doc.puede_revisar && <button id={`anular-${doc.id}`} className={boton} onClick={() => setFormulario({ item: item.id, documento: doc.id, estado: 'anulado', origen: `anular-${doc.id}` })}>Anular documento</button>}
              {puedeRevisar && doc.puede_revisar && doc.estado !== 'observado' && <button id={`observar-${doc.id}`} className={boton} onClick={() => setFormulario({ item: item.id, documento: doc.id, estado: 'observado', origen: `observar-${doc.id}` })}>Observar</button>}
            </div>
            {puedeRevisar && !doc.puede_revisar && !doc.subsanado && doc.estado !== 'anulado' && <p className="mt-3 text-xs text-slate-500">Otra persona de RR. HH. debe revisar esta carga.</p>}
            {formulario?.documento === doc.id && <div className="mt-4"><Revision03Form fechaActual={data.fecha_actual} revisar={revisarDocumento06} nota="La revisión queda en el historial. Verifica que los datos transcritos correspondan al documento. La aceptación no acredita una fiscalización posterior con la entidad emisora." key={`${doc.id}:${formulario.estado}`} usuario={usuario} documento={doc} item={item} estado={formulario.estado} onCancel={() => cerrar()} onSaved={() => cerrar(formulario.estado === 'aceptado' ? 'Documento aceptado. La fiscalización posterior sigue siendo independiente.' : formulario.estado === 'anulado' ? 'Documento anulado. Se conserva su archivo e historial.' : 'Observación registrada. La corrección se presenta con un documento nuevo.')} /></div>}
            {visor?.id === doc.id && <VisorPdf usuario={usuario} documento={doc} titulo={doc.nombre} onClose={() => setVisor(null)} />}
          </li>)}</ul>
          {puedeAgregar && data.legajo && item.aplica && <>
            {formulario?.item === item.id && !formulario.documento ? <Documento06Form usuario={usuario} item={item} datos={data} onCancel={() => cerrar()} onSaved={() => cerrar('Documento guardado. Queda en revisión de RR. HH.')} /> : <button data-recorrido-tipo={item.id} id={`agregar-${item.id}`} className={boton} onClick={() => { setAviso(''); setFormulario({ item: item.id, origen: `agregar-${item.id}` }) }}>{item.cantidad ? (item.permite_multiples ? 'Agregar otro documento' : 'Agregar documento que subsane al anterior') : 'Agregar documento'}</button>}
          </>}
        </div>
      </details>)}
    </section>)}
    <p className="text-sm font-semibold text-slate-800">Número de folios: {data.numero_folios}</p>
    <p className="rounded-xl border border-slate-200 bg-white p-4 text-xs leading-relaxed text-slate-500">Los documentos se conservan sin editar ni eliminar. Los folios son correlativos por sección. La aceptación de RR. HH., el fedateo, la fiscalización posterior y el análisis antivirus se muestran por separado.</p>
  </section>
}
