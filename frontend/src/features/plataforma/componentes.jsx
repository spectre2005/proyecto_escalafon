import { useState } from 'react'
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
  const { atrasado, ultimo_correcto_en } = institucion.respaldo
  return <span title={ultimo_correcto_en ? `Último respaldo correcto: ${new Date(ultimo_correcto_en).toLocaleString('es-PE')}` : 'Todavía no hay un respaldo correcto.'}
    className={`inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 ring-inset ${atrasado ? 'bg-rose-50 text-rose-700 ring-rose-200' : 'bg-teal-50 text-teal-800 ring-teal-200'}`}>{atrasado ? 'Sin respaldo reciente' : 'Respaldo al día'}</span>
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
  async function copiar() {
    try {
      await navigator.clipboard.writeText(credenciales.clave_temporal)
      setCopiada(true)
    } catch {
      setCopiada(false)
    }
  }
  return <div role="alert" className="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
    <p className="font-semibold">{titulo} Esta contraseña temporal se muestra solo esta vez.</p>
    <dl className="mt-3 grid gap-3 sm:grid-cols-2">
      <div><dt className="text-xs">Correo</dt><dd className="mt-1 break-all font-medium">{credenciales.email}</dd></div>
      <div><dt className="text-xs">Contraseña temporal</dt><dd className="mt-1 flex items-center gap-2"><code className="rounded-lg bg-white px-2 py-1 font-mono text-slate-900">{credenciales.clave_temporal}</code><button type="button" onClick={copiar} className="rounded-lg border border-amber-300 bg-white px-2 py-1 text-xs">{copiada ? 'Copiada' : 'Copiar'}</button></dd></div>
    </dl>
    <p className="mt-3 text-xs">Entrégala a la persona por un canal seguro. Al ingresar, el sistema le pedirá cambiarla.</p>
    <div className="mt-3 flex justify-end"><button type="button" onClick={onCerrar} className="rounded-xl bg-amber-900 px-4 py-2 text-xs font-semibold text-white">Ya la entregué</button></div>
  </div>
}
