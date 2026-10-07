import { useState } from 'react'
import api, { apiErrorMessage } from '../../../axios'
import { cacheInstitution, normalizeInstitution, readInstitution } from '../../../config/institution'
import { fetchInstitution } from '../../settings/institutionApi'

export default function DownloadPersonalReport({ usuario, blank = false, className }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  async function download() {
    if (busy) return
    setBusy(true)
    setError('')
    try {
      const { createPersonalPdf } = await import('../pdf/fichaPersonalPdf')
      const branding = await fetchInstitution()
      const institution = branding.configured ? normalizeInstitution(branding.data) : readInstitution()
      if (branding.configured) cacheInstitution(institution)
      let values = {}
      const families = []
      if (!blank) {
        const response = await api.get(`/expedientes/${usuario}`)
        values = response.data.data.usuario.values
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
