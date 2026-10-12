// Banderas del perfil con tres estados (backend: App\Support\AplicacionDocumental): true = Sí, false = No,
// null = Sin responder. Nunca se lee null como No: Sin responder no oculta documentos, los deja por determinar.

// Del puesto: se registran en el vínculo laboral (sección 02).
export const banderasPuesto = ['obligado_djbr', 'exige_colegiatura', 'exige_estudios_basicos', 'tiene_periodo_prueba']

// De la persona: en la ficha de datos personales.
export const banderasPersona = ['tiene_derechohabientes', 'con_discapacidad']

export const banderas = [...banderasPuesto, ...banderasPersona]

export function esBandera(clave) {
  return banderas.includes(clave)
}

// Valor guardado como true, false o null (undefined, '' y otros valores ausentes son Sin responder).
export function valorBandera(valor) {
  return valor === true || valor === false ? valor : null
}

export function textoBandera(valor) {
  return valor === true ? 'Sí' : valor === false ? 'No' : 'Sin responder'
}
