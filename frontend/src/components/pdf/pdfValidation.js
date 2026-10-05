export function validatePdf(file, maxMB = 2) {
  if (!file) return ''
  if (!/\.pdf$/i.test(file.name) || (file.type && file.type !== 'application/pdf')) return 'Selecciona un archivo PDF.'
  if (file.size > maxMB * 1024 * 1024) return `El archivo pesa ${(file.size / 1024 / 1024).toFixed(2)} MB; el máximo es ${maxMB} MB.`
  return ''
}
