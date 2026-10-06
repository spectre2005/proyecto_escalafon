// Listas cerradas de la sección 01. Los valores los valida el backend (app/Support/Catalogos.php);
// aquí solo se agregan las etiquetas para mostrar.
export const tiposDocIdentidad = { dni: 'DNI', ce: 'Carné de extranjería', pasaporte: 'Pasaporte', otro: 'Otro' }

export const estadosCiviles = { soltero: 'Soltero(a)', casado: 'Casado(a)', conviviente: 'Conviviente', divorciado: 'Divorciado(a)', viudo: 'Viudo(a)' }

export const parentescos = { conyuge: 'Cónyuge', concubino: 'Concubino(a)', hijo: 'Hijo(a)', otro: 'Otro' }

// Anexo 01, sección 01: literales c, d, e, f, h y l.
export const tiposDeclaracion = {
  antecedente_penal: 'Antecedentes penales',
  antecedente_judicial: 'Antecedentes judiciales',
  antecedente_policial: 'Antecedentes policiales',
  impedimentos_rnssc: 'Impedimentos e inhabilitaciones (RNSSC)',
  impedimentos_redjum: 'Deudores alimentarios (REDJUM)',
  djbr: 'Declaración jurada de bienes y rentas',
  dj_intereses: 'Declaración jurada de intereses',
  dj_no_impedimentos: 'Declaración jurada de no tener impedimentos (Ley 31419)',
  otra_dj: 'Otra declaración jurada',
}

export const resultadosDeclaracion = { presentada: 'Presentada', sin_registros: 'Sin registros', con_registros: 'Con registros', no_aplica: 'No aplica' }

export const personasCertificado = { servidor: 'El servidor(a)', familiar: 'Un familiar' }

// Sugerencias, no lista cerrada: la nacionalidad admite cualquier texto.
export const nacionalidades = ['Peruana', 'Argentina', 'Boliviana', 'Brasileña', 'Chilena', 'Colombiana', 'Ecuatoriana', 'Española', 'Mexicana', 'Venezolana']

// Categorías de las secciones 01, 09 y 10: requieren datos_sensibles.ver (el backend responde 403).
export const categoriasSensibles = ['familiares', 'declaraciones-verificaciones', 'certificados-discapacidad', 'afiliaciones', 'controversias', 'registros-sst-bienestar', 'seccion-10', 's10-sst', 's10-bienestar']

export function etiqueta(lista, valor) {
  if (valor === null || valor === undefined || valor === '') return 'No registrado'
  return lista[valor] || String(valor).replaceAll('_', ' ')
}
