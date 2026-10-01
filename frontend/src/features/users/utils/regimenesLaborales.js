// El valor se guarda en regimen_laboral (máximo 20 caracteres); la etiqueta solo se muestra.
export const regimenesPorTipo = {
  docente: [
    { value: '30220', label: 'Ley N° 30220 - Ley Universitaria' },
  ],
  administrativo: [
    { value: 'CAS', label: 'Decreto Legislativo N° 1057 (CAS)' },
    { value: '276', label: 'Decreto Legislativo N° 276' },
    { value: '728', label: 'Decreto Legislativo N° 728' },
    { value: '30057', label: 'Ley N° 30057 (Ley del Servicio Civil)' },
  ],
}

export function regimenInicial(tipoPersonal) {
  const opciones = regimenesPorTipo[tipoPersonal] || []
  return opciones.length === 1 ? opciones[0].value : ''
}
