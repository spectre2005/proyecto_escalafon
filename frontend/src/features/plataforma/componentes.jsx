import { fechaEnLima, fechaMaxima, vencimientoDelDia } from './plazoPassword'
import { useState } from 'react'
import Icon from '../../components/ui/Icon'
import { ESTADOS, ESTADO_TECNICO, SERVICIO, etiquetaEstado } from './estado'

export function EstadoInstitucion({ estado }) {
  return <span className={`inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 ring-inset ${ESTADOS[estado]?.clase || 'bg-slate-50 text-slate-600 ring-slate-200'}`}>{etiquetaEstado(estado)}</span>
}

/** Estado del tiempo de uso pagado (servicio.estado). */
export function EstadoServicio({ servicio }) {
  const estado = SERVICIO[servicio?.estado]
  if (!estado) return null
  return <span className={`inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 ring-inset ${estado.clase}`}>{estado.etiqueta}</span>
}

/** Respaldo al día o atrasado (respaldo.atrasado: sin un respaldo correcto reciente). Sin aprovisionar no hay qué respaldar. */
export function EstadoRespaldo({ institucion }) {
  if (!institucion.aprovisionada || !institucion.respaldo) return null
  const { atrasado, ultimo_correcto_en, nube } = institucion.respaldo
  const pendienteNube = nube && nube.estado !== 'correcta'
  return <span title={ultimo_correcto_en ? `Último respaldo correcto: ${new Date(ultimo_correcto_en).toLocaleString('es-PE')}` : 'Todavía no hay un respaldo correcto.'}
    className={`inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 ring-inset ${atrasado ? 'bg-rose-50 text-rose-700 ring-rose-200' : pendienteNube ? 'bg-amber-50 text-amber-800 ring-amber-200' : 'bg-teal-50 text-teal-800 ring-teal-200'}`}>{atrasado ? 'Sin respaldo reciente' : pendienteNube ? 'Copia en nube pendiente' : 'Respaldo al día'}</span>
}

/** Estado de una comprobación técnica (o del resumen de una institución). */
export function EstadoTecnico({ estado, texto }) {
  const valor = ESTADO_TECNICO[estado] || ESTADO_TECNICO.no_disponible
  return <span className={`inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 ring-inset ${valor.clase}`}>{texto || valor.etiqueta}</span>
}

export function SinAprovisionar({ institucion }) {
  if (institucion.aprovisionada) return null
  return <span title="Su base de datos, su almacenamiento y su configuración aún no están listos y verificados." className="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-600 ring-1 ring-inset ring-slate-200">Pendiente de aprovisionamiento</span>
}

export function Aviso({ tipo = 'error', children }) {
  if (!children) return null
  const clase = tipo === 'exito' ? 'bg-teal-50 text-teal-800' : tipo === 'info' ? 'bg-amber-50 text-amber-900' : 'bg-rose-50 text-rose-700'
  return <p role={tipo === 'error' ? 'alert' : 'status'} className={`rounded-lg p-3 text-sm ${clase}`}>{children}</p>
}

/**
 * Contraseña temporal de un administrador de la institución: solo existe en esta pantalla (no se guarda ni se audita);
 * al cerrarla ya no se puede recuperar. credenciales: { email, clave_temporal }.
 */
export function Credenciales({ titulo, credenciales, onCerrar }) {
  const [copiada, setCopiada] = useState(false)
  const [visible, setVisible] = useState(false)
  const [errorCopia, setErrorCopia] = useState('')
  async function copiar() {
    try {
      await navigator.clipboard.writeText(credenciales.clave_temporal)
      setCopiada(true)
      setErrorCopia('')
    } catch {
      setCopiada(false)
      setErrorCopia('No se pudo copiar. Muestra la contraseña y cópiala manualmente.')
    }
  }
  return <div role="status" className="overflow-hidden rounded-2xl border border-teal-200 bg-teal-50 p-5 text-sm text-teal-950">
    <div className="flex items-start gap-3"><span className="rounded-xl bg-teal-700 p-2 text-white"><Icon name="shield" /></span><div><p className="font-semibold">{titulo}</p><p className="mt-1 text-xs text-teal-800">Entrega de acceso · Esta contraseña temporal solo está disponible en este aviso.</p></div></div>
    <dl className="mt-3 grid gap-3 sm:grid-cols-2">
      <div><dt className="text-xs">Correo</dt><dd className="mt-1 break-all font-medium">{credenciales.email}</dd></div>
      <div><dt className="text-xs">Contraseña temporal</dt><dd className="mt-1 flex flex-wrap items-center gap-2"><code className="break-all rounded-lg bg-white px-2 py-1 font-mono text-slate-900">{visible ? credenciales.clave_temporal : '••••••••••••••••'}</code><button type="button" aria-pressed={visible} onClick={() => setVisible(valor => !valor)} className="rounded-lg border border-teal-200 bg-white px-2 py-1 text-xs">{visible ? 'Ocultar contraseña' : 'Mostrar contraseña'}</button><button type="button" onClick={copiar} className="rounded-lg border border-teal-200 bg-white px-2 py-1 text-xs">{copiada ? 'Copiada' : 'Copiar'}</button></dd></div>
    </dl>
    <LimitePassword cuenta={{ ...credenciales, debe_cambiar_password: true }} />
    <p className="mt-3 text-xs">Entrégala a la persona por un canal seguro. Al ingresar, el sistema le pedirá cambiarla.</p>
    {errorCopia && <p role="alert" className="mt-2 text-xs text-rose-700">{errorCopia}</p>}
    <div className="mt-3 flex justify-end"><button type="button" onClick={onCerrar} className="rounded-xl bg-teal-800 px-4 py-2 text-xs font-semibold text-white">Ya la entregué</button></div>
  </div>
}

export function ResumenCuentas({ cuentas }) {
  const indicadores = [
    { titulo: 'Listas para ingresar', valor: cuentas.filter(c => c.activo && !c.debe_cambiar_password).length, icono: 'check', clase: 'bg-teal-50 text-teal-800' },
    { titulo: 'Cambio de contraseña pendiente', valor: cuentas.filter(c => c.activo && c.debe_cambiar_password && !c.password_temporal_vencida).length, icono: 'clock', clase: 'bg-amber-50 text-amber-900' },
    { titulo: 'Temporales vencidas', valor: cuentas.filter(c => c.activo && c.password_temporal_vencida).length, icono: 'clock', clase: 'bg-rose-50 text-rose-800' },
    { titulo: 'Desactivadas', valor: cuentas.filter(c => !c.activo).length, icono: 'users', clase: 'bg-slate-100 text-slate-600' },
  ]
  return <dl aria-label="Resumen de cuentas" className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">{indicadores.map(item => <div key={item.titulo} className={`flex items-center gap-3 rounded-2xl p-4 ${item.clase}`}><Icon name={item.icono} size={22} /><div><dt className="text-xs font-medium">{item.titulo}</dt><dd className="mt-1 text-2xl font-bold">{item.valor}</dd></div></div>)}</dl>
}

export function PlazoPassword({ value, onChange, disabled }) {
  return <label className="grid gap-2 text-xs font-medium text-slate-600">Contraseña temporal válida hasta el<input type="date" required value={value?.slice(0, 10) || ''} min={fechaEnLima()} max={fechaMaxima()} onChange={event => onChange(vencimientoDelDia(event.target.value))} disabled={disabled} className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-800" /><span className="font-normal text-slate-500">Hasta las 23:59:59 del día seleccionado, hora de Lima. Puedes elegir hasta 7 días calendario. Después necesitará una contraseña temporal nueva.</span></label>
}

export function LimitePassword({ cuenta }) {
  if (!cuenta.debe_cambiar_password || !cuenta.password_temporal_vence_en) return null
  const fecha = new Date(cuenta.password_temporal_vence_en).toLocaleString('es-PE', { timeZone: 'America/Lima', dateStyle: 'medium', timeStyle: 'short' })
  return <p className={`mt-2 text-xs ${cuenta.password_temporal_vencida ? 'font-semibold text-rose-700' : 'text-amber-800'}`}>{cuenta.password_temporal_vencida ? 'Contraseña temporal vencida' : 'Cambiar contraseña antes del'} · {fecha} (hora de Lima){cuenta.password_temporal_vencida && '. Entrega una contraseña temporal nueva para recuperar el acceso.'}</p>
}
