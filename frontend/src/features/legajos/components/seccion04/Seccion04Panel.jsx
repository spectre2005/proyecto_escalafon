import { useState } from 'react'
import { apiErrorMessage } from '../../../../axios'
import { useLiveQuery } from '../../../../shared/queries/useLiveQuery'
import { usePuede } from '../../../auth/session'
import { useNoAplicables } from '../../api/useNoAplicables'
import { ofrecerTipo } from '../../config/aplicacion'
import { estados03, antivirus03 } from '../../config/seccion03'
import { folios } from '../../config/documentos'
import AbrirLegajo from '../AbrirLegajo'
import EspacioSeccion from '../documentos/EspacioSeccion'
import VistasDocumentales from '../documentos/VistasDocumentales'
import { tituloSeccion } from '../../config/espacioSeccion'
import { vistaDeItem } from '../../config/vistasDocumentales'
import VisorPdf from '../documentos/VisorPdf'
import Documento04Form from './Documento04Form'
import LineaTiempoExperiencia from './LineaTiempoExperiencia'
import { revisarDocumento04 } from '../../api/seccion04Api'
import Revision03Form from '../seccion03/Revision03Form'

const boton = 'rounded-xl border border-teal-200 bg-teal-50 px-3 py-2 text-xs font-semibold text-teal-800 hover:bg-teal-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 disabled:opacity-50'
const fecha = value => value ? value.split('-').reverse().join('/') : 'No registrada'

function Estado({ estado }) {
  const [texto, clase] = estados03[estado] || estados03.por_determinar
  return <span className={`inline-block rounded-full px-2.5 py-1 text-xs font-medium ${clase}`}>{texto}</span>
}

export default function Seccion04Panel({ usuario }) {
  const request = useLiveQuery(`/expedientes/${usuario}/seccion-04`)
  const data = request.data?.data
  const puedeAgregar = usePuede('documento.subir')
  const noAplica = useNoAplicables(usuario)
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
  if (!data) return error || <p role="status" className="p-6 text-sm text-slate-500">Cargando experiencia laboral…</p>
  const resumen = data.resumen
  return <EspacioSeccion seccion={tituloSeccion('04')} subseccion="Documentos y tiempo de experiencia" cifras={`Exigidos aceptados: ${resumen.aceptados} de ${resumen.exigidos} · ${resumen.pendientes} en revisión · ${resumen.observados} observado(s).`} etiquetaResumen="Ver resumen"
    resumen={<div className="space-y-3"><p className="text-slate-500">Experiencia declarada durante la selección, con sus periodos y documentos de respaldo.</p>
    <div className="grid gap-5 rounded-2xl border border-slate-200 bg-white p-5 sm:grid-cols-2">
      <div><p className="text-xs text-slate-500">Documentos exigidos aceptados</p><p className="mt-2 text-2xl font-bold text-slate-800">{resumen.aceptados} de {resumen.exigidos}</p>
        <div role="progressbar" aria-label="Avance de documentos exigidos aceptados" aria-valuenow={resumen.porcentaje} aria-valuemin={0} aria-valuemax={100} className="mt-3 h-2 overflow-hidden rounded-full bg-slate-100"><div className="h-full bg-teal-700" style={{ width: `${resumen.porcentaje}%` }} /></div>
      </div>
      <div className="flex flex-wrap content-center items-center gap-2 text-xs">{data.perfil.tipos_personal.map(tipo => <span key={tipo} className="rounded-full bg-slate-100 px-3 py-1.5">{tipo === 'docente' ? 'Docente' : 'Administrativo'}</span>)}{(data.perfil.puestos.length ? data.perfil.puestos : ['Puesto no registrado']).map(puesto => <span key={puesto} className="max-w-full break-words rounded-full bg-slate-100 px-3 py-1.5">{puesto}</span>)}
        <span className="rounded-full bg-amber-50 px-3 py-1.5 text-amber-900">{resumen.pendientes} en revisión</span><span className="rounded-full bg-rose-50 px-3 py-1.5 text-rose-800">{resumen.observados} observado(s)</span><span className="rounded-full bg-rose-50 px-3 py-1.5 text-rose-800">{resumen.faltantes} faltante(s)</span>
      </div>
    </div>
    </div>}>
    <div className="min-w-0 space-y-5">
    <section aria-label="Resumen de experiencia laboral" className="overflow-hidden rounded-xl border border-slate-200 bg-white">
      <dl className="grid divide-y divide-slate-100 sm:grid-cols-3 sm:divide-x sm:divide-y-0">
        {[['Experiencia total', data.tiempo.total], ['Sector público', data.tiempo.publico], ['Sector privado', data.tiempo.privado]].map(([label, t], index) => <div key={label} className={`px-4 py-3 ${index === 0 ? 'bg-teal-50/70' : ''}`}>
          <dt className="text-xs font-medium text-slate-600">{label}</dt>
          <dd className={`mt-1 text-sm font-semibold tabular-nums ${index === 0 ? 'text-teal-900' : 'text-slate-800'}`}>{t.anios} años · {t.meses} meses · {t.dias} días</dd>
        </div>)}
      </dl>
      <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 border-t border-slate-100 px-4 py-2 text-xs text-slate-500">
        <p>Corte: {fecha(data.tiempo.fecha_corte)} · {data.tiempo.configuracion.incluir_pendientes ? 'Aceptados y en revisión' : 'Solo documentos aceptados'}</p>
        <details className="min-w-0"><summary className="cursor-pointer font-semibold text-teal-800">Detalle del cálculo</summary>
          <dl className="mt-2 space-y-1">{[['Total', data.tiempo.total], ['Público', data.tiempo.publico], ['Privado', data.tiempo.privado]].map(([label, t]) => <div key={label} className="flex flex-wrap gap-x-2"><dt>{label}:</dt><dd>{t.dias_totales} días calendario · {t.anios_decimal.toFixed(2)} años</dd></div>)}</dl>
          <p className="mt-2 max-w-lg leading-relaxed">Mes de {data.tiempo.configuracion.dias_por_mes} días y año de {data.tiempo.configuracion.dias_por_anio} días. Los subtotales por sector pueden sumar más que el total si hay periodos simultáneos.</p>
        </details>
      </div>
    </section>
    {error}
    {!data.legajo && <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><p className="mb-3">Primero abre el legajo para agregar documentos.</p><AbrirLegajo usuario={usuario} /></div>}
    {aviso && <p role="status" className="rounded-xl bg-teal-50 p-4 text-sm text-teal-800">{aviso}</p>}
    {data.items.length === 0 && <p className="rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-600">No hay tipos documentales disponibles para tu perfil. Solicita a administración revisar el catálogo de la Sección 04.</p>}
    {/* Documentos por vista: Pendientes · Con documentos · Opcionales · No corresponde (cada ítem conserva su formulario). */}
    <VistasDocumentales elementos={data.items} clasificar={item => vistaDeItem(item, ofrecerTipo(noAplica, item))} nombreDe={item => `${item.letra}) ${item.nombre}`}>{(lista, vista) => <div className="space-y-4">{[['Documentos de experiencia laboral', 'Certificados, asignación de funciones y documentos que requiera la entidad.']].map(([titulo, descripcion], grupo) => lista.some(item => item.grupo === grupo) && <section key={titulo} aria-label={titulo} className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
      <header className="border-b border-slate-100 p-5"><h3 className="font-semibold text-slate-800">{titulo}</h3><p className="mt-1 text-xs text-slate-500">{descripcion}</p></header>
      {lista.filter(item => item.grupo === grupo).map(item => <details key={item.id} open={vista === 'pendientes' || item.letra === 'a'} className="border-b border-slate-100 last:border-b-0">
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
            {doc.experiencias.map(p => <p key={p.id} className="mt-2 break-words text-xs text-slate-600">{p.cargo} · {p.sector === 'publico' ? 'Público' : 'Privado'} · {fecha(p.fecha_inicio)} – {p.fecha_fin ? fecha(p.fecha_fin) : 'Hasta la fecha'}{p.es_ejercicio_profesional && ' · Ejercicio profesional'}{p.es_docencia_universitaria && ' · Docencia universitaria'}</p>)}
            {doc.funcion_asignada && <p className="mt-2 break-words text-xs text-slate-600">Función asignada: {doc.funcion_asignada}</p>}
            <div className="mt-2 flex flex-wrap gap-x-4 gap-y-2 text-xs text-slate-500"><span>Folios {folios(doc.folio_inicio, doc.folio_fin)}</span><span>{doc.fedateado ? 'Fedateado' : 'Sin fedatear'}</span><span>{doc.fiscalizado ? 'Verificado con la entidad emisora' : 'Sin fiscalización registrada'}</span><span>{doc.archivo ? `PDF · ${antivirus03[doc.archivo.estado_antivirus] || 'Estado antivirus no confirmado'}` : 'Sin archivo'}</span><span>Recepción: {fecha(doc.fecha_recepcion)}</span></div>
            {doc.subsanado && <p className="mt-3 text-xs text-slate-500">Subsanado por otro documento. Se conserva en el historial y no cuenta en el avance.</p>}
            {doc.observaciones.map((obs, indice) => <p key={indice} className="mt-3 break-words rounded-lg bg-rose-50 p-3 text-xs text-rose-800"><strong>Observación:</strong> {obs.descripcion} · Plazo: {fecha(obs.plazo_subsanacion)}</p>)}
            <div className="mt-3 flex flex-wrap gap-2">
              {doc.archivo && doc.archivo.estado_antivirus !== 'infectado' && <button className={boton} onClick={() => setVisor(visor?.id === doc.id ? null : doc)}>Ver PDF</button>}
              {/* Subsanar un documento vigente también si su tipo ya no se ofrece (lo admite el backend); propone sus datos. */}
              {puedeAgregar && data.legajo && item.aplica && !doc.subsanado && doc.estado !== 'anulado' && !formulario && <button id={`subsanar-${doc.id}`} className={boton} onClick={() => { setAviso(''); setFormulario({ item: item.id, subsana: doc.id, origen: `subsanar-${doc.id}` }) }}>Subsanar</button>}
              {puedeRevisar && doc.puede_revisar && doc.estado !== 'aceptado' && <button id={`aceptar-${doc.id}`} className={boton} onClick={() => setFormulario({ item: item.id, documento: doc.id, estado: 'aceptado', origen: `aceptar-${doc.id}` })}>Aceptar</button>}
              {puedeAnular && puedeRevisar && doc.puede_revisar && <button id={`anular-${doc.id}`} className={boton} onClick={() => setFormulario({ item: item.id, documento: doc.id, estado: 'anulado', origen: `anular-${doc.id}` })}>Anular documento</button>}
              {puedeRevisar && doc.puede_revisar && doc.estado !== 'observado' && <button id={`observar-${doc.id}`} className={boton} onClick={() => setFormulario({ item: item.id, documento: doc.id, estado: 'observado', origen: `observar-${doc.id}` })}>Observar</button>}
            </div>
            {puedeRevisar && !doc.puede_revisar && !doc.subsanado && doc.estado !== 'anulado' && <p className="mt-3 text-xs text-slate-500">Otra persona de RR. HH. debe revisar esta carga.</p>}
            {formulario?.documento === doc.id && <div className="mt-4"><Revision03Form revisar={revisarDocumento04} nota="La revisión queda en el historial. Verifica también el periodo y su clasificación. La aceptación no acredita una fiscalización posterior con la entidad emisora." key={`${doc.id}:${formulario.estado}`} usuario={usuario} documento={doc} item={item} estado={formulario.estado} onCancel={() => cerrar()} onSaved={() => cerrar(formulario.estado === 'aceptado' ? 'Documento aceptado. La fiscalización posterior sigue siendo independiente.' : formulario.estado === 'anulado' ? 'Documento anulado. Se conserva su archivo e historial.' : 'Observación registrada. La corrección se presenta con un documento nuevo.')} /></div>}
            {visor?.id === doc.id && <VisorPdf usuario={usuario} documento={doc} titulo={doc.nombre} onClose={() => setVisor(null)} />}
          </li>)}</ul>
          {puedeAgregar && data.legajo && item.aplica && <>
            {formulario?.item === item.id && !formulario.documento ? <Documento04Form usuario={usuario} item={item} datos={data} subsana={formulario.subsana || ''} soloSubsanar={!ofrecerTipo(noAplica, item)} onCancel={() => cerrar()} onSaved={() => cerrar('Documento guardado. Queda en revisión de RR. HH.')} /> : ofrecerTipo(noAplica, item) && <button id={`agregar-${item.id}`} className={boton} onClick={() => { setAviso(''); setFormulario({ item: item.id, origen: `agregar-${item.id}` }) }}>{item.cantidad ? (item.permite_multiples ? 'Agregar otro documento' : 'Agregar documento que subsane al anterior') : 'Agregar documento'}</button>}
          </>}
        </div>
      </details>)}
    </section>)}</div>}</VistasDocumentales>
    <LineaTiempoExperiencia tiempo={data.tiempo} />
    <p className="text-sm font-semibold text-slate-800">Número de folios: {data.numero_folios}</p>
    <details className="rounded-xl border border-slate-200 bg-white p-4 text-xs text-slate-500"><summary className="cursor-pointer font-semibold text-slate-600">Cómo se conservan los documentos</summary><p className="mt-2 leading-relaxed">Los documentos se conservan sin editar ni eliminar. Los folios son correlativos por sección. La aceptación de RR. HH., el fedateo, la fiscalización posterior y el análisis antivirus se muestran por separado.</p></details>
    </div>
  </EspacioSeccion>
}
