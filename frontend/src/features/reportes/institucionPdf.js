import { cacheInstitution, normalizeInstitution, readInstitution } from '../../config/institution'
import { fetchInstitution } from '../settings/institutionApi'

/** Identidad institucional para los PDF: la configurada en el sistema o, si no hay, la guardada en el navegador. */
export async function institucionParaPdf() {
  const branding = await fetchInstitution()
  const institution = branding.configured ? normalizeInstitution(branding.data) : readInstitution()
  if (branding.configured) cacheInstitution(institution)
  return institution
}
