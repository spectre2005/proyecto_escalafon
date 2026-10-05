import { useEffect, useRef, useState } from 'react'
import Campo, { Formulario, Opciones } from '../seccion01/Campo'
import { hoy, useBorrador, useGuardar } from '../seccion01/useGuardar'
import { agregarDocumento03 } from '../../api/seccion03Api'
import { modalidadesPresentacion, folios } from '../../config/documentos'
import { niveles03, capacitaciones03 } from '../../config/seccion03'

export default function Documento03Form({ usuario, item, datos, onSaved, onCancel }) {
  const nombre = useRef(null)
  useEffect(() => { nombre.current?.focus() }, [])
  const [pdf, setPdf] = useState(null)
  const [errorPdf, setErrorPdf] = useState('')
  const vigentes = item.documentos.filter(doc => !doc.subsanado && doc.estado !== 'anulado')
  const [draft, set] = useBorrador({
    nombre: '', emisor: '', fecha_emision: '', folio_inicio: datos.folio_sugerido, folio_fin: datos.folio_sugerido,
    modalidad_presentacion: '', numero_registro: '', nivel: '', numero_colegiatura: '', vigente_hasta: '',
    tipo_capacitacion: '', horas: '', subsana_a_documento_id: !item.permite_multiples && vigentes.length === 1 ? vigentes[0].id : '',
  })
  const { busy, error, errors, guardar } = useGuardar(values => {
    const comunes = ['nombre', 'emisor', 'fecha_emision', 'folio_inicio', 'folio_fin', 'modalidad_presentacion', 'subsana_a_documento_id']
    const especificos = ['b', 'e'].includes(item.letra) ? ['numero_registro', 'nivel'] : item.letra === 'c' ? ['numero_colegiatura', 'vigente_hasta'] : ['f', 'g'].includes(item.letra) ? ['tipo_capacitacion', 'horas'] : []
    const payload = Object.fromEntries([...comunes, ...especificos].map(key => [key, values[key]]))
    return agregarDocumento03(usuario, { ...payload, tipo_documento_id: item.id }, pdf)
  }, onSaved)
  function seleccionar(event) {
    const archivo = event.target.files[0] || null
    setPdf(archivo)
    setErrorPdf(archivo && !archivo.name.toLowerCase().endsWith('.pdf') ? 'Selecciona un archivo PDF.' : archivo && archivo.size > datos.tamano_maximo_kb * 1024 ? `El PDF no debe superar los ${datos.tamano_maximo_kb} KB.` : '')
  }
  return <Formulario titulo="Agregar documento" guardar="Guardar documento" busy={busy} error={error} onCancel={onCancel}
    onSubmit={() => { if (pdf && !errorPdf) guardar(draft) }}
    nota="Quedará en revisión. Lo registrado no se edita ni se elimina: para corregirlo, agrega un documento nuevo que subsane al anterior.">
    <Campo label="Nombre del documento" required error={errors.nombre} className="sm:col-span-2"><input ref={nombre} required maxLength={200} value={draft.nombre} onChange={set('nombre')} /></Campo>
    <Campo label="Institución emisora" required error={errors.emisor}><input required maxLength={200} value={draft.emisor} onChange={set('emisor')} /></Campo>
    <Campo label="Fecha de emisión" required error={errors.fecha_emision}><input type="date" required max={hoy()} value={draft.fecha_emision} onChange={set('fecha_emision')} /></Campo>
    {niveles03[item.letra] && <>
      <Campo label="Nivel académico" required error={errors.nivel}><select required value={draft.nivel} onChange={set('nivel')}><Opciones lista={niveles03[item.letra]} vacio="Selecciona" /></select></Campo>
      <Campo label="N.º de registro (opcional)" error={errors.numero_registro}><input maxLength={40} value={draft.numero_registro} onChange={set('numero_registro')} /></Campo>
    </>}
    {item.letra === 'c' && <>
      <Campo label="Número de colegiatura" required error={errors.numero_colegiatura}><input required maxLength={30} value={draft.numero_colegiatura} onChange={set('numero_colegiatura')} /></Campo>
      <Campo label="Habilitación vigente hasta (si figura)" error={errors.vigente_hasta}><input type="date" min={draft.fecha_emision} value={draft.vigente_hasta} onChange={set('vigente_hasta')} /></Campo>
    </>}
    {capacitaciones03[item.letra] && <>
      <Campo label="Tipo de capacitación" required error={errors.tipo_capacitacion}><select required value={draft.tipo_capacitacion} onChange={set('tipo_capacitacion')}><Opciones lista={capacitaciones03[item.letra]} vacio="Selecciona" /></select></Campo>
      <Campo label="Horas (si figuran)" error={errors.horas}><input type="number" min="1" max="100000" value={draft.horas} onChange={set('horas')} /></Campo>
    </>}
    <Campo label="Folio inicial sugerido" required error={errors.folio_inicio}><input type="number" required min="1" value={draft.folio_inicio} onChange={set('folio_inicio')} /></Campo>
    <Campo label="Folio final" required error={errors.folio_fin}><input type="number" required min={draft.folio_inicio || 1} value={draft.folio_fin} onChange={set('folio_fin')} /></Campo>
    <Campo label="Modalidad de presentación" required error={errors.modalidad_presentacion}><select required value={draft.modalidad_presentacion} onChange={set('modalidad_presentacion')}><Opciones lista={modalidadesPresentacion} vacio="Selecciona" /></select></Campo>
    <Campo label={`Archivo PDF (máximo ${datos.tamano_maximo_kb} KB)`} required error={errorPdf || errors.pdf}><input type="file" accept="application/pdf,.pdf" required onChange={seleccionar} /></Campo>
    {vigentes.length > 0 && <Campo label="Documento que subsana" required={!item.permite_multiples} error={errors.subsana_a_documento_id} className="sm:col-span-2"><select required={!item.permite_multiples} value={draft.subsana_a_documento_id} onChange={set('subsana_a_documento_id')}>
      <option value="">{item.permite_multiples ? 'Es un documento adicional' : 'Selecciona el documento que corriges'}</option>
      {vigentes.map(doc => <option key={doc.id} value={doc.id}>{doc.nombre} · folios {folios(doc.folio_inicio, doc.folio_fin)}</option>)}
    </select></Campo>}
    {errors.tipo_documento_id && <p role="alert" className="col-span-full text-sm text-rose-700">{errors.tipo_documento_id.join(' ')}</p>}
  </Formulario>
}
