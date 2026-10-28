import { Link } from 'react-router-dom'
import Icon from '../../components/ui/Icon'
import { useUsers } from '../users/hooks/useUsers'
import { usePuede } from '../auth/session'
import { useLiveQuery } from '../../shared/queries/useLiveQuery'
import { nombreLey, SIN_DEFINIR } from '../users/utils/leyes'
import { situaciones } from '../users/utils/situacion'

const nombresBandera = {
  tiene_derechohabientes: 'Tiene derechohabientes', con_discapacidad: 'Con discapacidad (servidor o hijos)',
  obligado_djbr: 'Obligado a la DJBR', exige_colegiatura: 'Exige colegiatura', exige_estudios_basicos: 'Exige estudios básicos', tiene_periodo_prueba: 'Período de prueba',
}

// Personas con condiciones del perfil sin responder (backend: /personal/banderas-sin-responder, sistema.administrar).
// Mientras estén sin responder, sus documentos quedan por determinar y no cuentan en la completitud.
function BanderasSinResponder({ panel }) {
  const consulta = useLiveQuery('/personal/banderas-sin-responder')
  const datos = consulta.data?.data
  return <section className={`${panel} lg:col-span-2`} aria-busy={!datos && !consulta.error}>
    <div className="flex flex-wrap items-start justify-between gap-4"><div><h2 className="text-sm font-semibold text-slate-800">Condiciones del personal sin responder</h2><p className="mt-1 text-xs text-slate-500">Personas con alguna condición en «Sin responder»: sus documentos quedan por determinar. Las del puesto cuentan solo con vínculo vigente.</p></div>
      <p className="text-3xl font-semibold tracking-tight text-amber-700">{consulta.error ? '—' : datos ? datos.total.toLocaleString('es') : '…'}<span className="ml-2 text-xs font-normal text-slate-500">persona(s)</span></p></div>
    {consulta.error && <p role="alert" className="mt-4 text-xs text-rose-700">No se pudo consultar. <button type="button" onClick={consulta.retry} className="font-semibold underline">Reintentar</button></p>}
    {datos && <dl className="mt-5 grid gap-3 min-[480px]:grid-cols-2 lg:grid-cols-3">{Object.entries(datos.por_bandera).map(([bandera, total]) => <div key={bandera} className="rounded-xl bg-slate-50 p-4"><dt className="text-xs text-slate-600">{nombresBandera[bandera] || bandera}</dt><dd className="mt-2 text-xl font-semibold text-slate-800">{total.toLocaleString('es')}</dd></div>)}</dl>}
    {/* Quiénes son: cada persona abre su ficha, donde «Completa el perfil» permite responder sin buscar cada campo. */}
    {datos?.personas?.length > 0 && <details className="mt-5 rounded-xl border border-slate-100">
      <summary className="cursor-pointer px-4 py-3 text-xs font-semibold text-teal-700">Ver personas con condiciones pendientes ({datos.total.toLocaleString('es')})</summary>
      <ul className="max-h-80 divide-y divide-slate-100 overflow-y-auto border-t border-slate-100">{datos.personas.map(persona => <li key={persona.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5">
        <div className="min-w-0"><Link to={`/legajos/${persona.id}`} className="text-sm font-semibold text-teal-800 hover:underline">{persona.nombre}</Link>
          <p className="mt-0.5 text-[11px] text-slate-500">Falta: {persona.pendientes.map(bandera => nombresBandera[bandera] || bandera).join(' · ')}</p></div>
        <Link to={`/legajos/${persona.id}`} className="shrink-0 text-xs font-semibold text-teal-700 hover:underline">Completar perfil →</Link>
      </li>)}</ul>
      {datos.total > datos.personas_mostradas && <p className="border-t border-slate-100 px-4 py-2.5 text-[11px] text-slate-500">Se muestran {datos.personas_mostradas} de {datos.total.toLocaleString('es')}, por apellido.</p>}
    </details>}
  </section>
}

export default function DashboardPage() {
  const { users, loading, loadError, refreshUsers } = useUsers()
  const administra = usePuede('sistema.administrar')
  const conVinculo = users.filter(user => user.situacion === 'vinculo_vigente').length
  // Tuvieron legajo pero ya no tienen vínculo vigente (cesados); sin legajo activo no cuenta aquí.
  const sinVinculo = users.filter(user => user.situacion === 'sin_vinculo_vigente').length
  const withEmail = users.filter(user => user.email_institucional?.trim()).length
  const ready = !loading && !loadError
  const show = value => loading ? '…' : loadError ? '—' : value.toLocaleString('es')
  const percent = count => ready && users.length ? Math.round(count / users.length * 100) : 0
  const date = new Intl.DateTimeFormat('es-PE', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'America/Lima' }).format(new Date())
  const stats = [
    { label: 'Personal registrado', value: users.length, detail: 'Ver directorio completo', icon: 'users', color: 'bg-teal-50 text-teal-700', to: '/personal' },
    { label: 'Con vínculo vigente', value: conVinculo, detail: 'Consultar situación laboral', icon: 'check', color: 'bg-sky-50 text-sky-700', to: '/personal?situacion=vinculo_vigente' },
    { label: 'Sin vínculo vigente', value: sinVinculo, detail: 'Ver personal sin vínculo', icon: 'clock', color: 'bg-amber-50 text-amber-700', to: '/personal?situacion=sin_vinculo_vigente' },
    { label: 'Con correo registrado', value: withEmail, detail: 'Ver personal con correo', icon: 'file', color: 'bg-violet-50 text-violet-700', to: '/personal?correo=con' },
  ]
  const situacionesPersonal = Object.entries(situaciones).map(([valor, { texto }]) => ({ label: texto, count: users.filter(user => user.situacion === valor).length, to: `/personal?situacion=${valor}`,
    color: { vinculo_vigente: 'bg-teal-500', sin_vinculo_vigente: 'bg-amber-400', sin_legajo: 'bg-slate-400' }[valor] }))
  const types = [
    { label: 'Docentes', count: users.filter(user => user.tipo_personal === 'docente').length, to: '/personal?tipo=docente', color: 'bg-teal-500' },
    { label: 'Administrativos', count: users.filter(user => user.tipo_personal === 'administrativo').length, to: '/personal?tipo=administrativo', color: 'bg-sky-500' },
    { label: 'Sin clasificación', count: users.filter(user => !['docente', 'administrativo'].includes(user.tipo_personal)).length, color: 'bg-slate-400' },
  ]
  // Por ley (regimen_normativo_id); sin ley, Sin definir (al final). El tipo de vínculo está en cada vínculo (2.2).
  const regimes = Object.entries(users.reduce((counts, user) => {
    const name = nombreLey(user)
    counts[name] = (counts[name] || 0) + 1
    return counts
  }, {})).sort((a, b) => (a[0] === SIN_DEFINIR) - (b[0] === SIN_DEFINIR) || b[1] - a[1])
  const panel = 'rounded-2xl border border-slate-200/80 bg-white p-6'
  return <>
    <header className="mb-7 flex flex-wrap items-end justify-between gap-5">
      <div><p className="text-[10px] font-semibold uppercase tracking-[.2em] text-teal-700">Panel administrativo</p><h1 className="mt-2 text-3xl font-bold tracking-tight text-slate-800">Resumen institucional</h1><p className="mt-3 text-sm text-slate-500">Indicadores del personal y cobertura de los datos registrados.</p></div>
      <div className="flex flex-wrap items-center gap-3"><span className="rounded-full border border-slate-200 bg-white px-4 py-2 text-xs text-slate-500">{date}</span></div>
    </header>
    {loadError && <div role="alert" className="mb-6 rounded-xl border border-rose-100 bg-rose-50 px-5 py-4 text-sm text-rose-800">{loadError}<button type="button" disabled={loading} onClick={refreshUsers} className="ml-3 font-semibold underline">Reintentar</button></div>}
    <section aria-label="Resumen del personal" aria-busy={loading} className="mb-6 grid grid-cols-1 gap-4 min-[480px]:grid-cols-2 xl:grid-cols-4">
      {stats.map(stat => <Link key={stat.label} to={stat.to} className="group rounded-2xl border border-slate-200/80 bg-white p-5 transition hover:-translate-y-1 hover:border-teal-300 hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-teal-600">
        <div className="flex items-center justify-between gap-3"><p className="text-xs font-medium text-slate-600">{stat.label}</p><span className={`grid size-10 place-items-center rounded-xl ${stat.color}`}><Icon name={stat.icon} /></span></div>
        <p className="mt-3 text-3xl font-semibold tracking-tight text-slate-800">{show(stat.value)}</p><div className="mt-3 flex items-center justify-between text-[11px] text-slate-500"><span>{stat.detail}</span><Icon name="arrow" size={16} /></div>
      </Link>)}
    </section>
    <div className="grid gap-6 lg:grid-cols-2">
      <section className={panel} aria-busy={loading}>
        <h2 className="text-sm font-semibold text-slate-800">Composición del personal</h2><p className="mt-1 text-xs text-slate-500">Distribución por función y por situación laboral (vínculo vigente y legajo)</p>
        <div className="mt-6 space-y-5">{[...types, ...situacionesPersonal].map(item => <div key={item.label}>
          <div className="mb-2 flex justify-between text-xs">{item.to ? <Link to={item.to} className="font-medium text-slate-600 hover:text-teal-700 hover:underline">{item.label}</Link> : <span className="text-slate-600">{item.label}</span>}<span className="font-semibold text-slate-700">{show(item.count)} · {ready ? `${percent(item.count)}%` : '—'}</span></div>
          <div className="h-3 overflow-hidden rounded-full bg-slate-100" aria-hidden="true"><div className={`h-full rounded-full ${item.color}`} style={{ width: `${percent(item.count)}%` }} /></div>
        </div>)}</div>
      </section>
      <section className={panel} aria-busy={loading}>
        <h2 className="text-sm font-semibold text-slate-800">Leyes / regímenes normativos</h2><p className="mt-1 text-xs text-slate-500">Cantidad de personal por ley. «Sin definir»: sin ley registrada.</p>
        <div className="mt-5 max-h-72 space-y-4 overflow-y-auto">{!ready ? <p className="text-sm text-slate-500">{loading ? 'Consultando registros…' : 'Datos no disponibles'}</p> : !regimes.length ? <p className="text-sm text-slate-500">Aún no hay personal registrado.</p> : regimes.map(([name, count]) => <div key={name}><div className="mb-2 flex justify-between gap-4 text-xs"><span className={name === SIN_DEFINIR ? 'text-amber-700' : 'text-slate-600'}>{name}</span><span className="shrink-0 font-semibold text-slate-700">{count} · {percent(count)}%</span></div><div className="h-2 overflow-hidden rounded-full bg-slate-100" aria-hidden="true"><div className="h-full rounded-full bg-violet-400" style={{ width: `${percent(count)}%` }} /></div></div>)}</div>
      </section>
      {administra && <BanderasSinResponder panel={panel} />}
    </div>
    {ready && !users.length && <p className="mt-6 rounded-xl border border-teal-100 bg-teal-50 p-4 text-sm text-teal-800">Aún no hay personal registrado. <Link to="/personal" className="font-semibold underline">Ir a registrar personal</Link></p>}
  </>
}
