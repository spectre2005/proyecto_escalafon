import { idTipo, irA } from '../../config/espacioSeccion'
import { modalidadesDictado03, nivelesFormacion, nivelesIdioma03, resumenFormacion, tiposCapacitacion, tiposIdioma03, tiposProduccion03 } from '../../config/seccion03'

const boton = 'whitespace-nowrap rounded-xl border border-teal-200 bg-teal-50 px-3 py-2 text-xs font-semibold text-teal-800 hover:bg-teal-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700'
const fecha = valor => valor ? valor.split('-').reverse().join('/') : '—'
const texto = valor => valor === null || valor === undefined || valor === '' ? '—' : valor

function Tabla({ titulo, columnas, filas, celdas }) {
  if (!filas.length) return null
  return <div>
    <h3 className="mb-2 text-xs font-semibold text-slate-600">{titulo}</h3>
    <div className="overflow-x-auto rounded-xl border border-slate-100">
      <table className="tabla-documental w-full text-left text-sm">
        <thead className="bg-slate-50 text-xs text-slate-500"><tr>{[...columnas, 'Detalle'].map(columna => <th key={columna} scope="col" className="px-4 py-3 font-medium">{columna}</th>)}</tr></thead>
        <tbody>{filas.map(fila => <tr key={fila.id} id={`registro-${fila.id}`} tabIndex={-1} className="border-t border-slate-100">
          {celdas(fila).map((valor, indice) => indice === 0
            ? <th key={columnas[indice]} scope="row" className="min-w-48 px-4 py-3 font-medium text-slate-800">{valor}</th>
            : <td key={columnas[indice]} data-label={columnas[indice]} className="px-4 py-3 text-slate-600">{valor}</td>)}
          <td data-label="Detalle" className="px-4 py-3"><button type="button" className={boton} onClick={() => irA(idTipo(fila.item))}>Ver detalle</button></td>
        </tr>)}</tbody>
      </table>
    </div>
  </div>
}

// Lo que acreditan los documentos de la sección (antes solo en los listados genéricos de consulta). «Ver detalle» abre
// los documentos del tipo de origen.
export default function ResumenFormacion({ items }) {
  const { formacion, colegiaturas, capacitaciones, idiomas, producciones, horas } = resumenFormacion(items)
  if (!formacion.length && !colegiaturas.length && !capacitaciones.length && !idiomas.length && !producciones.length) return null
  return <section aria-labelledby="resumen-formacion" className="space-y-5 rounded-2xl border border-slate-200 bg-white p-5">
    <h2 id="resumen-formacion" className="text-sm font-semibold text-slate-800">Resumen de formación</h2>
    <Tabla titulo="Grados y títulos" filas={formacion} columnas={['Denominación', 'Nivel', 'Institución', 'Obtención', 'Registro SUNEDU']}
      celdas={f => [texto(f.denominacion), nivelesFormacion[f.nivel] || texto(f.nivel), `${texto(f.institucion)}${f.pais && f.pais !== 'Perú' ? ` (${f.pais})` : ''}${f.es_revalidado ? ' · Revalidado' : ''}`, fecha(f.fecha_obtencion), texto(f.numero_registro_sunedu)]} />
    <Tabla titulo="Colegiatura" filas={colegiaturas} columnas={['Colegio profesional', 'N.º', 'Habilitado', 'Vigente hasta']}
      celdas={c => [texto(c.colegio_profesional), texto(c.numero_colegiatura), c.habilitado ? 'Sí' : 'No', fecha(c.vigente_hasta)]} />
    <Tabla titulo={`Capacitaciones · ${horas} hora(s) registradas`} filas={capacitaciones} columnas={['Nombre', 'Tipo', 'Entidad', 'Dictado', 'Horas', 'Créditos', 'Fin']}
      celdas={c => [texto(c.nombre), tiposCapacitacion[c.tipo] || texto(c.tipo), texto(c.entidad), modalidadesDictado03[c.modalidad_dictado] || texto(c.modalidad_dictado), texto(c.horas), texto(c.creditos), fecha(c.fecha_fin)]} />
    <Tabla titulo="Idiomas y lenguas originarias" filas={idiomas} columnas={['Idioma', 'Tipo', 'Nivel', 'Acreditado por', 'Fecha']}
      celdas={i => [texto(i.idioma), tiposIdioma03[i.tipo] || texto(i.tipo), nivelesIdioma03[i.nivel] || texto(i.nivel), texto(i.institucion), fecha(i.fecha)]} />
    <Tabla titulo="Producción intelectual" filas={producciones} columnas={['Título', 'Tipo', 'Editorial / revista', 'ISBN / ISSN / DOI', 'Publicación']}
      celdas={p => [texto(p.titulo), tiposProduccion03[p.tipo] || texto(p.tipo), texto(p.editorial_revista), texto(p.identificador), fecha(p.fecha_publicacion)]} />
    <p className="text-xs text-slate-500">Datos tomados de los documentos vigentes de la sección. Los subsanados y anulados se consultan en el detalle de cada tipo.</p>
  </section>
}
