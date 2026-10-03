import { useState } from 'react'
import { useLiveQuery } from '../../../../shared/queries/useLiveQuery'
import { usePuede } from '../../../auth/session'
import { anularSeccion02 } from '../../api/seccion02Api'
import { camposDatosPuesto, estadosDocumento, estadosSubseccion, estadosVencimiento, etiquetaVinculo, formulariosDatos, regimenesLaborales, resultadosPeriodo, subseccionesSeccion02, tiposInduccion, tiposPersonal } from '../../config/seccion02'
import { etiqueta } from '../../config/seccion01'
import { AdendaForm, InduccionForm, PeriodoForm, SeleccionForm, VinculoForm } from './Formularios'
import Registros, { Bloqueo } from './Registros'

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
// Cargados y verificados van por separado: la fórmula de completitud está pendiente de definir con el cliente.
function Resumen({ data, onNavigate }) {
  const r = data.resumen
  return <section aria-label="Resumen de la sección 02" className="mb-8 rounded-2xl border border-teal-100 bg-white p-5">
    <div className="flex flex-wrap gap-x-10 gap-y-4">
      <div><p className="text-xs text-slate-500">Tipos de documento pendientes</p><p className="mt-1 text-2xl font-bold text-slate-800">{r.pendientes} <span className="text-sm font-normal text-slate-500">de {r.tipos}</span></p></div>
      <div><p className="text-xs text-slate-500">Exigidos con documento cargado</p><p className="mt-1 text-2xl font-bold text-slate-800">{r.exigidos_cargados} <span className="text-sm font-normal text-slate-500">de {r.exigidos.length}</span></p></div>
      <div><p className="text-xs text-slate-500">Exigidos verificados</p><p className="mt-1 text-2xl font-bold text-slate-800">{r.exigidos_verificados} <span className="text-sm font-normal text-slate-500">de {r.exigidos.length}</span></p></div>
    </div>
    <p className="mt-3 text-xs text-slate-500">{data.tiene_periodo_prueba ? '' : 'La subsección 2.4 no aplica y no se cuenta. '}Cargados y verificados se muestran por separado: el criterio de completitud del legajo está pendiente de definir.{r.por_determinar ? ` ${r.por_determinar} tipo(s) no se pueden evaluar porque el régimen laboral registrado no es carrera, plazo determinado ni designado.` : ''}</p>
    <div className="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">{data.subsecciones.map(sub => {
      const [nombre, clase] = estadosSubseccion[sub.estado]
      return <button key={sub.codigo} onClick={() => onNavigate(categoriaDe[sub.codigo])} className={`rounded-xl border border-slate-200 p-3 text-left hover:bg-slate-50 ${sub.aplica ? '' : 'opacity-60'}`}>
        <span className="block text-xs font-semibold text-slate-700">{subseccionesSeccion02[categoriaDe[sub.codigo]]?.titulo || sub.nombre}</span>
        <span className="mt-2 flex flex-wrap items-center gap-2"><Etiqueta clase={clase}>{nombre}</Etiqueta>{sub.aplica && sub.exigidos > 0 && <span className="text-[11px] text-slate-500">{sub.exigidos_cargados}/{sub.exigidos} cargados · {sub.exigidos_verificados} verificados</span>}</span>
      </button>
    })}</div>
    {r.exigidos.length > 0 && <details className="mt-4"><summary className="cursor-pointer text-xs font-semibold text-teal-700">Documentos que se exigen a esta persona ({r.exigidos.length})</summary>
      <ul className="mt-3 grid gap-1 text-xs text-slate-600 sm:grid-cols-2">{r.exigidos.map(tipo => <li key={tipo.literal + tipo.nombre}>{tipo.literal}) {tipo.nombre} — {tipo.verificado ? 'verificado' : tipo.cargado ? 'cargado' : 'pendiente'}</li>)}</ul>
    </details>}
  </section>
}

// Tipos de documento de la subsección: si se exigen (aplica_* y requiere_*), estado y metadatos del último documento.
function Documentos({ subseccion, aplica = true }) {
  if (!subseccion) return <Aviso>El catálogo de documentos de esta subsección no está cargado en la base de datos (Seccion02CatalogoSeeder).</Aviso>
  return <section aria-label="Documentos de la subsección" className={aplica ? '' : 'opacity-60'}>
    <h3 className="mb-1 text-base font-semibold text-slate-800">Documentos</h3>
    <p className="mb-4 text-xs text-slate-500">La carga de documentos (PDF y metadatos) aún no está disponible desde la ficha.</p>
    <ul className="divide-y divide-slate-100 overflow-hidden rounded-2xl border border-slate-200 bg-white">{subseccion.tipos.map(tipo => {
      const [exigeTexto, exigeClase] = exigencia[String(aplica ? tipo.exigido : false)]
      const d = tipo.documento
      return <li key={tipo.id} className="flex flex-wrap items-start justify-between gap-3 p-4">
        <div className="min-w-0 flex-1">
          <p className="text-sm font-medium text-slate-800">{tipo.literal && <span className="mr-1 text-slate-500">{tipo.literal})</span>}{tipo.nombre}</p>
          <p className="mt-1 text-xs text-slate-500">{aplica ? tipo.motivo : 'No aplica a esta persona.'}{tipo.permite_multiples ? ' Admite varios documentos.' : ''}{tipo.formulario_datos ? ` Datos: ${formulariosDatos[tipo.formulario_datos] || tipo.formulario_datos}.` : ''}</p>
          {d ? <dl className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-slate-600">
            <div><dt className="inline text-slate-400">Folios: </dt><dd className="inline">{d.folio_inicio ? `${d.folio_inicio}–${d.folio_fin ?? '?'}` : 'sin foliar'}</dd></div>
            <div><dt className="inline text-slate-400">Emisor: </dt><dd className="inline">{texto(d.emisor)}</dd></div>
            <div><dt className="inline text-slate-400">Emitido: </dt><dd className="inline">{fecha(d.fecha_emision)}</dd></div>
            <div><dt className="inline text-slate-400">Incorporado: </dt><dd className="inline">{fecha(d.fecha_incorporacion)}</dd></div>
            {(tipo.requiere_num_resolucion || d.num_resolucion) && <div><dt className="inline text-slate-400">N.º resolución: </dt><dd className="inline">{texto(d.num_resolucion)}</dd></div>}
            <div><dt className="inline text-slate-400">Titular: </dt><dd className="inline">{d.titular_nombre ? `${d.titular_nombre} ${d.titular_documento || ''}` : 'No registrado'}</dd></div>
          </dl> : <p className="mt-2 text-[11px] text-slate-400">Sin documento cargado.</p>}
        </div>
        <div className="flex shrink-0 flex-wrap gap-2">
          <Etiqueta clase={exigeClase}>{exigeTexto}</Etiqueta>
          <Etiqueta clase="bg-slate-100 text-slate-700">{etiqueta(estadosDocumento, tipo.estado)}{tipo.documentos > 1 ? ` · ${tipo.documentos}` : ''}</Etiqueta>
        </div>
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

function Anulados(props) {
  const puede = usePuede('sistema.administrar')
  const [abierto, setAbierto] = useState(false)
  if (!puede) return null
  return <div className="mb-8 -mt-4">
    <button onClick={() => setAbierto(!abierto)} className="text-xs font-semibold text-slate-500 hover:text-slate-700">{abierto ? 'Ocultar anulados' : 'Ver anulados'}</button>
    {abierto && <ListaAnulados {...props} />}
  </div>
}

function DatosPuesto({ vinculo }) {
  const perfiles = vinculo.datos_puesto
  return <div className="mt-5 rounded-xl bg-slate-50 p-4">
    <h5 className="text-xs font-semibold text-slate-700">Datos del puesto · {etiqueta(tiposPersonal, vinculo.values.tipo_personal)}</h5>
    {perfiles.length === 0 ? <p className="mt-2 text-xs text-slate-500">Sin perfil {vinculo.values.tipo_personal === 'docente' ? 'docente' : 'administrativo'} registrado para este vínculo. Se registra desde la configuración del personal (aún no disponible en la ficha).</p>
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
function VinculoCard({ vinculo, aviso, acciones, destacado }) {
  const v = vinculo.values
  const [estadoTexto, estadoClase] = estadosVencimiento[vinculo.vencimiento.estado]
  return <article className={`rounded-2xl border bg-white p-5 ${destacado ? 'border-teal-300 ring-1 ring-teal-100' : 'border-slate-200'}`}>
    <div className="flex flex-wrap items-start justify-between gap-3"><div className="min-w-0"><h4 className="font-semibold text-slate-800">{v.nombre_puesto}</h4><p className="mt-1 text-xs text-slate-500">{etiqueta(tiposPersonal, v.tipo_personal)} · {etiqueta(regimenesLaborales, v.regimen_laboral)} · {v.numero_documento_vinculo || 'sin N.º de resolución o contrato'}</p></div><Etiqueta clase={estadoClase}>{estadoTexto}</Etiqueta></div>
    <div className="mt-4"><AvisoVencimiento vinculo={vinculo} aviso={aviso} /></div>
    <dl className="grid gap-3 text-sm sm:grid-cols-3">
      {[['Inicio', fecha(v.fecha_inicio)], ['Fin prevista', v.fecha_fin_prevista ? fecha(v.fecha_fin_prevista) : 'Sin fecha de fin'], ['Fin efectivo', vinculo.vencimiento.fin_efectivo ? `${fecha(vinculo.vencimiento.fin_efectivo)}${vinculo.vencimiento.por_adenda ? ' (por adenda)' : ''}` : 'Sin fecha de fin'],
        ['Fecha de cese', fecha(v.fecha_cese)], ['Régimen normativo', vinculo.regimen_normativo || 'No registrado'], ['Unidad orgánica', vinculo.unidad_organica || 'No registrada'],
        ['Obligado a DJBR', texto(v.obligado_djbr)], ['Exige colegiatura', texto(v.exige_colegiatura)], ['Período de prueba', texto(v.tiene_periodo_prueba)],
      ].map(([label, valor]) => <div key={label}><dt className="text-xs text-slate-500">{label}</dt><dd className="mt-1 break-words text-slate-800">{valor}</dd></div>)}
    </dl>
    <div className="mt-5">
      <h5 className="text-xs font-semibold text-slate-700">Adendas ({vinculo.adendas.length})</h5>
      {vinculo.adendas.length === 0 ? <p className="mt-2 text-xs text-slate-500">Sin adendas registradas.</p> : <div className="mt-2 overflow-x-auto"><table className="w-full text-left text-xs"><thead className="text-slate-500"><tr>{['N.º', 'Motivo', 'Inicio', 'Fin', 'Registrado por'].map(t => <th key={t} className="py-2 pr-4 font-medium">{t}</th>)}</tr></thead>
        <tbody>{vinculo.adendas.map((adenda, i) => <tr key={adenda.id} className="border-t border-slate-100 text-slate-700"><td className="py-2 pr-4">{adenda.values.numero || 'Sin número'}{i === vinculo.adendas.length - 1 && <span className="ml-2 text-[10px] text-teal-700">última</span>}</td><td className="py-2 pr-4">{texto(adenda.values.motivo)}</td><td className="py-2 pr-4">{fecha(adenda.values.fecha_inicio)}</td><td className="py-2 pr-4">{fecha(adenda.values.fecha_fin)}</td><td className="py-2 pr-4">{adenda.values.registrado_por || 'No registrado'}</td></tr>)}</tbody></table></div>}
    </div>
    <DatosPuesto vinculo={vinculo} />
    {acciones}
  </article>
}

function Formalizacion({ usuario, data, sinLegajo }) {
  const puedeRegistrar = usePuede('legajo.editar')
  const [form, setForm] = useState(null)
  const [aviso, setAviso] = useState('')
  const vigentes = data.vinculos.filter(v => v.vigente)
  const historial = data.vinculos.filter(v => !v.vigente)
  const cerrar = guardado => { setForm(null); setAviso(guardado ? 'Registro guardado.' : '') }
  const ocupado = !!form
  const acciones = vinculo => puedeRegistrar && <div className="mt-5 flex flex-wrap items-center gap-4">
    <button disabled={ocupado || !!sinLegajo || vinculo.cerrado} onClick={() => setForm({ tipo: 'adenda', vinculo })} className={enlace}>Registrar adenda</button>
    <button disabled={ocupado || !!sinLegajo || vinculo.cerrado} onClick={() => setForm({ tipo: 'vinculo', record: vinculo })} className={enlace}>Corregir</button>
    {(sinLegajo || vinculo.cerrado) && <Bloqueo>{sinLegajo || 'Vínculo cerrado (con fecha de cese): no se corrige ni recibe adendas.'}</Bloqueo>}
  </div>
  const formulario = form && (form.tipo === 'adenda'
    ? <AdendaForm usuario={usuario} vinculos={[form.vinculo]} onSaved={() => cerrar(true)} onCancel={() => cerrar(false)} />
    : <VinculoForm key={form.record?.id || 'nuevo'} usuario={usuario} record={form.record} onSaved={() => cerrar(true)} onCancel={() => cerrar(false)} />)
  return <section aria-label="Vínculos laborales" className="mb-8">
    <div className="mb-4 flex flex-wrap items-center justify-between gap-3"><h3 className="text-base font-semibold text-slate-800">Vínculos laborales</h3>{puedeRegistrar && <div className="flex flex-wrap items-center gap-3">{sinLegajo && <Bloqueo>{sinLegajo}</Bloqueo>}<button disabled={ocupado || !!sinLegajo} onClick={() => setForm({ tipo: 'vinculo' })} className={boton}>Registrar vínculo</button></div>}</div>
    {aviso && <p role="status" className="mb-4 rounded-lg bg-teal-50 p-3 text-sm text-teal-800">{aviso}</p>}
    {formulario}
    <p className="mb-4 text-xs text-slate-500">Las adendas no se corrigen ni se anulan (el esquema no lo permite): revisa sus datos antes de guardar.</p>
    {data.vinculos.length === 0 && <p className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">No hay vínculos laborales registrados. {sinLegajo ? 'Primero se debe abrir el legajo.' : 'Registra el vínculo (resolución o contrato) para habilitar adendas, inducciones y período de prueba.'}</p>}
    {data.vinculos.length > 0 && vigentes.length === 0 && <Aviso>La persona no tiene un vínculo vigente: todos están cesados, vencidos o aún no inician. Revisa el historial.</Aviso>}
    <div className="space-y-4">{vigentes.map(v => <VinculoCard key={v.id} vinculo={v} aviso={data.dias_aviso_vencimiento} destacado acciones={acciones(v)} />)}</div>
    {historial.length > 0 && <details className="mt-5" open={vigentes.length === 0}><summary className="cursor-pointer text-sm font-semibold text-slate-600">Historial de vínculos ({historial.length})</summary>
      <div className="mt-4 space-y-4">{historial.map(v => <VinculoCard key={v.id} vinculo={v} aviso={data.dias_aviso_vencimiento} acciones={acciones(v)} />)}</div>
    </details>}
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
  const puedeVinculo = usePuede('legajo.editar')
  const veSensibles = usePuede('datos_sensibles.ver')
  // La bandera de la persona se edita en Información personal, que exige además datos sensibles.
  const puedeFicha = puedeVinculo && veSensibles
  return <section className="rounded-2xl border border-slate-200 bg-slate-50 p-6 text-slate-500">
    <p className="text-sm font-semibold">No aplica: esta persona no tiene período de prueba</p>
    <p className="mt-2 text-xs">La subsección se activa con la bandera «tiene período de prueba» en los datos generales de la persona o en un vínculo vigente. Mientras no la tenga, no hay formularios ni documentos exigidos.</p>
    {(puedeFicha || puedeVinculo) && <div className="mt-4 flex flex-wrap gap-4 text-xs font-semibold">
      {puedeFicha && <button onClick={() => onNavigate('general')} className="text-teal-700 hover:underline">Revisar la bandera en Información personal</button>}
      {puedeVinculo && <button onClick={() => onNavigate('s02-vinculo')} className="text-teal-700 hover:underline">Revisar la bandera del vínculo en 2.2</button>}
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
  const noAplica = codigo === '2.4' && !data.tiene_periodo_prueba

  return <section aria-label={titulo}>
    {error}
    <Resumen data={data} onNavigate={onNavigate} />
    <h2 className="mb-5 text-xl font-semibold text-slate-800">{titulo}</h2>
    {noAplica ? <NoAplicaPrueba onNavigate={onNavigate} /> : <>
      {sinLegajo ? <Aviso>{sinLegajo} Los formularios de la sección 02 están deshabilitados hasta abrirlo. Abrir el legajo desde la ficha aún no está disponible.</Aviso>
        : sinVinculo && categoria !== 's02-vinculo' && <Aviso accion={irAVinculo}>La persona no tiene un vínculo laboral abierto. Los formularios de esta subsección dependen del vínculo.</Aviso>}
      {codigo === '2.1' && <Seleccion usuario={usuario} data={data} sinLegajo={sinLegajo} />}
      {codigo === '2.2' && <Formalizacion usuario={usuario} data={data} sinLegajo={sinLegajo} />}
      {codigo === '2.3' && <Induccion usuario={usuario} data={data} bloqueo={bloqueo} />}
      {codigo === '2.4' && <Prueba usuario={usuario} data={data} bloqueo={bloqueo} />}
      <Documentos subseccion={subseccion} />
    </>}
  </section>
}
