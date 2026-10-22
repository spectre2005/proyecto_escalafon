import { useState } from 'react'
import api, { apiErrorMessage } from '../../../axios'
import { institucionParaPdf } from '../institucionPdf'
import { puestoActual, textoRegimen } from '../../legajos/config/seccion02'

export default function DownloadPersonalReport({ usuario, blank = false, className }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  async function download() {
    if (busy) return
    setBusy(true)
    setError('')
    try {
      const { createPersonalPdf } = await import('../pdf/fichaPersonalPdf')
      const institution = await institucionParaPdf()
      let values = {}
      const families = []
      if (!blank) {
        const response = await api.get(`/expedientes/${usuario}`)
        // La ley y el tipo de vínculo vigente salen de regimen (regimen_laboral de la persona es solo un respaldo).
        const regimen = textoRegimen(response.data.data.regimen)
        // Puesto y unidad actuales: del vínculo vigente (o los registrados antes en la persona).
        const actual = puestoActual(response.data.data.puesto_actual)
        values = { ...response.data.data.usuario.values, ley: regimen.ley, tipo_vinculo: regimen.tipo, nombre_puesto: actual.puesto, unidad_organica_id: actual.unidad }
        let page = 1
        let lastPage
        do {
          const result = await api.get(`/expedientes/${usuario}/familiares`, { params: { page } })
          families.push(...result.data.data)
          lastPage = result.data.last_page
          page++
        } while (page <= lastPage)
      }
      createPersonalPdf({ values, families, blank, institution }).save(blank ? 'ficha_personal_vacia.pdf' : 'ficha_personal.pdf')
    } catch (failure) {
      setError(failure.response || failure.isAxiosError ? apiErrorMessage(failure) : 'No se pudo generar el PDF. Inténtalo nuevamente.')
    } finally {
      setBusy(false)
    }
  }
  return <div><button type="button" className={className} disabled={busy} onClick={download}>{busy ? 'Generando PDF…' : blank ? 'Descargar ficha vacía' : 'Descargar PDF'}</button>{error && <p role="alert" className="mt-2 text-xs text-rose-700">{error}</p>}</div>
}
