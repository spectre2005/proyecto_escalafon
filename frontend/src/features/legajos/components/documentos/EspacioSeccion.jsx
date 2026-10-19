import { createContext, useContext } from 'react'
import DocumentoModal from './DocumentoModal'
import { etiqueta } from '../../config/seccion01'
import { coloresEstadoDocumento, estadosDocumento, estadosSubseccion } from '../../config/seccion02'
import { folios } from '../../config/documentos'
import { buscarDocumento, documentosSinAsociar, idBloque, idTipo, irA } from '../../config/espacioSeccion'

/*
 * Organización común de las secciones con registros y documentos (01, 02, 05, 07, 09 y 11):
 *
 *   Cabecera     sección › subsección, cambio de subsección y cifras en una línea (el resumen completo, plegado)
 *   Atajos       Registros · Documentos · Sin registro asociado (saltan a cada bloque)
 *   Formularios  registrar, corregir y enlazar en ventanas superpuestas, conservando la posición de la página
 *   Registros    cada registro con su documento, o «Adjuntar documento» si no tiene
 *   Documentos   primero los que quedaron sin registro; luego los tipos documentales
 *
 * No cambia ninguna operación: solo dónde se muestran. Lo que no está dentro de EspacioSeccion se ve en su lugar.
 */

const Contexto = createContext(null)

function Chip({ activo, children, onClick }) {
  return <button type="button" aria-current={activo ? 'page' : undefined} onClick={onClick}
    className={`inline-flex items-center gap-2 rounded-lg border px-2.5 py-1.5 text-xs ${activo ? 'border-teal-300 bg-teal-50 font-semibold text-teal-800' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'}`}>{children}</button>
}

/**
 * seccion: «07 · Evaluación…»; subseccion: título de lo que se está viendo. subsecciones: [{ codigo, titulo, estado,
 * aplica, activa, onIr }] para cambiar sin volver al menú. cifras: texto corto. resumen: el resumen completo (plegado).
 * atajos: [{ id, texto }] a los bloques de la página.
 */
export default function EspacioSeccion({ seccion, subseccion, subsecciones = [], cifras, resumen, etiquetaResumen = 'Ver resumen de la sección', atajos = [], children }) {
  return <Contexto.Provider value={{ contexto: subseccion ? `${seccion} › ${subseccion}` : seccion }}>
    <section aria-label={subseccion || seccion}>
      <header className="mb-4 rounded-2xl border border-slate-200 bg-white p-4">
        <p className="text-[11px] text-slate-500">{seccion}</p>
        <h2 className="mt-0.5 text-lg font-semibold text-slate-800">{subseccion || seccion}</h2>
        {subsecciones.length > 0 && <nav aria-label="Subsecciones" className="mt-3 flex flex-wrap gap-2">{subsecciones.map(sub => {
          const [nombre, clase] = estadosSubseccion[sub.estado] || []
          return <Chip key={sub.codigo} activo={sub.activa} onClick={sub.onIr}>
            <span className={sub.aplica === false ? 'text-slate-400' : ''}>{sub.titulo}</span>
            {nombre && <span className={`rounded-full px-1.5 py-0.5 text-[10px] ${clase}`}>{nombre}</span>}
          </Chip>
        })}</nav>}
        {(cifras || resumen) && <details className="mt-3 text-xs text-slate-600">
          <summary className="cursor-pointer"><span>{cifras}</span>{resumen && <span className={`${cifras ? 'ml-2 ' : ''}font-semibold text-teal-700`}>{etiquetaResumen}</span>}</summary>
          {resumen && <div className="mt-3">{resumen}</div>}
        </details>}
        {atajos.length > 0 && <nav aria-label="En esta página" className="mt-3 flex flex-wrap gap-x-4 gap-y-1 border-t border-slate-100 pt-3 text-xs">
          <span className="text-slate-400">En esta página:</span>
          {atajos.map(atajo => <button key={atajo.id} type="button" onClick={() => irA(atajo.id)} className={`font-semibold hover:underline ${atajo.alerta ? 'text-amber-800' : 'text-teal-700'}`}>{atajo.texto}</button>)}
        </nav>}
      </header>
      {children}
    </section>
  </Contexto.Provider>
}

/** Paso compartido de registro, corrección o enlace, sin desplazar la sección. */
export function EnZona({ titulo, onCancel, children }) {
  const espacio = useContext(Contexto)
  return <DocumentoModal titulo={titulo || 'Registrar datos'} onCancel={onCancel}>
    {espacio && <p className="mb-3 text-xs text-slate-600">{espacio.contexto}</p>}
    {children}
  </DocumentoModal>
}

const nombreTipo = tipo => `${tipo.literal ? `${tipo.literal}) ` : ''}${tipo.nombre}`

/** Documento que acredita un registro, dentro de su tarjeta: cuál es, sus folios y su estado, con acceso a su tipo.
 * Va en la misma línea que «Adjuntar documento» (LineaDocumento). */
export function DocumentoDelRegistro({ encontrado, enlazado }) {
  if (!encontrado) {
    return <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">{enlazado ? 'Documento enlazado (no está en la lista de esta subsección).' : 'Sin documento enlazado.'}</p>
  }
  const { tipo, documento } = encontrado
  const v = documento.values
  return <p className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
    <span>Documento: <strong className="text-slate-800">{nombreTipo(tipo)}</strong> · folios {folios(v.folio_inicio, v.folio_fin)}</span>
    <span className={`rounded-full px-2 py-0.5 text-[10px] font-medium ${coloresEstadoDocumento[documento.estado_visible] || 'bg-slate-100 text-slate-700'}`}>{etiqueta(estadosDocumento, documento.estado_visible || v.estado)}</span>
    <button type="button" onClick={() => irA(idTipo(tipo.id))} className="font-semibold text-teal-700 hover:underline">Ver en Documentos</button>
  </p>
}

/**
 * Bloque «Sin registro asociado» al inicio de Documentos: cada uno con «Enlazar» (onEnlazar, el mismo paso que «Enlazar
 * ahora» en la lista) o, si el panel no lo ofrece aquí, con acceso a su tipo.
 */
export function SinAsociar({ tipos, onEnlazar }) {
  const pendientes = documentosSinAsociar(tipos)
  if (!pendientes.length) return null
  return <section id={idBloque.sinAsociar} aria-label="Documentos sin registro asociado" className="mb-4 scroll-mt-4 rounded-2xl border border-amber-200 bg-amber-50/60 p-4">
    <h4 className="text-sm font-semibold text-amber-900">Sin registro asociado ({pendientes.length})</h4>
    <p className="mt-1 text-xs text-amber-900/80">Se cargaron sin registrar sus datos. Enlázalos a un registro existente o registra sus datos.</p>
    <ul className="mt-3 divide-y divide-amber-100 rounded-xl border border-amber-100 bg-white">{pendientes.map(({ tipo, documento }) => <li key={documento.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-xs">
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
export function DocumentoDeRegistro({ tipos, record }) {
  if (!('documento_id' in (record.values || {}))) return null
  return <DocumentoDelRegistro encontrado={buscarDocumento(tipos, record.values.documento_id)} enlazado={!!record.values.documento_id} />
}

/** Línea de la tarjeta de un registro: su documento (o «Sin documento») y, al lado, «Adjuntar documento» si corresponde. */
export function LineaDocumento({ documento, adjuntar }) {
  if (!documento && !adjuntar) return null
  return <div className="mt-4 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 [&>div]:mt-0">{documento}{adjuntar}</div>
}
