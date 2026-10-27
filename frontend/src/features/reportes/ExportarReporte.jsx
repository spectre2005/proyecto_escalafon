import { useState } from 'react'
import { useOutletContext } from 'react-router-dom'
import api, { apiErrorMessage } from '../../axios'
import Icon from '../../components/ui/Icon'
import { defaultInstitution } from '../../config/institution'
import { crearExcelReporte, crearPdfReporte, descargarExcel, describirFiltros } from './exportarReporte'

const boton = 'inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition-colors hover:border-teal-200 hover:bg-teal-50 focus-visible:outline-2 focus-visible:outline-teal-700 disabled:cursor-not-allowed disabled:opacity-50'

// Excel y PDF de un reporte (backend: /reportes/{tipo}?exportar=1): todos los resultados con los mismos filtros y permisos,
// armados en el navegador. Lo usan Reportes y los paneles que tienen su reporte propio (Vencimientos, Evaluaciones).
// `opciones` solo da nombre a los filtros en el archivo (unidades, estados…); sin ellas se muestran los valores.
export default function ExportarReporte({ tipo, filtros, opciones = {}, deshabilitado = false }) {
  const institucion = useOutletContext()?.institution || defaultInstitution
  const [exportando, setExportando] = useState(null)
  const [error, setError] = useState('')
  async function exportar(formato) {
    if (exportando) return
    setExportando(formato)
    setError('')
    try {
      const parametros = new URLSearchParams(filtros)
      parametros.delete('page')
      parametros.delete('por_pagina')
      parametros.set('exportar', '1')
      const { data: completo } = await api.get(`/reportes/${tipo}?${parametros}`, { timeout: 120000 })
      const descripcion = describirFiltros(completo.meta.filtros, opciones)
      const nombre = `${tipo}_${new Date(completo.meta.generado_en).toLocaleDateString('sv-SE', { timeZone: 'America/Lima' })}`
      if (formato === 'excel') descargarExcel(await crearExcelReporte(completo, institucion, descripcion), `${nombre}.xlsx`)
      else (await crearPdfReporte(completo, institucion, descripcion)).save(`${nombre}.pdf`)
    } catch (e) { setError(apiErrorMessage(e)) } finally { setExportando(null) }
  }
  return <div className="grid justify-items-end gap-2">
    <div className="flex flex-wrap gap-2">
      <button type="button" disabled={deshabilitado || !!exportando} onClick={() => exportar('excel')} className={boton}><Icon name="file" size={17} />{exportando === 'excel' ? 'Generando Excel…' : 'Excel'}</button>
      <button type="button" disabled={deshabilitado || !!exportando} onClick={() => exportar('pdf')} className={boton}><Icon name="file" size={17} />{exportando === 'pdf' ? 'Generando PDF…' : 'PDF'}</button>
    </div>
    {exportando && <p role="status" className="text-xs text-teal-700">Preparando todos los resultados filtrados…</p>}
    {error && <p role="alert" className="max-w-sm rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
  </div>
}
