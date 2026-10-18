import DocumentoVinculado from './DocumentoVinculado'
import Icon from '../../../../components/ui/Icon'
import { folios } from '../../config/documentos'
import { buscarDocumento, documentosSinAsociar, idBloque, idTipo, irA } from '../../config/espacioSeccion'

/*
 * Organización común de las secciones con registros y documentos (01, 02, 05, 07, 09 y 11):
 *
 *   Cabecera     sección › subsección
 *   Atajos       Registros · Documentos · Sin registro asociado (saltan a cada bloque)
 *   Formularios  registrar, completar datos y enlazar en modales, sin desplazar la página
 *   Registros    cada registro con su documento, o «Adjuntar documento» si no tiene
 *   Documentos   primero los que quedaron sin registro; luego los tipos documentales
 *
 * Los formularios comparten el modal y conservan las operaciones de cada sección.
 */

/**
 * seccion: «07 · Evaluación…»; subseccion: título de lo que se está viendo. subsecciones: [{ codigo, titulo, estado,
 * aplica, activa, onIr }] para cambiar sin volver al menú.
 * atajos: [{ id, texto }] a los bloques de la página.
 */
export default function EspacioSeccion({ seccion, subseccion, atajos = [], clasica = false, children }) {
  return <section aria-label={subseccion || seccion}>
      <header className={`mb-5 rounded-2xl border border-slate-200 bg-white p-5 sm:p-6 ${clasica ? '' : 'relative overflow-hidden before:absolute before:inset-y-0 before:left-0 before:w-1 before:bg-teal-700'}`}>
        {clasica ? <><p className="text-xs font-medium text-teal-700">{seccion}</p><h2 className="mt-2 text-xl font-semibold tracking-tight text-slate-800">{subseccion || seccion}</h2></> : <div className="flex flex-wrap items-center justify-between gap-5">
          <div className="flex min-w-0 flex-1 basis-64 items-center gap-4">
            <span className="grid size-14 shrink-0 place-items-center rounded-2xl bg-teal-50 text-teal-700"><Icon name={({ '01': 'user', '02': 'building', '03': 'file', '04': 'folder', '05': 'clock', '06': 'chart', '07': 'check', '08': 'shield', '09': 'users', '11': 'logout' })[seccion.slice(0, 2)] || 'folder'} size={27} /></span>
            <div className="min-w-0"><p className="text-xs font-medium leading-relaxed text-slate-500">{seccion}</p><h2 className="mt-1 text-xl font-bold tracking-tight text-slate-800 sm:text-2xl">{subseccion || seccion}</h2></div>
          </div>
          <nav aria-label="En esta página" className="flex flex-wrap gap-2">{(atajos.length ? atajos : [{ texto: 'Documentos' }]).map(atajo => <button key={atajo.id || 'documentos'} type="button" onClick={event => atajo.id ? irA(atajo.id) : event.currentTarget.closest('section').querySelector('[role="tablist"]')?.scrollIntoView({ behavior: 'smooth', block: 'start' })} className={`inline-flex items-center gap-2 rounded-xl border px-3 py-2.5 text-xs font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 ${atajo.alerta ? 'border-slate-200 bg-slate-50 text-slate-600' : 'border-slate-200 bg-white text-slate-700 hover:border-teal-200 hover:bg-teal-50 hover:text-teal-800'}`}>
            <Icon name={atajo.id === 'bloque-registros' ? 'folder' : atajo.alerta ? 'paperclip' : 'file'} size={18} />{atajo.texto.split(' (')[0]}
          </button>)}</nav>
        </div>}
        {clasica && atajos.length > 0 && <nav aria-label="En esta página" className="mt-3 flex flex-wrap gap-x-4 gap-y-1 border-t border-slate-100 pt-3 text-xs">
          <span className="text-slate-400">En esta página:</span>
          {atajos.map(atajo => <button key={atajo.id} type="button" onClick={() => irA(atajo.id)} className={`font-semibold hover:underline ${atajo.alerta ? 'text-slate-600' : 'text-teal-700'}`}>{atajo.texto}</button>)}
        </nav>}
      </header>
      {children}
    </section>
}

// Los formularios administran su propio modal; este contenedor conserva los pasos y sus estados en el lugar de origen.
export function EnZona({ children }) {
  return children
}

const nombreTipo = tipo => `${tipo.literal ? `${tipo.literal}) ` : ''}${tipo.nombre}`

/** Documento que acredita un registro, dentro de su tarjeta: cuál es, sus folios y su estado, con acceso a su tipo.
 * Va en la misma línea que «Adjuntar documento» (LineaDocumento). */
export function DocumentoDelRegistro(props) {
  return <DocumentoVinculado {...props} />
}

/**
 * Bloque «Sin registro asociado» al inicio de Documentos: cada uno con «Enlazar» (onEnlazar, el mismo paso que «Enlazar
 * ahora» en la lista) o, si el panel no lo ofrece aquí, con acceso a su tipo.
 */
export function SinAsociar({ tipos, onEnlazar }) {
  const pendientes = documentosSinAsociar(tipos)
  if (!pendientes.length) return null
  return <section id={idBloque.sinAsociar} aria-label="Documentos sin registro asociado" className="mb-4 scroll-mt-4 rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
    <h4 className="text-sm font-semibold text-slate-700">Sin registro asociado ({pendientes.length})</h4>
    <p className="mt-1 text-xs text-slate-700/80">Se cargaron sin registrar sus datos. Enlázalos a un registro existente o registra sus datos.</p>
    <ul className="mt-3 divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">{pendientes.map(({ tipo, documento }) => <li key={documento.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-xs">
      <span className="min-w-0 text-slate-700">{nombreTipo(tipo)} · folios {folios(documento.values.folio_inicio, documento.values.folio_fin)}{documento.values.fecha_emision ? ` · emitido ${documento.values.fecha_emision.split('-').reverse().join('/')}` : ''}</span>
      <span className="flex gap-3">
        {onEnlazar && <button type="button" onClick={() => onEnlazar(tipo, documento)} className="font-semibold text-teal-700 hover:underline">Enlazar o registrar datos</button>}
        <button type="button" onClick={() => irA(idTipo(tipo.id))} className="font-semibold text-slate-600 hover:underline">Ver en su tipo</button>
      </span>
    </li>)}</ul>
  </section>
}


/**
 * Documento de un registro que guarda documento_id (declaraciones, movimientos, controversias…), buscado en los tipos
 * de la página. Los registros sin ese campo (p. ej. familiares, que se acreditan por familiar) no muestran nada.
 */
export function DocumentoDeRegistro({ tipos, record, compacto = false }) {
  if (!('documento_id' in (record.values || {}))) return null
  return <DocumentoDelRegistro compacto={compacto} encontrado={buscarDocumento(tipos, record.values.documento_id)} enlazado={!!record.values.documento_id} />
}

/** Línea de la tarjeta de un registro: su documento (o «Sin documento») y, al lado, «Adjuntar documento» si corresponde. */
export function LineaDocumento({ documento, adjuntar }) {
  if (!documento && !adjuntar) return null
  return <div className="mt-4 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 [&>div]:mt-0">{documento}{adjuntar}</div>
}
