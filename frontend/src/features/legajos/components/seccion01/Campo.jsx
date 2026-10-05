import { Children, cloneElement } from 'react'

const control = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-800 outline-none focus:ring-2 focus:ring-teal-600 disabled:bg-slate-50 disabled:text-slate-400'

// Etiqueta + control + mensaje de error del backend. El control recibe el estilo del panel.
export default function Campo({ label, required, error, className = '', children }) {
  const child = Children.only(children)
  return <label className={`grid content-start gap-2 text-xs font-medium text-slate-600 ${className}`}>
    {label}{required ? ' *' : ''}
    {cloneElement(child, { className: `${control} ${child.props.className || ''}`, 'aria-invalid': !!error })}
    {error && <span className="text-rose-700">{[].concat(error).join(' ')}</span>}
  </label>
}

// Opciones de una lista cerrada. Si el valor guardado no está en la lista (dato anterior), se conserva.
export function Opciones({ lista, actual, vacio }) {
  return <>
    {vacio !== undefined && <option value="">{vacio}</option>}
    {actual && !(actual in lista) && <option value={actual}>{actual} (valor anterior)</option>}
    {Object.entries(lista).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
  </>
}

// PDF ya registrado en un formulario de edición: se conserva y no se reemplaza (Directiva, arts. 2.3 y 5.3).
export function PdfRegistrado({ nombre, error }) {
  return <div className="col-span-full rounded-xl bg-slate-50 p-3 text-xs text-slate-600">
    <p className="break-words">PDF registrado: {nombre}</p>
    <p className="mt-1">Un PDF registrado no se reemplaza ni se elimina. Si es incorrecto, el registro se anula con motivo y se registra uno nuevo.</p>
    {error && <p role="alert" className="mt-2 text-rose-700">{[].concat(error).join(' ')}</p>}
  </div>
}

// Contenedor común de los formularios de la sección 01.
export function Formulario({ titulo, nota, busy, error, onSubmit, onCancel, guardar, children }) {
  return <form onSubmit={event => { event.preventDefault(); onSubmit() }} className="mb-5 rounded-2xl border border-teal-200 bg-white p-5" aria-busy={busy}>
    <h3 className="mb-5 font-semibold text-slate-800">{titulo}</h3>
    {error && <p role="alert" className="mb-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
    <fieldset disabled={busy} className="grid gap-4 sm:grid-cols-2">
      {children}
      <p className="col-span-full text-xs text-slate-500">{nota || 'El responsable del registro se guarda automáticamente con tu sesión.'}</p>
      <div className="col-span-full flex justify-end gap-3 pt-2"><button type="button" onClick={onCancel} className="rounded-xl border border-slate-200 px-4 py-2 text-sm">Cancelar</button><button className="rounded-xl bg-teal-700 px-4 py-2 text-sm font-semibold text-white">{busy ? 'Guardando…' : guardar}</button></div>
    </fieldset>
  </form>
}
