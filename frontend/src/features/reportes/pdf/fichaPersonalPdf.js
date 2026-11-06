import { disenoDocumento } from './disenoDocumento.js'
import { jsPDF } from 'jspdf'
import { personalGroups, familyFields, reportValue } from '../config/fichaPersonal.js'
import { presentacionPdf } from './presentacionPdf.js'

export function createPersonalPdf({ values = {}, families = [], blank = false, institution = 'Ficha del personal' } = {}) {
  const pdf = new jsPDF({ unit: 'mm', format: 'a4' })
  const base = presentacionPdf(pdf, typeof institution === 'string' ? { name: institution } : institution)
  const { branding, footer } = base
  pdf.setProperties({ title: branding.reportTitle, author: branding.name, subject: 'Ficha institucional del personal', creator: branding.area })
  const doc = disenoDocumento(pdf, base, { title: blank ? `${branding.reportTitle} - Formato en blanco` : branding.reportTitle,
    instruction: blank ? 'Complete los cuadros con letra clara. En las condiciones, indique Sí o No.' : '' })
  function group(name, fields, data) {
    doc.heading(name)
    for (let i = 0; i < fields.length; i++) {
      const [key, label] = fields[i]
      const full = ['apellidos_nombres', 'domicilio', 'nombre_puesto', 'unidad_organica_id', 'email_institucional', 'motivo_anulacion'].includes(key)
      const next = fields[i + 1]
      const pair = [label, blank ? ' ' : reportValue(data[key], false, key)]
      if (!full && next && !['apellidos_nombres', 'domicilio', 'nombre_puesto', 'unidad_organica_id', 'email_institucional', 'motivo_anulacion'].includes(next[0])) {
        doc.fields([pair, [next[1], blank ? ' ' : reportValue(data[next[0]], false, next[0])]])
        i++
      } else doc.fields([pair], 1)
    }
  }
  personalGroups.forEach(([name, fields], index) => group(`${index + 1}. ${name}`, fields, values))
  if (blank) group('Información familiar', familyFields.filter(([key]) => !['anulado', 'motivo_anulacion'].includes(key)), {})
  else if (families.length) families.forEach((family, index) => group(`Información familiar · Familiar ${index + 1}`, familyFields, family.values))
  else group('Información familiar', [['familiares', 'Familiares registrados']], { familiares: 'Sin familiares registrados' })
  if (blank) group('Conformidad', [['fecha', 'Fecha'], ['firma', 'Firma del personal']], {})
  footer()
  return pdf
}
