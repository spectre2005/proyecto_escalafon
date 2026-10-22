import { useState } from 'react'
import api, { apiErrorMessage } from '../../../axios'
import { usePuede } from '../../auth/session'
import { clavePendiente, lugarPendiente, motivoSinRespuesta, preguntasPerfil, solicitudRespuesta } from '../config/perfilPendiente'

// «Completa el perfil»: condiciones Sin responder de la persona y de sus vínculos vigentes (condiciones.pendientes del
// backend), con Sí y No para responderlas aquí mismo. Cada respuesta va a su registro con los endpoints de siempre; al
// guardarse, la ficha se actualiza sola (las escrituras invalidan las consultas) y la condición sale del bloque. Quien no
// sabe la respuesta la deja como está: Sin responder sigue siendo válido.
// plegado: dentro de una sección del legajo se muestra en una línea y se despliega al pedirlo (no empuja la sección).
export default function CompletaPerfil({ usuario, pendientes, legajoActivo, plegado = false }) {
  const permisos = { editar: usePuede('legajo.editar'), sensibles: usePuede('datos_sensibles.ver'), discapacidad: usePuede('discapacidad.editar'), legajoActivo }
  const [guardando, setGuardando] = useState(null)
  const [errores, setErrores] = useState({})
  const [aviso, setAviso] = useState('')
  if (!pendientes?.length) return aviso ? <p role="status" className="mb-5 rounded-xl bg-teal-50 p-3 text-sm text-teal-800">{aviso} El perfil no tiene condiciones pendientes.</p> : null

  async function responder(pendiente, valor) {
    const clave = clavePendiente(pendiente)
    if (guardando) return
    setGuardando(clave)
    setAviso('')
    setErrores(previos => ({ ...previos, [clave]: '' }))
    try {
      const { url, data } = solicitudRespuesta(usuario, pendiente, valor)
      await api.patch(url, data)
      setAviso(`Guardado: «${preguntasPerfil[pendiente.bandera]}» ${valor ? 'Sí' : 'No'}.`)
    } catch (failure) {
      setErrores(previos => ({ ...previos, [clave]: apiErrorMessage(failure) }))
    } finally {
      setGuardando(null)
    }
  }

  const contenido = <>
    <div className="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 id="completa-perfil-titulo" className="text-sm font-semibold text-slate-800">Completa el perfil <span className="ml-1 rounded-full bg-slate-50 px-2 py-0.5 text-[11px] font-medium text-slate-600">{pendientes.length} sin responder</span></h2>
        <p className="mt-1 text-xs text-slate-500">Mientras una condición esté sin responder, sus documentos quedan por determinar. Si aún no sabes la respuesta, déjala como está.</p>
      </div>
    </div>
    {aviso && <p role="status" className="mt-3 rounded-lg bg-teal-50 p-2.5 text-xs text-teal-800">{aviso}</p>}
    <ul className="mt-4 divide-y divide-slate-100 rounded-xl border border-slate-100">
      {pendientes.map(pendiente => {
        const clave = clavePendiente(pendiente)
        const motivo = motivoSinRespuesta(pendiente, permisos)
        const ocupado = guardando === clave
        return <li key={clave} id={`perfil-pendiente-${pendiente.bandera}-${pendiente.vinculo_id || 'persona'}`} tabIndex={-1} className="scroll-mt-6 focus:outline-2 focus:outline-teal-600 target:bg-teal-50 flex flex-wrap items-center justify-between gap-3 px-4 py-3">
          <div className="min-w-0">
            <p className="text-sm text-slate-700">{preguntasPerfil[pendiente.bandera] || pendiente.bandera}</p>
            <p className="mt-0.5 text-[11px] text-slate-500">Se guarda en: {lugarPendiente(pendiente)}{motivo ? ` · ${motivo}` : ''}</p>
            {errores[clave] && <p role="alert" className="mt-1 text-xs text-rose-700">No se guardó: {errores[clave]}</p>}
          </div>
          {!motivo && <div className="flex shrink-0 gap-2" role="group" aria-label={`Responder: ${preguntasPerfil[pendiente.bandera]} (${lugarPendiente(pendiente)})`}>
            {[[true, 'Sí'], [false, 'No']].map(([valor, texto]) => <button key={texto} type="button" disabled={!!guardando} onClick={() => responder(pendiente, valor)}
              className="rounded-lg border border-slate-200 px-3.5 py-1.5 text-xs font-semibold text-slate-700 hover:border-teal-300 hover:bg-teal-50 disabled:opacity-50">{ocupado ? 'Guardando…' : texto}</button>)}
          </div>}
        </li>
      })}
    </ul>
  </>
  if (plegado) return <details className="mb-4 rounded-2xl border border-slate-200 bg-white px-5 py-3">
    <summary className="cursor-pointer text-sm text-slate-700"><strong className="font-semibold text-slate-800">Completa el perfil</strong> <span className="ml-1 rounded-full bg-slate-50 px-2 py-0.5 text-[11px] font-medium text-slate-600">{pendientes.length} sin responder</span> <span className="ml-2 text-xs font-semibold text-teal-700">Responder</span></summary>
    <div className="mt-3">{contenido}</div>
  </details>
  return <section aria-labelledby="completa-perfil-titulo" className="mb-6 rounded-2xl border border-slate-200 bg-white p-5">{contenido}</section>
}
