import { apiErrorMessage } from '../axios'

// Controles comunes de las consultas bajo demanda: estado de la petición, paginación y campo con etiqueta.

const control = 'w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800'
const button = 'rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-teal-800 hover:bg-teal-50 disabled:opacity-50'

export function QueryStatus({ request }) {
  return <>{request.error && <p role="alert" className="my-3 text-sm text-rose-800">{apiErrorMessage(request.error)} <button type="button" className={button} onClick={request.retry}>Reintentar</button></p>}{request.loading && !request.data && <p role="status" className="my-3 text-sm">Cargando…</p>}</>
}

export function WorkflowPagination({ data, setPage, loading }) {
  if (!data || data.last_page <= 1) return null
  return <nav aria-label="Paginación de registros" className="my-4 flex flex-wrap items-center gap-3 text-sm"><button type="button" className={button} disabled={loading || data.current_page <= 1} onClick={() => setPage(data.current_page - 1)}>Anterior</button><span role="status">{data.current_page} / {data.last_page}</span><button type="button" className={button} disabled={loading || data.current_page >= data.last_page} onClick={() => setPage(data.current_page + 1)}>Siguiente</button></nav>
}

export function WorkflowField({ name, label, type = 'text', required = true, maxLength, defaultValue, children, ...rest }) {
  return <label className="grid gap-2 text-sm text-slate-700">{label}{children ? <select name={name} required={required} defaultValue={defaultValue} className={control} {...rest}>{children}</select> : type === 'textarea' ? <textarea name={name} required={required} maxLength={maxLength} defaultValue={defaultValue} className={control} rows={3} {...rest} /> : <input name={name} type={type} required={required} maxLength={maxLength} defaultValue={defaultValue} className={control} {...rest} />}</label>
}
