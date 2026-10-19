import { EstadoRespaldo, EstadoTecnico } from '../componentes'
import { fecha, resumenTecnico } from '../estado'
import { idPanel, tituloPestana } from '../pestanas'
import { resumenAdministradores, resumenAuditoria, resumenRespaldo, resumenServicio } from '../resumen'

/** Un indicador: abre la pestaña de su apartado (onIr). Su nombre accesible es su texto más «Ver …». */
function Indicador({ titulo, insignia, texto, detalle, destino, onIr }) {
  return <li className="min-w-0">
    <button type="button" onClick={() => onIr(destino)} aria-controls={idPanel(destino)}
      className="h-full w-full rounded-2xl border border-slate-200 bg-white p-4 text-left hover:border-slate-300 hover:bg-slate-50">
      <span className="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500"><span>{titulo}</span>{insignia}</span>
      <span className="mt-2 block break-words text-sm font-semibold text-slate-800">{texto}</span>
      {detalle && <span className="mt-0.5 block break-words text-xs text-slate-500">{detalle}</span>}
      <span className="sr-only">. Ver {tituloPestana(destino)}</span>
    </button>
  </li>
}

/**
 * Franja de resumen del detalle de una institución. Solo informa y no pide nada al backend: usa la institución que ya
 * cargó la página, la medición de uso (useUso, la misma que muestra UsoTecnico) y la lista que ya cargó
 * AdministradoresInstitucion. Si un dato falta o no se pudo leer, lo dice. Cada indicador abre su pestaña (onIr). El
 * estado del servicio ya está como insignia en la cabecera: aquí solo su fecha.
 */
export default function ResumenInstitucion({ institucion, uso, administradores, onIr }) {
  const servicio = resumenServicio(institucion.servicio)
  const respaldo = resumenRespaldo(institucion)
  const admins = resumenAdministradores(institucion, administradores)
  const auditoria = resumenAuditoria(institucion.auditoria)
  const tecnico = uso.data ? resumenTecnico(uso.data) : null
  return <section aria-label="Resumen de la institución" className="mt-6">
    <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      <Indicador titulo="Servicio" destino="servicio" onIr={onIr} {...servicio} />
      <Indicador titulo="Último respaldo" destino="tecnico" onIr={onIr} insignia={<EstadoRespaldo institucion={institucion} />} {...respaldo} />
      <Indicador titulo="Estado técnico" destino="tecnico" onIr={onIr} insignia={tecnico && <EstadoTecnico estado={tecnico.estado} />}
        texto={uso.loading ? 'Midiendo…' : uso.error ? 'No disponible' : tecnico.texto}
        detalle={uso.data ? `Medido ${fecha(uso.data.medido_en)}` : uso.error ? 'No se pudo medir' : null} />
      <Indicador titulo="Administradores" destino="administradores" onIr={onIr} {...admins} />
      <Indicador titulo="Última operación" destino="auditoria" onIr={onIr} {...auditoria} />
    </ul>
  </section>
}
