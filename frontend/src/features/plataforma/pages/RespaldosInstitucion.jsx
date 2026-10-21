import { useId, useState } from 'react'
import { EstadoRespaldo } from '../componentes'
import { COPIA_EXTERNA, VERIFICACION, fecha, tamano } from '../estado'
import { LIMITES, MAXIMOS_BACKEND, acotar, textoConteo } from '../historial'
import { Seccion, VerTodos } from '../ui'

/**
 * Últimos respaldos de la institución (docker/produccion/respaldar.sh): solo metadatos, el contenido no pasa por el panel.
 * Llegan los más recientes primero; se ven los primeros LIMITES.respaldos y «Ver todos» muestra el resto de los recibidos.
 */
export default function RespaldosInstitucion({ institucion }) {
  const id = useId()
  const [expandido, setExpandido] = useState(false)
  const lista = institucion.respaldos || []
  const resumen = institucion.respaldo
  const nube = resumen?.nube
  const ultimo = resumen?.ultimo
  const estadoNube = { sin_respaldos: 'Sin copias registradas', no_configurada: 'Sin copia externa configurada', fallida: 'La última copia falló', atrasada: 'Sin copia reciente', correcta: 'Copia cifrada comprobada' }[nube?.estado] || 'Sin comprobar'
  const { visibles, total, hayMas } = acotar(lista, LIMITES.respaldos, expandido)
  return <Seccion titulo="Respaldos" acciones={<EstadoRespaldo institucion={institucion} />}>
    <div className="mt-4 grid gap-3 sm:grid-cols-3">
      <div className="rounded-xl border border-slate-200 bg-slate-50 p-4"><p className="text-xs text-slate-500">Respaldo local</p><p className="mt-1 text-sm font-semibold text-slate-800">{resumen?.ultimo_correcto_en ? 'Última copia correcta' : 'Sin copia correcta'}</p>{resumen?.ultimo_correcto_en && <p className="mt-2 text-xs text-slate-500">{fecha(resumen.ultimo_correcto_en)}</p>}</div>
      <div className={`rounded-xl border p-4 ${nube?.estado === 'correcta' ? 'border-teal-200 bg-teal-50' : 'border-amber-200 bg-amber-50'}`}><p className="text-xs text-slate-600">Copia en la nube</p><p className="mt-1 text-sm font-semibold text-slate-800">{estadoNube}</p>{nube?.ultimo_correcto_en && <p className="mt-2 text-xs text-slate-600">Última correcta: {fecha(nube.ultimo_correcto_en)}</p>}</div>
      <div className="rounded-xl border border-slate-200 bg-slate-50 p-4"><p className="text-xs text-slate-500">Restauración del último respaldo</p><p className="mt-1 text-sm font-semibold text-slate-800">{ultimo?.verificacion ? VERIFICACION[ultimo.verificacion]?.etiqueta : 'Pendiente de prueba'}</p>{ultimo?.verificado_en && <p className="mt-2 text-xs text-slate-500">{fecha(ultimo.verificado_en)}</p>}</div>
    </div>
    <p className="mt-3 text-xs leading-relaxed text-slate-500">El respaldo incluye base de datos y documentos privados. La copia externa se cifra antes de guardarse y se comprueba después de subirla.</p>
    {nube?.estado !== 'correcta' && <details className="mt-4 rounded-xl border border-slate-200 p-4 text-sm"><summary className="cursor-pointer font-semibold text-teal-800">Conectar los respaldos con Google Drive</summary><p className="mt-3 text-xs leading-relaxed text-slate-600">La persona responsable del servidor debe ejecutar el asistente y autorizar la cuenta de Google. Luego debe programar los respaldos y comprobar una restauración.</p><code className="mt-3 block overflow-x-auto rounded-lg bg-slate-50 p-3 text-xs">sh docker/produccion/conectar-drive.sh</code><p className="mt-3 text-xs text-slate-500">La nube se mostrará como comprobada después de que termine correctamente un respaldo con copia externa.</p></details>}
    {lista.length === 0 ? <p className="mt-4 text-sm text-slate-500">Todavía no hay respaldos registrados. Configura el destino y la programación automática en el servidor.</p> : <ol id={id} className="mt-3 divide-y divide-slate-100">
      {visibles.map(respaldo => <li key={respaldo.id} className="grid gap-1 py-3 text-sm">
        <div className="flex flex-wrap justify-between gap-2">
          <span><strong className={respaldo.estado === 'correcto' ? 'text-slate-700' : 'text-rose-700'}>{respaldo.estado === 'correcto' ? 'Correcto' : 'Fallido'}</strong>
            <span className="text-slate-500"> · base {tamano(respaldo.base_bytes)}{respaldo.archivos_bytes !== null && ` · archivos ${tamano(respaldo.archivos_bytes)}`}</span></span>
          <span className="text-xs text-slate-500">{fecha(respaldo.created_at)}</span>
        </div>
        <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs">
          <span className={COPIA_EXTERNA[respaldo.copia_externa]?.clase}>{COPIA_EXTERNA[respaldo.copia_externa]?.etiqueta || respaldo.copia_externa}</span>
          {respaldo.verificacion && <span className={VERIFICACION[respaldo.verificacion]?.clase}>{VERIFICACION[respaldo.verificacion]?.etiqueta} {fecha(respaldo.verificado_en)}</span>}
        </div>
        {respaldo.detalle && <p className="text-xs text-rose-700">{respaldo.detalle}</p>}
        {respaldo.verificacion === 'fallida' && respaldo.detalle_verificacion && <p className="text-xs text-rose-700">{respaldo.detalle_verificacion}</p>}
      </li>)}
    </ol>}
    <VerTodos hayMas={hayMas} expandido={expandido} onCambiar={setExpandido} total={total} controla={id}
      conteo={textoConteo({ visibles: visibles.length, total, nombre: 'respaldos', maximo: MAXIMOS_BACKEND.respaldos })} />
  </Seccion>
}
