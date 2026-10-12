import Icon from '../../../../components/ui/Icon'
import { useState } from 'react'
import { useLiveQuery } from '../../../../shared/queries/useLiveQuery'
import { usePuede } from '../../../auth/session'
import { anularSeccion02 } from '../../api/seccion02Api'
import { useNoAplicables } from '../../api/useNoAplicables'
import { mostrarTipo, ofrecerTipo } from '../../config/aplicacion'
import { camposDatosPuesto, coloresEstadoDocumento, estadosDocumento, estadosSubseccion, estadosVencimiento, etiquetaVinculo, formulariosDatos, regimenesLaborales, resultadosPeriodo, subseccionesSeccion02, tiposInduccion, tiposPersonal } from '../../config/seccion02'
import { etiqueta } from '../../config/seccion01'
import { folios } from '../../config/documentos'
import ListaDocumentos from '../documentos/ListaDocumentos'
import SubirDocumentoForm from '../documentos/SubirDocumentoForm'
import { apiErrorMessage } from '../../../../axios'
import AbrirLegajo from '../AbrirLegajo'
import { AnularForm } from '../seccion01/RegistrosPanel'
import { AdendaForm, DatosPuestoForm, InduccionForm, PeriodoForm, SeleccionForm, VinculoForm } from './Formularios'
import Registros, { Bloqueo } from './Registros'
import { textoBandera } from '../../../../shared/banderas'

const fecha = value => value ? value.split('-').reverse().join('/') : 'No registrada'
const texto = value => value === null || value === undefined || value === '' ? 'No registrado' : typeof value === 'boolean' ? (value ? 'Sí' : 'No') : String(value).replaceAll('_', ' ')
const boton = 'rounded-xl bg-teal-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50'
const enlace = 'text-sm font-semibold text-teal-700 disabled:opacity-50'
const categoriaDe = Object.fromEntries(Object.entries(subseccionesSeccion02).map(([key, item]) => [item.codigo, key]))

const exigencia = {
  true: ['Se exige', 'bg-teal-50 text-teal-800'],
  false: ['No se exige', 'bg-slate-100 text-slate-600'],
  null: ['Por determinar', 'bg-amber-50 text-amber-800'],
}

function Aviso({ tono = 'amber', children, accion }) {
  const clases = { amber: 'border-amber-200 bg-amber-50 text-amber-900', rose: 'border-rose-200 bg-rose-50 text-rose-800', slate: 'border-slate-200 bg-slate-50 text-slate-600' }[tono]
  return <div role="note" className={`mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4 text-sm ${clases}`}><div>{children}</div>{accion}</div>
}

function Etiqueta({ children, clase }) {
  return <span className={`rounded-full px-2.5 py-1 text-[11px] font-medium ${clase}`}>{children}</span>
}

// Resumen de la sección: pendientes de los tipos que aplican, exigidos a la persona y estado de cada subsección.
// Cargados y recepcionados (documentos reales) van por separado: la fórmula de completitud la define el cliente.
function Resumen({ data, onNavigate }) {
  const r = data.resumen
  return <section aria-label="Resumen de la sección 02" className="mb-8 rounded-2xl border border-teal-100 bg-white p-5">
    <div className="flex flex-wrap gap-x-10 gap-y-4">
      <div><p className="text-xs text-slate-500">Tipos de documento pendientes</p><p className="mt-1 text-2xl font-bold text-slate-800">{r.pendientes} <span className="text-sm font-normal text-slate-500">de {r.tipos}</span></p></div>
      <div><p className="text-xs text-slate-500">Exigidos con documento cargado</p><p className="mt-1 text-2xl font-bold text-slate-800">{r.exigidos_cargados} <span className="text-sm font-normal text-slate-500">de {r.exigidos.length}</span></p></div>
      <div><p className="text-xs text-slate-500">Exigidos con recepción registrada</p><p className="mt-1 text-2xl font-bold text-slate-800">{r.exigidos_recepcionados} <span className="text-sm font-normal text-slate-500">de {r.exigidos.length}</span></p></div>
    </div>
    <p className="mt-3 text-xs text-slate-500">{data.tiene_periodo_prueba === false ? 'La subsección 2.4 no aplica y no se cuenta. ' : data.tiene_periodo_prueba === null ? 'Falta definir si tiene período de prueba: los tipos de 2.4 quedan por determinar. ' : ''}Cargados y recepcionados se muestran por separado: el criterio de completitud del legajo está pendiente de definir. Sin recepción un documento no cuenta como aprobado.{r.por_determinar ? ` ${r.por_determinar} tipo(s) quedan por determinar (falta definir una condición del puesto, o el régimen laboral registrado no es carrera, plazo determinado ni designado); no se cuentan. Revisa el motivo de cada tipo.` : ''}</p>
    <div className="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">{data.subsecciones.map(sub => {
      const [nombre, clase] = estadosSubseccion[sub.estado]
      return <button key={sub.codigo} onClick={() => onNavigate(categoriaDe[sub.codigo])} className={`rounded-xl border border-slate-200 p-3 text-left hover:bg-slate-50 ${sub.aplica ? '' : 'opacity-60'}`}>
        <span className="block text-xs font-semibold text-slate-700">{subseccionesSeccion02[categoriaDe[sub.codigo]]?.titulo || sub.nombre}</span>
        <span className="mt-2 flex flex-wrap items-center gap-2"><Etiqueta clase={clase}>{nombre}</Etiqueta>{sub.aplica && sub.exigidos > 0 && <span className="text-[11px] text-slate-500">{sub.exigidos_cargados}/{sub.exigidos} cargados · {sub.exigidos_recepcionados} recepcionados</span>}</span>
      </button>
    })}</div>
    {r.exigidos.length > 0 && <details className="mt-4"><summary className="cursor-pointer text-xs font-semibold text-teal-700">Documentos que se exigen a esta persona ({r.exigidos.length})</summary>
      <ul className="mt-3 grid gap-1 text-xs text-slate-600 sm:grid-cols-2">{r.exigidos.map(tipo => <li key={tipo.literal + tipo.nombre}>{tipo.literal}) {tipo.nombre} — {tipo.recepcionado ? 'recepcionado' : tipo.cargado ? 'cargado' : 'pendiente'}</li>)}</ul>
    </details>}
  </section>
}

// Registros existentes que un documento puede acreditar, según el formulario de datos de su tipo (sin documento aún).
function enlacesDe(formulario, data) {
  const sinDocumento = lista => lista.filter(record => !record.values.documento_id)
  if (formulario === 'vinculos_laborales') return data.vinculos.map(v => ({ id: v.id, etiqueta: etiquetaVinculo(v) }))
  if (formulario === 'vinculo_adendas') return data.vinculos.flatMap(v => sinDocumento(v.adendas).map(a => ({ id: a.id, etiqueta: `Adenda ${a.values.numero || 'sin número'} · ${fecha(a.values.fecha_inicio)} – ${fecha(a.values.fecha_fin)} · ${v.values.nombre_puesto}` })))
  if (formulario === 'inducciones') return sinDocumento(data.inducciones).map(i => ({ id: i.id, etiqueta: `Inducción ${etiqueta(tiposInduccion, i.values.tipo).toLowerCase()} · ${fecha(i.values.fecha)}` }))
  if (formulario === 'periodos_prueba') return sinDocumento(data.periodos_prueba).map(p => ({ id: p.id, etiqueta: `Período de prueba ${fecha(p.values.fecha_inicio)} – ${fecha(p.values.fecha_fin)}` }))
  return null
}

// Registro de datos que acredita un documento (el enlace lo guarda el backend al subirlo o al registrar el formulario).
function enlazadoA(formulario, documento, data) {
  if (formulario === 'vinculos_laborales') {
    const vinculo = data.vinculos.find(v => v.id === documento.values.vinculo_laboral_id)
    return vinculo ? `Vínculo: ${etiquetaVinculo(vinculo)}` : 'Sin vínculo enlazado'
  }
  if (formulario === 'vinculo_adendas') {
    const adenda = data.vinculos.flatMap(v => v.adendas).find(a => a.values.documento_id === documento.id)
    return adenda ? `Adenda ${adenda.values.numero || 'sin número'} · ${fecha(adenda.values.fecha_inicio)} – ${fecha(adenda.values.fecha_fin)}` : 'Sin adenda enlazada'
  }
  if (formulario === 'inducciones') {
    const inducciones = data.inducciones.filter(i => i.values.documento_id === documento.id)
    return inducciones.length ? inducciones.map(i => `Inducción ${etiqueta(tiposInduccion, i.values.tipo).toLowerCase()} · ${fecha(i.values.fecha)}`).join('; ') : 'Sin inducción enlazada'
  }
  if (formulario === 'periodos_prueba') {
    const periodo = data.periodos_prueba.find(p => p.values.documento_id === documento.id)
    return periodo ? `Período de prueba ${fecha(periodo.values.fecha_inicio)} – ${fecha(periodo.values.fecha_fin)} · ${etiqueta(resultadosPeriodo, periodo.values.resultado).toLowerCase()}` : 'Sin período enlazado'
  }
  return null
}

// Formulario de datos que se abre al subir un documento cuyo tipo lo tiene, enlazado a ese documento.
function FormularioEnlazado({ usuario, data, formulario, documentoId, onDone }) {
  const abiertos = data.vinculos.filter(v => !v.cerrado)
  const props = { usuario, documentoId, onSaved: () => onDone(true), onCancel: () => onDone(false) }
  if (formulario === 'vinculos_laborales') return <VinculoForm {...props} />
  if (!abiertos.length) return <Aviso accion={<button onClick={() => onDone(false)} className={enlace}>Entendido</button>}>El documento quedó cargado, pero no hay un vínculo laboral abierto para registrar sus datos. Registra primero el vínculo en 2.2.</Aviso>
  if (formulario === 'vinculo_adendas') return <AdendaForm vinculos={abiertos} {...props} />
  if (formulario === 'inducciones') return <InduccionForm vinculos={abiertos} {...props} />
  if (formulario === 'periodos_prueba') return <PeriodoForm vinculos={abiertos} {...props} />
  return null
}

// Tipos de documento de la subsección: si se exigen (aplica_* y requiere_*), estado y metadatos del último documento,
// y la carga del PDF con sus metadatos (documento.subir). Un documento no se elimina ni se reemplaza: se subsana.
function Documentos({ usuario, data, subseccion, sinLegajo, aplica = true }) {
  const puedeSubir = usePuede('documento.subir')
  const noAplica = useNoAplicables(usuario)
  const [subiendo, setSubiendo] = useState(null)
  const [enlazando, setEnlazando] = useState(null)
  const [aviso, setAviso] = useState('')
  if (!subseccion) return <Aviso>El catálogo de documentos de esta subsección no está cargado en la base de datos (Seccion02CatalogoSeeder).</Aviso>
  const ocupado = !!subiendo || !!enlazando
  function subido(tipo, documento) {
    setSubiendo(null)
    const abre = documento.formulario_datos && !documento.enlazado
    setAviso(`Documento cargado (folios ${folios(documento.folio_inicio, documento.folio_fin)}).${abre ? ` Completa los datos de ${(formulariosDatos[documento.formulario_datos] || documento.formulario_datos).toLowerCase()} que acredita.` : ''}`)
    if (abre) setEnlazando({ tipoId: tipo.id, formulario: documento.formulario_datos, documentoId: documento.id })
  }
  function enlazado(guardado) {
    setEnlazando(null)
    setAviso(guardado ? 'Datos guardados y enlazados al documento.' : 'El documento quedó cargado sin registro de datos enlazado.')
  }
  return <section aria-label="Documentos de la subsección" className={aplica ? '' : 'opacity-60'}>
    <h3 className="mb-1 text-base font-semibold text-slate-800">Documentos</h3>
    <p className="mb-4 text-xs text-slate-500">Cada documento se sube en PDF con sus metadatos. Los folios son correlativos en toda la sección.{sinLegajo && puedeSubir ? ` ${sinLegajo}` : ''}</p>
    {aviso && <p role="status" className="mb-4 rounded-lg bg-teal-50 p-3 text-sm text-teal-800">{aviso}</p>}
    <ul className="divide-y divide-slate-100 overflow-hidden rounded-2xl border border-slate-200 bg-white">{subseccion.tipos.filter(tipo => mostrarTipo(noAplica, tipo)).map(tipo => {
      const [exigeTexto, exigeClase] = exigencia[String(aplica ? tipo.exigido : false)]
      const d = tipo.documento
      return <li key={tipo.id} className="p-4">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0 flex-1">
            <p className="text-sm font-medium text-slate-800">{tipo.literal && <span className="mr-1 text-slate-500">{tipo.literal})</span>}{tipo.nombre}</p>
            <p className={`mt-1 text-xs ${aplica && tipo.exigido === null ? 'text-amber-800' : 'text-slate-500'}`}>{aplica ? tipo.motivo : 'No aplica a esta persona.'}{tipo.permite_multiples ? ' Admite varios documentos.' : ''}{tipo.formulario_datos ? ` Datos: ${formulariosDatos[tipo.formulario_datos] || tipo.formulario_datos}.` : ''}</p>
            <ListaDocumentos usuario={usuario} tipo={tipo} enlazado={documento => enlazadoA(tipo.formulario_datos, documento, data)} />
          </div>
          <div className="flex shrink-0 flex-wrap items-center gap-2">
            <Etiqueta clase={exigeClase}>{exigeTexto}</Etiqueta>
            <Etiqueta clase={coloresEstadoDocumento[tipo.estado] || 'bg-slate-100 text-slate-700'}>{etiqueta(estadosDocumento, tipo.estado)}{tipo.documentos > 1 ? ` · ${tipo.documentos} documentos` : ''}</Etiqueta>
            {puedeSubir && aplica && ofrecerTipo(noAplica, tipo) && <button disabled={ocupado || !!sinLegajo} onClick={() => { setAviso(''); setSubiendo(tipo.id) }} className={`${enlace} inline-flex items-center gap-2`}><Icon name="paperclip" size={18} />{d && !tipo.permite_multiples ? 'Subsanar documento' : 'Subir documento'}</button>}
          </div>
        </div>
        {subiendo === tipo.id && <div className="mt-4"><SubirDocumentoForm usuario={usuario} seccion={2} tipo={tipo} titular={data.titular} enlaces={enlacesDe(tipo.formulario_datos, data)} onSaved={documento => subido(tipo, documento)} onCancel={() => setSubiendo(null)} /></div>}
        {enlazando?.tipoId === tipo.id && <div className="mt-4"><FormularioEnlazado usuario={usuario} data={data} formulario={enlazando.formulario} documentoId={enlazando.documentoId} onDone={enlazado} /></div>}
      </li>
    })}</ul>
  </section>
}

// Registros anulados: vista aparte, atenuada, solo con sistema.administrar (el backend responde 403 sin él).
function ListaAnulados({ usuario, clave, titulo, campos }) {
  const request = useLiveQuery(`/expedientes/${usuario}/seccion-02/anulados`)
  const registros = request.data?.data[clave] || []
  if (request.error) return <p role="alert" className="mt-3 text-sm text-rose-700">No se pudieron cargar los anulados. <button onClick={request.retry} className="underline">Reintentar</button></p>
  if (!request.data) return <p role="status" className="mt-3 text-sm text-slate-500">Cargando anulados…</p>
  if (!registros.length) return <p className="mt-3 text-sm text-slate-500">No hay registros anulados.</p>
  return <div className="mt-3 space-y-3">{registros.map(record => <article key={record.id} className="rounded-2xl border border-rose-100 bg-white p-4 opacity-75">
    <div className="flex flex-wrap items-start justify-between gap-2"><h4 className="text-sm font-semibold text-slate-700 line-through decoration-rose-300">{titulo(record.values)}</h4><Etiqueta clase="bg-rose-50 text-rose-700">Anulado</Etiqueta></div>
    <dl className="mt-3 grid gap-2 text-xs sm:grid-cols-2">{campos.map(([label, render]) => <div key={label}><dt className="text-slate-500">{label}</dt><dd className="mt-0.5 text-slate-700">{render(record.values)}</dd></div>)}
      <div><dt className="text-slate-500">Registrado por</dt><dd className="mt-0.5 text-slate-700">{record.values.registrado_por || 'No registrado'}</dd></div>
      <div className="sm:col-span-2"><dt className="text-rose-700">Motivo de anulación</dt><dd className="mt-0.5 text-slate-700">{record.values.motivo_anulacion}</dd></div>
    </dl>
  </article>)}</div>
}

function Anulados({ etiqueta: nombre = 'anulados', ...props }) {
  const puede = usePuede('sistema.administrar')
  const [abierto, setAbierto] = useState(false)
  if (!puede) return null
  return <div className="mb-8 -mt-4">
    <button onClick={() => setAbierto(!abierto)} className="text-xs font-semibold text-slate-500 hover:text-slate-700">{abierto ? `Ocultar ${nombre}` : `Ver ${nombre}`}</button>
    {abierto && <ListaAnulados {...props} />}
  </div>
}

function DatosPuesto({ vinculo }) {
  const perfiles = vinculo.datos_puesto
  return <div className="mt-5 rounded-xl bg-slate-50 p-4">
    <h5 className="text-xs font-semibold text-slate-700">Datos del puesto · {etiqueta(tiposPersonal, vinculo.values.tipo_personal)}</h5>
    {perfiles.length === 0 ? <p className="mt-2 text-xs text-slate-500">Sin perfil {vinculo.values.tipo_personal === 'docente' ? 'docente' : 'administrativo'} registrado para este vínculo. Se registra con «Datos del puesto».</p>
      : perfiles.map(perfil => <dl key={perfil.id} className="mt-3 grid gap-3 text-sm sm:grid-cols-3">{Object.entries(perfil.values).map(([key, value]) => <div key={key}><dt className="text-xs text-slate-500">{camposDatosPuesto[key] || key}</dt><dd className="mt-1 break-words text-slate-800">{texto(value)}</dd></div>)}</dl>)}
  </div>
}

function AvisoVencimiento({ vinculo, aviso }) {
  const { estado, dias, fin_efectivo: fin, por_adenda: porAdenda } = vinculo.vencimiento
  const origen = porAdenda ? 'según su última adenda' : 'según la fecha de fin prevista'
  if (estado === 'vencido') return <Aviso tono="rose">El vínculo venció el {fecha(fin)} ({origen}) y no tiene fecha de cese ni una adenda posterior. Hace {-dias} día(s).</Aviso>
  if (estado === 'por_vencer') return <Aviso>El vínculo vence el {fecha(fin)} ({origen}): {dias === 0 ? 'hoy' : `en ${dias} día(s)`}. Se avisa con {aviso} días de anticipación.</Aviso>
  return null
}

// Un vínculo con su vigencia, sus adendas en orden cronológico, el fin efectivo y los datos del puesto.
function VinculoCard({ vinculo, aviso, acciones, accionAdenda, destacado }) {
  const v = vinculo.values
  const [estadoTexto, estadoClase] = estadosVencimiento[vinculo.vencimiento.estado]
  return <article className={`rounded-2xl border bg-white p-5 ${destacado ? 'border-teal-300 ring-1 ring-teal-100' : 'border-slate-200'}`}>
    <div className="flex flex-wrap items-start justify-between gap-3"><div className="min-w-0"><h4 className="font-semibold text-slate-800">{v.nombre_puesto}</h4><p className="mt-1 text-xs text-slate-500">{etiqueta(tiposPersonal, v.tipo_personal)} · {etiqueta(regimenesLaborales, v.regimen_laboral)} · {v.numero_documento_vinculo || 'sin N.º de resolución o contrato'}</p></div><Etiqueta clase={estadoClase}>{estadoTexto}</Etiqueta></div>
    <div className="mt-4"><AvisoVencimiento vinculo={vinculo} aviso={aviso} /></div>
    <dl className="grid gap-3 text-sm sm:grid-cols-3">
      {[['Inicio', fecha(v.fecha_inicio)], ['Fin prevista', v.fecha_fin_prevista ? fecha(v.fecha_fin_prevista) : 'Sin fecha de fin'], ['Fin efectivo', vinculo.vencimiento.fin_efectivo ? `${fecha(vinculo.vencimiento.fin_efectivo)}${vinculo.vencimiento.por_adenda ? ' (por adenda)' : ''}` : 'Sin fecha de fin'],
        ['Fecha de cese', fecha(v.fecha_cese)], ['Régimen normativo', vinculo.regimen_normativo || 'No registrado'], ['Unidad orgánica', vinculo.unidad_organica || 'No registrada'],
        ['Obligado a DJBR', textoBandera(v.obligado_djbr)], ['Exige colegiatura', textoBandera(v.exige_colegiatura)], ['Exige estudios básicos', textoBandera(v.exige_estudios_basicos)], ['Período de prueba', textoBandera(v.tiene_periodo_prueba)],
        ['Registrado por', vinculo.registrado_por || 'No registrado (registro anterior)'],
      ].map(([label, valor]) => <div key={label}><dt className="text-xs text-slate-500">{label}</dt><dd className="mt-1 break-words text-slate-800">{valor}</dd></div>)}
    </dl>
    <div className="mt-5">
      <h5 className="text-xs font-semibold text-slate-700">Adendas ({vinculo.adendas.length})</h5>
      {vinculo.adendas.length === 0 ? <p className="mt-2 text-xs text-slate-500">Sin adendas registradas.</p> : <div className="mt-2 overflow-x-auto"><table className="w-full text-left text-xs"><thead className="text-slate-500"><tr>{['N.º', 'Motivo', 'Inicio', 'Fin', 'Registrado por', ''].map((t, i) => <th key={i} className="py-2 pr-4 font-medium">{t}</th>)}</tr></thead>
        <tbody>{vinculo.adendas.map((adenda, i) => <tr key={adenda.id} className="border-t border-slate-100 text-slate-700"><td className="py-2 pr-4">{adenda.values.numero || 'Sin número'}{i === vinculo.adendas.length - 1 && <span className="ml-2 text-[10px] text-teal-700">última</span>}</td><td className="py-2 pr-4">{texto(adenda.values.motivo)}</td><td className="py-2 pr-4">{fecha(adenda.values.fecha_inicio)}</td><td className="py-2 pr-4">{fecha(adenda.values.fecha_fin)}</td><td className="py-2 pr-4">{adenda.values.registrado_por || 'No registrado'}</td><td className="py-2 pr-4">{accionAdenda?.(adenda)}</td></tr>)}</tbody></table></div>}
    </div>
    <DatosPuesto vinculo={vinculo} />
    {acciones}
  </article>
}

function Formalizacion({ usuario, data, sinLegajo }) {
  const puedeRegistrar = usePuede('legajo.editar')
  const puedeAnular = usePuede('sistema.administrar')
  const [form, setForm] = useState(null)
  const [anulando, setAnulando] = useState(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [aviso, setAviso] = useState('')
  const vigentes = data.vinculos.filter(v => v.vigente)
  const historial = data.vinculos.filter(v => !v.vigente)
  const cerrar = guardado => { setForm(null); setAviso(guardado ? 'Registro guardado.' : '') }
  const ocupado = !!form || !!anulando
  const empezar = accion => { setAviso(''); setError(''); accion() }

  // Vínculos y adendas no se eliminan: se anulan con motivo (solo administración) y pasan a la vista de anulados.
  async function anular(motivo) {
    if (busy) return
    setBusy(true)
    setError('')
    try {
      await anularSeccion02(usuario, anulando.tipo, anulando.record.id, motivo)
      setAnulando(null)
      setAviso('Registro anulado.')
    } catch (failure) {
      setError(failure.response?.status === 409 ? 'El registro ya estaba anulado.' : apiErrorMessage(failure))
    } finally {
      setBusy(false)
    }
  }
  const tituloAdenda = adenda => `Adenda ${adenda.values.numero || 'sin número'} (${fecha(adenda.values.fecha_inicio)} – ${fecha(adenda.values.fecha_fin)})`
  const accionAdenda = vinculo => puedeAnular && (adenda => <button disabled={ocupado} onClick={() => empezar(() => setAnulando({ tipo: 'vinculo-adendas', record: adenda, vinculoId: vinculo.id, titulo: tituloAdenda }))} className="text-xs font-semibold text-rose-700 disabled:opacity-50">Anular</button>)
  const acciones = vinculo => <>
    {(puedeRegistrar || puedeAnular) && <div className="mt-5 flex flex-wrap items-center gap-4">
      {puedeRegistrar && <>
        <button disabled={ocupado || !!sinLegajo || vinculo.cerrado} onClick={() => empezar(() => setForm({ tipo: 'adenda', vinculo }))} className={enlace}>Registrar adenda</button>
        <button disabled={ocupado || !!sinLegajo || vinculo.cerrado} onClick={() => empezar(() => setForm({ tipo: 'vinculo', record: vinculo }))} className={enlace}>Corregir</button>
        <button disabled={ocupado || !!sinLegajo || vinculo.cerrado} onClick={() => empezar(() => setForm({ tipo: 'puesto', vinculo }))} className={enlace}>Datos del puesto</button>
      </>}
      {puedeAnular && <button disabled={ocupado} onClick={() => empezar(() => setAnulando({ tipo: 'vinculos-laborales', record: vinculo, vinculoId: vinculo.id, titulo: v => v.values.nombre_puesto }))} className="text-sm font-semibold text-rose-700 disabled:opacity-50">Anular vínculo</button>}
      {puedeRegistrar && (sinLegajo || vinculo.cerrado) && <Bloqueo>{sinLegajo || 'Vínculo cerrado (con fecha de cese): no se corrige ni recibe adendas.'}</Bloqueo>}
    </div>}
    {anulando?.vinculoId === vinculo.id && <AnularForm record={anulando.record} titulo={anulando.titulo} busy={busy} onCancel={() => setAnulando(null)} onConfirm={anular} />}
  </>
  const formulario = form && (form.tipo === 'adenda'
    ? <AdendaForm usuario={usuario} vinculos={[form.vinculo]} onSaved={() => cerrar(true)} onCancel={() => cerrar(false)} />
    : form.tipo === 'puesto' ? <DatosPuestoForm key={form.vinculo.id} usuario={usuario} vinculo={form.vinculo} onSaved={() => cerrar(true)} onCancel={() => cerrar(false)} />
      : <VinculoForm key={form.record?.id || 'nuevo'} usuario={usuario} record={form.record} onSaved={() => cerrar(true)} onCancel={() => cerrar(false)} />)
  const tarjeta = (v, destacado) => <VinculoCard key={v.id} vinculo={v} aviso={data.dias_aviso_vencimiento} destacado={destacado} acciones={acciones(v)} accionAdenda={accionAdenda(v)} />
  return <section aria-label="Vínculos laborales" className="mb-8">
    <div className="mb-4 flex flex-wrap items-center justify-between gap-3"><h3 className="text-base font-semibold text-slate-800">Vínculos laborales</h3>{puedeRegistrar && <div className="flex flex-wrap items-center gap-3">{sinLegajo && <Bloqueo>{sinLegajo}</Bloqueo>}<button disabled={ocupado || !!sinLegajo} onClick={() => empezar(() => setForm({ tipo: 'vinculo' }))} className={boton}>Registrar vínculo</button></div>}</div>
    {aviso && <p role="status" className="mb-4 rounded-lg bg-teal-50 p-3 text-sm text-teal-800">{aviso}</p>}
    {error && <p role="alert" className="mb-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
    {formulario}
    <p className="mb-4 text-xs text-slate-500">Vínculos y adendas no se eliminan: si hay un error, se anulan con motivo (administración) y se registran de nuevo. Las adendas no se corrigen.</p>
    {data.vinculos.length === 0 && <p className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">No hay vínculos laborales registrados. {sinLegajo ? 'Primero se debe abrir el legajo.' : 'Registra el vínculo (resolución o contrato) para habilitar adendas, inducciones y período de prueba.'}</p>}
    {data.vinculos.length > 0 && vigentes.length === 0 && <Aviso>La persona no tiene un vínculo vigente: todos están cesados, vencidos o aún no inician. Revisa el historial.</Aviso>}
    <div className="space-y-4">{vigentes.map(v => tarjeta(v, true))}</div>
    {historial.length > 0 && <details className="mt-5" open={vigentes.length === 0}><summary className="cursor-pointer text-sm font-semibold text-slate-600">Historial de vínculos ({historial.length})</summary>
      <div className="mt-4 space-y-4">{historial.map(v => tarjeta(v, false))}</div>
    </details>}
    <div className="mt-6 flex flex-wrap gap-x-6">
      <Anulados usuario={usuario} clave="vinculos_laborales" etiqueta="vínculos anulados" titulo={v => v.nombre_puesto} campos={[['Tipo de personal', v => etiqueta(tiposPersonal, v.tipo_personal)], ['Régimen laboral', v => etiqueta(regimenesLaborales, v.regimen_laboral)], ['N.º de resolución o contrato', v => texto(v.numero_documento_vinculo)], ['Inicio', v => fecha(v.fecha_inicio)]]} />
      <Anulados usuario={usuario} clave="vinculo_adendas" etiqueta="adendas anuladas" titulo={v => `Adenda ${v.numero || 'sin número'}`} campos={[['Motivo de la adenda', v => texto(v.motivo)], ['Vigencia', v => `${fecha(v.fecha_inicio)} – ${fecha(v.fecha_fin)}`], ['Vínculo', v => etiquetaVinculo(data.vinculos.find(item => item.id === v.vinculo_laboral_id) || { values: { nombre_puesto: 'Vínculo anulado o no encontrado' } })]]} />
    </div>
  </section>
}

function Seleccion({ usuario, data, sinLegajo }) {
  const vinculos = data.vinculos.filter(v => v.vigente)
  const principales = vinculos.length ? vinculos : data.vinculos.slice(0, 1)
  return <>
    {!vinculos.length && data.vinculos.length > 0 && <Aviso>No hay un vínculo vigente: se muestra el más reciente.</Aviso>}
    <Registros titulo={vinculos.length > 1 ? 'Proceso de selección de los vínculos vigentes' : 'Proceso de selección del vínculo vigente'} vacio="Sin vínculo laboral: los datos de selección se guardan en el vínculo. Primero registra el vínculo en 2.2." registros={principales} bloqueo={sinLegajo}
      tituloRegistro={record => record.values.nombre_puesto} subtitulo={record => etiquetaVinculo(record)}
      campos={[['Proceso de selección', v => texto(v.proceso_seleccion)], ['Fecha del resultado', v => fecha(v.fecha_resultado_seleccion)]]}
      corregir={{ etiqueta: 'Registrar o corregir datos de selección', puede: record => !record.cerrado, motivo: 'Vínculo cerrado (con fecha de cese): no se corrige.' }}
      renderForm={props => <SeleccionForm key={props.record.id} usuario={usuario} {...props} />} />
  </>
}

function Induccion({ usuario, data, bloqueo }) {
  const vigentes = data.vinculos.filter(v => v.vigente && v.inducciones_faltantes.length)
  return <>
    {vigentes.map(v => <Aviso key={v.id}>Falta la inducción {v.inducciones_faltantes.map(t => etiqueta(tiposInduccion, t).toLowerCase()).join(' y la ')} del vínculo vigente «{v.values.nombre_puesto}».</Aviso>)}
    <Registros titulo="Inducciones" agregar="Registrar inducción" vacio={bloqueo ? `No hay inducciones registradas. ${bloqueo}` : 'No hay inducciones registradas. Registra la general y la específica.'} registros={data.inducciones} bloqueo={bloqueo}
      tituloRegistro={record => `Inducción ${etiqueta(tiposInduccion, record.values.tipo).toLowerCase()}`} subtitulo={record => fecha(record.values.fecha)}
      campos={[['Vínculo laboral', v => etiquetaVinculo(data.vinculos.find(item => item.id === v.vinculo_laboral_id) || { values: { nombre_puesto: 'No encontrado' } })], ['Responsable', v => texto(v.responsable)]]}
      onAnular={(record, motivo) => anularSeccion02(usuario, 'inducciones', record.id, motivo)}
      renderForm={props => <InduccionForm usuario={usuario} vinculos={data.vinculos.filter(v => !v.cerrado)} {...props} />} />
    <Anulados usuario={usuario} clave="inducciones" titulo={v => `Inducción ${etiqueta(tiposInduccion, v.tipo).toLowerCase()} · ${fecha(v.fecha)}`} campos={[['Responsable', v => texto(v.responsable)]]} />
  </>
}

function NoAplicaPrueba({ onNavigate }) {
  // La bandera del puesto se registra en el vínculo laboral (2.2).
  const puedeVinculo = usePuede('legajo.editar')
  return <section className="rounded-2xl border border-slate-200 bg-slate-50 p-6 text-slate-500">
    <p className="text-sm font-semibold">No aplica: «Tiene período de prueba» está en No</p>
    <p className="mt-2 text-xs">La subsección se activa con «Tiene período de prueba» en Sí en un vínculo laboral (sin vínculo vigente, en los datos de la persona). Mientras esté en No, no hay formularios ni documentos exigidos.</p>
    {puedeVinculo && <div className="mt-4 flex flex-wrap gap-4 text-xs font-semibold">
      <button onClick={() => onNavigate('s02-vinculo')} className="text-teal-700 hover:underline">Revisar la bandera del vínculo en 2.2</button>
    </div>}
  </section>
}

function Prueba({ usuario, data, bloqueo }) {
  const vencidos = data.periodos_prueba.filter(p => p.alerta === 'pendiente_vencido')
  return <>
    {vencidos.map(p => <Aviso key={p.id} tono="rose">El período de prueba terminó el {fecha(p.values.fecha_fin)} y el resultado sigue pendiente (hace {-p.dias_restantes} día(s)). Registra el resultado.</Aviso>)}
    <Registros titulo="Períodos de prueba" agregar="Registrar período de prueba" vacio={bloqueo ? `No hay períodos de prueba registrados. ${bloqueo}` : 'No hay períodos de prueba registrados.'} registros={data.periodos_prueba} bloqueo={bloqueo}
      tituloRegistro={record => `Período de prueba ${fecha(record.values.fecha_inicio)} – ${fecha(record.values.fecha_fin)}`} subtitulo={record => `Resultado: ${etiqueta(resultadosPeriodo, record.values.resultado)}`}
      campos={[
        ['Vínculo laboral', v => etiquetaVinculo(data.vinculos.find(item => item.id === v.vinculo_laboral_id) || { values: { nombre_puesto: 'No encontrado' } })],
        ['Días restantes', (v, record) => record.dias_restantes === null ? 'Con resultado' : record.dias_restantes < 0 ? `Vencido hace ${-record.dias_restantes} día(s)` : `${record.dias_restantes} día(s)`],
        ['Fecha del resultado', v => fecha(v.fecha_resultado)],
      ]}
      corregir={{ etiqueta: 'Registrar resultado', puede: record => record.values.resultado === 'pendiente', motivo: 'Con resultado registrado: para corregirlo, anúlalo y regístralo de nuevo.' }}
      onAnular={(record, motivo) => anularSeccion02(usuario, 'periodos-prueba', record.id, motivo)}
      renderForm={props => <PeriodoForm key={props.record?.id || 'nuevo'} usuario={usuario} vinculos={data.vinculos.filter(v => !v.cerrado)} {...props} />} />
    <Anulados usuario={usuario} clave="periodos_prueba" titulo={v => `Período de prueba ${fecha(v.fecha_inicio)} – ${fecha(v.fecha_fin)}`} campos={[['Resultado', v => etiqueta(resultadosPeriodo, v.resultado)], ['Fecha del resultado', v => fecha(v.fecha_resultado)]]} />
  </>
}

export default function Seccion02Panel({ usuario, categoria, onNavigate }) {
  const request = useLiveQuery(`/expedientes/${usuario}/seccion-02`)
  const data = request.data?.data
  const { codigo, titulo } = subseccionesSeccion02[categoria]
  const error = request.error && <p role="alert" className="mb-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{request.error.response?.status === 403 ? 'No tienes permiso para ver esta sección.' : 'No se pudo actualizar la sección. Lo que ves puede no estar al día.'} <button onClick={request.retry} className="font-semibold underline">Reintentar</button></p>
  if (!data) return error || <p role="status" className="rounded-xl bg-slate-100 p-6 text-sm text-slate-500">Cargando…</p>

  const subseccion = data.subsecciones.find(item => item.codigo === codigo)
  const sinLegajo = !data.legajo && 'La persona no tiene un legajo abierto.'
  const sinVinculo = !data.vinculos.some(v => !v.cerrado) && 'Primero registra un vínculo laboral en 2.2 (los cesados no reciben registros).'
  const bloqueo = sinLegajo || sinVinculo
  const irAVinculo = categoria !== 's02-vinculo' && <button onClick={() => onNavigate('s02-vinculo')} className="rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-semibold text-amber-900">Ir a 2.2 Formalización del vínculo</button>
  // Solo No oculta la 2.4; Sin responder la muestra con sus tipos por determinar.
  const noAplica = codigo === '2.4' && data.tiene_periodo_prueba === false

  return <section aria-label={titulo}>
    {error}
    <Resumen data={data} onNavigate={onNavigate} />
    {(data.avisos_perfil || []).map(aviso => <Aviso key={aviso}>{aviso}</Aviso>)}
    <h2 className="mb-5 text-xl font-semibold text-slate-800">{titulo}</h2>
    {noAplica ? <NoAplicaPrueba onNavigate={onNavigate} /> : <>
      {sinLegajo ? <Aviso accion={<AbrirLegajo usuario={usuario} />}>{sinLegajo} Los formularios y la carga de documentos de la sección 02 están deshabilitados hasta abrirlo.</Aviso>
        : sinVinculo && categoria !== 's02-vinculo' && <Aviso accion={irAVinculo}>La persona no tiene un vínculo laboral abierto. Los formularios de esta subsección dependen del vínculo.</Aviso>}
      {codigo === '2.1' && <Seleccion usuario={usuario} data={data} sinLegajo={sinLegajo} />}
      {codigo === '2.2' && <Formalizacion usuario={usuario} data={data} sinLegajo={sinLegajo} />}
      {codigo === '2.3' && <Induccion usuario={usuario} data={data} bloqueo={bloqueo} />}
      {codigo === '2.4' && <Prueba usuario={usuario} data={data} bloqueo={bloqueo} />}
      <Documentos usuario={usuario} data={data} subseccion={subseccion} sinLegajo={sinLegajo} />
    </>}
  </section>
}
