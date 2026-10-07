import { Link } from 'react-router-dom'
import Icon from '../../components/ui/Icon'
import { useUsers } from '../users/hooks/useUsers'

export default function DashboardPage() {
  const { users, loading, loadError, refreshUsers } = useUsers()
  const active = users.filter(user => user.activo).length
  const withEmail = users.filter(user => user.email_institucional?.trim()).length
  const withPhone = users.filter(user => user.telefono?.trim()).length
  const ready = !loading && !loadError
  const show = value => loading ? '…' : loadError ? '—' : value.toLocaleString('es')
  const percent = count => ready && users.length ? Math.round(count / users.length * 100) : 0
  const date = new Intl.DateTimeFormat('es-PE', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'America/Lima' }).format(new Date())
  const stats = [
    { label: 'Personal registrado', value: users.length, detail: 'Ver directorio completo', icon: 'users', color: 'bg-teal-50 text-teal-700', to: '/personal' },
    { label: 'Usuarios activos', value: active, detail: 'Consultar personal activo', icon: 'check', color: 'bg-sky-50 text-sky-700', to: '/personal?estado=activo' },
    { label: 'Usuarios inactivos', value: users.length - active, detail: 'Consultar personal inactivo', icon: 'clock', color: 'bg-amber-50 text-amber-700', to: '/personal?estado=inactivo' },
    { label: 'Con correo registrado', value: withEmail, detail: 'Ver personal con correo', icon: 'file', color: 'bg-violet-50 text-violet-700', to: '/personal?correo=con' },
  ]
  const types = [
    { label: 'Docentes', count: users.filter(user => user.tipo_personal === 'docente').length, to: '/personal?tipo=docente', color: 'bg-teal-500' },
    { label: 'Administrativos', count: users.filter(user => user.tipo_personal === 'administrativo').length, to: '/personal?tipo=administrativo', color: 'bg-sky-500' },
    { label: 'Sin clasificación', count: users.filter(user => !['docente', 'administrativo'].includes(user.tipo_personal)).length, color: 'bg-slate-400' },
  ]
  const regimes = Object.entries(users.reduce((counts, user) => {
    const name = user.regimen_laboral?.trim() || 'Sin régimen registrado'
    counts[name] = (counts[name] || 0) + 1
    return counts
  }, {})).sort((a, b) => b[1] - a[1])
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
        <h2 className="text-sm font-semibold text-slate-800">Composición del personal</h2><p className="mt-1 text-xs text-slate-500">Distribución por función en la institución</p>
        <div className="mt-6 space-y-5">{types.map(item => <div key={item.label}>
          <div className="mb-2 flex justify-between text-xs">{item.to ? <Link to={item.to} className="font-medium text-slate-600 hover:text-teal-700 hover:underline">{item.label}</Link> : <span className="text-slate-600">{item.label}</span>}<span className="font-semibold text-slate-700">{show(item.count)} · {ready ? `${percent(item.count)}%` : '—'}</span></div>
          <div className="h-3 overflow-hidden rounded-full bg-slate-100" aria-hidden="true"><div className={`h-full rounded-full ${item.color}`} style={{ width: `${percent(item.count)}%` }} /></div>
        </div>)}</div>
      </section>
      <section className={panel} aria-busy={loading}>
        <h2 className="text-sm font-semibold text-slate-800">Regímenes laborales</h2><p className="mt-1 text-xs text-slate-500">Cantidad de personal por régimen registrado</p>
        <div className="mt-5 max-h-72 space-y-4 overflow-y-auto">{!ready ? <p className="text-sm text-slate-500">{loading ? 'Consultando registros…' : 'Datos no disponibles'}</p> : !regimes.length ? <p className="text-sm text-slate-500">Aún no hay regímenes registrados.</p> : regimes.map(([name, count]) => <div key={name}><div className="mb-2 flex justify-between gap-4 text-xs"><span className="text-slate-600">{name}</span><span className="shrink-0 font-semibold text-slate-700">{count} · {percent(count)}%</span></div><div className="h-2 overflow-hidden rounded-full bg-slate-100" aria-hidden="true"><div className="h-full rounded-full bg-violet-400" style={{ width: `${percent(count)}%` }} /></div></div>)}</div>
      </section>
      <section className={`${panel} lg:col-span-2`} aria-busy={loading}>
        <div className="flex flex-wrap items-center justify-between gap-3"><div><h2 className="text-sm font-semibold text-slate-800">Cobertura de contacto</h2><p className="mt-1 text-xs text-slate-500">Disponibilidad de información para contactar al personal</p></div><Link to="/personal" className="text-xs font-semibold text-teal-700 hover:underline">Consultar directorio →</Link></div>
        <div className="mt-5 grid gap-4 sm:grid-cols-3">
          {[{ label: 'Correo institucional', count: withEmail, to: '/personal?correo=con', detail: 'Personal con correo registrado' }, { label: 'Teléfono registrado', count: withPhone, detail: 'Personal con número de contacto' }, { label: 'Sin correo institucional', count: users.length - withEmail, to: '/personal?correo=sin', detail: 'Consultar registros sin correo' }].map(item => {
            const Tag = item.to ? Link : 'div'
            return <Tag key={item.label} {...(item.to ? { to: item.to } : {})} className={`rounded-xl bg-slate-50 p-5 ${item.to ? 'hover:bg-teal-50 focus-visible:outline-teal-600' : ''}`}><p className="text-xs font-medium text-slate-600">{item.label}</p><p className="mt-3 text-2xl font-semibold text-slate-800">{show(item.count)} <span className="text-sm font-normal text-slate-500">{ready ? `${percent(item.count)}%` : '—'}</span></p><p className="mt-2 text-[11px] text-slate-500">{item.detail}</p></Tag>
          })}
        </div>
      </section>
    </div>
    {ready && !users.length && <p className="mt-6 rounded-xl border border-teal-100 bg-teal-50 p-4 text-sm text-teal-800">Aún no hay personal registrado. <Link to="/personal" className="font-semibold underline">Ir a registrar personal</Link></p>}
  </>
}
