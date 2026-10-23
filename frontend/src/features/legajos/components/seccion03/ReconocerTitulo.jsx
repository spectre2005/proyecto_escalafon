import { useEffect, useId, useRef, useState } from 'react'
import api, { apiErrorMessage } from '../../../../axios'
import PdfPreviewField from '../../../../components/pdf/PdfPreviewField'
import DocumentoModal from '../documentos/DocumentoModal'
import { camposSeleccionados, seleccionInicial } from '../../config/reconocimientoTitulo'
import { niveles03 } from '../../config/seccion03'

const etiquetasTitulo = { titular_nombre: 'Titular leído (solo contraste)', nombre: 'Título o grado', emisor: 'Institución emisora', nivel: 'Nivel académico sugerido', pais: 'País de la institución', fecha_emision: 'Fecha de expedición', fecha_obtencion: 'Fecha de obtención', numero_diploma: 'Número de diploma', numero_registro: 'Registro SUNEDU / MINEDU' }
const boton = 'rounded-xl border border-teal-200 bg-white px-3 py-2 text-xs font-semibold text-teal-800 hover:bg-teal-50 disabled:opacity-50'

export default function ReconocerTitulo({ archivo, usuario, item, draft, onApply, onBusy }) {
  const [ocupado, setOcupado] = useState(false)
  const [abierto, setAbierto] = useState(false)
  const [resultado, setResultado] = useState(null)
  const [propuestos, setPropuestos] = useState({})
  const [tab, setTab] = useState('detectados')
  const [error, setError] = useState('')
  const [seleccion, setSeleccion] = useState({})
  const [revisado, setRevisado] = useState(false)
  const [aplicado, setAplicado] = useState(false)
  const peticion = useRef(null)
  const panelId = useId()
  useEffect(() => () => peticion.current?.abort(), [])
  const niveles = niveles03[item.letra] || {}
  const esBasico = item.letra === 'a'
  const etiquetas = esBasico ? Object.fromEntries(Object.entries({ ...etiquetasTitulo, nombre: 'Nombre del certificado', numero_diploma: 'Serie y número del certificado' }).filter(([key]) => key !== 'numero_registro')) : etiquetasTitulo

  async function reconocer() {
    if (ocupado) return
    setAbierto(true); setOcupado(true); onBusy(true); setError(''); setResultado(null); setRevisado(false); setAplicado(false); setTab('detectados')
    const controller = new AbortController()
    peticion.current = controller
    const form = new FormData()
    form.append('archivo', archivo)
    form.append('tipo_documento_id', item.id)
    try {
      const response = await api.post(`/expedientes/${usuario}/seccion-03/reconocer`, form, { signal: controller.signal, timeout: 60000, sinAvisoCambios: true })
      if (controller.signal.aborted) return
      const data = response.data.data
      setResultado(data); setPropuestos(data.campos)
      setSeleccion(seleccionInicial(data.campos, niveles))
    } catch (failure) {
      if (!controller.signal.aborted) setError(failure.response?.data?.errors?.archivo?.join(' ') || (failure.response?.status === 503 || failure.response?.status === 409 ? failure.response.data.message : apiErrorMessage(failure)))
    } finally { if (!controller.signal.aborted) { setOcupado(false); onBusy(false) } }
  }
  function aplicar() {
    onApply(camposSeleccionados(propuestos, seleccion, niveles), resultado.token)
    setAplicado(true); setAbierto(false)
  }
  function corregir(key, value) {
    setPropuestos(prev => ({ ...prev, [key]: value }))
    setSeleccion(prev => ({ ...prev, [key]: !!value }))
    setRevisado(false)
  }
  const detectados = Object.keys(etiquetas).filter(key => resultado?.campos[key])
  const pendientes = Object.keys(etiquetas).filter(key => !resultado?.campos[key])
  const campos = tab === 'detectados' ? detectados : pendientes
  const elegidos = Object.keys(camposSeleccionados(propuestos, seleccion, niveles)).length
  const tabs = [['detectados', `Detectados (${detectados.length})`], ['pendientes', `Por completar (${pendientes.length})`], ['texto', 'Texto y fechas']]

  return <section className="col-span-full space-y-3 rounded-xl border border-teal-200 bg-teal-50/50 p-4" aria-label="Lectura asistida del documento">
    <div className="flex flex-wrap items-center justify-between gap-3"><h3 className="text-sm font-semibold text-teal-900">{esBasico ? 'Reconocer datos del certificado' : 'Reconocer datos del título'}</h3><button type="button" className={boton} disabled={ocupado || !archivo} onClick={reconocer}>{ocupado ? 'Leyendo documento…' : resultado ? 'Volver a escanear' : 'Escanear y proponer datos'}</button></div>
    <p className="text-xs leading-relaxed text-slate-600">Procesamiento local, hasta cinco páginas y sin créditos de APIsTED. Los resultados se revisan en un segundo panel antes de rellenar el formulario.</p>
    {resultado && <button type="button" className={boton} onClick={() => setAbierto(true)}>Abrir resultados · {detectados.length} campos detectados</button>}
    {aplicado && <p role="status" className="text-xs font-medium text-teal-800">Datos aplicados. Revisa el formulario antes de guardar.</p>}
    {abierto && <DocumentoModal titulo={esBasico ? "Revisar certificado de estudios básicos" : "Revisar escaneo del título"} busy={ocupado} onCancel={() => setAbierto(false)}>
      <div className="grid min-w-0 items-start gap-5 lg:grid-cols-2">
        <div className="min-w-0 rounded-xl border border-slate-200 bg-white p-3"><h3 className="mb-3 text-sm font-semibold">Documento de referencia</h3><PdfPreviewField previewFile={archivo} help="Compara el contenido con los datos reconocidos." /></div>
        <section className="min-w-0 space-y-4" aria-label="Resultados del escaneo">
          {ocupado && <p role="status" className="rounded-xl bg-teal-50 p-5 text-sm text-teal-800">Leyendo el documento y buscando datos. Puede tardar hasta 40 segundos…</p>}
          {error && <div role="alert" className="rounded-xl bg-rose-50 p-4 text-sm text-rose-800">{error}<p className="mt-2">Puedes cerrar este panel y completar el formulario manualmente.</p></div>}
          {resultado && <>
            <p className="text-xs text-slate-500">{resultado.metodo === 'ocr_local' ? 'Lectura OCR local' : 'Texto del PDF'} · {resultado.paginas} página(s). El reconocimiento no acredita autenticidad.</p>
            <div role="tablist" aria-label="Datos del escaneo" className="flex flex-wrap gap-2">{tabs.map(([key, label], index) => <button key={key} id={`${panelId}-${key}`} type="button" role="tab" aria-selected={tab === key} aria-controls={`${panelId}-contenido`} tabIndex={tab === key ? 0 : -1} className={`${boton} ${tab === key ? 'bg-teal-100 ring-1 ring-teal-500' : ''}`} onClick={() => setTab(key)} onKeyDown={event => {
              if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
                event.preventDefault()
                const destino = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length
                setTab(tabs[destino][0]); document.getElementById(`${panelId}-${tabs[destino][0]}`)?.focus()
              }
            }}>{label}</button>)}</div>
            <div id={`${panelId}-contenido`} role="tabpanel" aria-labelledby={`${panelId}-${tab}`} tabIndex={0} className="space-y-3">
              {tab !== 'texto' && <>
                <p className="text-xs text-slate-600">{tab === 'detectados' ? 'Los campos detectados compatibles están marcados. Corrige o desmarca los que no quieras aplicar.' : 'Estos datos no se detectaron. Complétalos solo si figuran en el documento.'}</p>
                {!campos.length && <p className="rounded-xl bg-white p-4 text-sm text-slate-600">{tab === 'detectados' ? 'No se detectaron campos. Revisa el texto y completa los datos manualmente.' : 'No hay campos pendientes.'}</p>}
                {campos.map(key => {
                  const soloContraste = key === 'titular_nombre'
                  const value = propuestos[key] || ''
                  const compatible = key !== 'nivel' || Object.hasOwn(niveles, value)
                  const reemplaza = !soloContraste && draft[key] && draft[key] !== value
                  return <article key={key} className="rounded-xl border border-slate-200 bg-white p-3 text-xs">
                    <div className="flex items-start gap-3"><input type="checkbox" className="mt-1" aria-label={`Aplicar ${etiquetas[key]}`} disabled={soloContraste || !value || !compatible} checked={soloContraste ? !!value : !!seleccion[key] && !!value && compatible} onChange={event => { setSeleccion(prev => ({ ...prev, [key]: event.target.checked })); setRevisado(false) }} />
                      <label className="grid min-w-0 flex-1 gap-2"><span className="font-semibold text-slate-700">{etiquetas[key]}</span>
                        {soloContraste ? <span className="text-slate-600">{value || 'No detectado'}<span className="mt-1 block text-slate-500">No modifica la identidad del legajo.</span></span> : key === 'nivel' ? <select className="w-full rounded-lg border border-slate-200 p-2 text-sm" value={value} onChange={event => corregir(key, event.target.value)}><option value="">Selecciona</option>{value && !compatible && <option value={value}>{value} · No corresponde al tipo elegido</option>}{Object.entries(niveles).map(([codigo, label]) => <option key={codigo} value={codigo}>{label}</option>)}</select> : <input className="w-full rounded-lg border border-slate-200 p-2 text-sm" type={key.startsWith('fecha_') ? 'date' : 'text'} maxLength={key.startsWith('numero_') ? 40 : key === 'pais' ? 60 : 200} value={value} onChange={event => corregir(key, event.target.value)} />}
                        {reemplaza && <span className="text-amber-800">Reemplazará: {key === 'nivel' ? niveles[draft[key]] || draft[key] : draft[key]}</span>}
                      </label>
                    </div>
                    {resultado.evidencias[key] && <details className="mt-2 text-slate-500"><summary className="cursor-pointer">Texto de referencia</summary><p className="mt-2 whitespace-pre-wrap">{resultado.evidencias[key]}</p></details>}
                  </article>
                })}
              </>}
              {tab === 'texto' && <>
                {resultado.avisos.map((aviso, i) => <p key={i} className="rounded-lg bg-amber-50 p-3 text-xs leading-relaxed text-amber-900">{aviso}</p>)}
                <h4 className="text-sm font-semibold">Fechas encontradas</h4>{resultado.fechas_detectadas.map((fecha, i) => <p key={i} className="rounded-lg bg-white p-3 text-xs">{fecha.fecha}: {fecha.contexto}</p>)}
                <h4 className="text-sm font-semibold">Texto completo leído</h4><pre className="max-h-96 overflow-auto whitespace-pre-wrap break-words rounded-xl bg-white p-3 font-sans text-xs">{resultado.texto}</pre>
              </>}
            </div>
            {tab !== 'texto' && resultado.avisos.length > 1 && <p className="text-xs text-amber-900">Hay advertencias sobre la lectura. Revísalas en «Texto y fechas».</p>}
            <div className="sticky bottom-0 space-y-3 rounded-xl border border-teal-200 bg-white p-4 shadow-sm">
              <label className="flex items-start gap-2 text-xs leading-relaxed text-slate-700"><input type="checkbox" checked={revisado} onChange={event => setRevisado(event.target.checked)} />He comparado el titular y los datos seleccionados con el documento.</label>
              <div className="flex flex-wrap items-center justify-between gap-3"><span className="text-xs text-slate-500">{elegidos} campos para aplicar</span><button type="button" className={boton} disabled={!revisado || !elegidos} onClick={aplicar}>Aplicar y volver al formulario</button></div>
            </div>
          </>}
        </section>
      </div>
    </DocumentoModal>}
  </section>
}
