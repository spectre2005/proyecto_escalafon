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
  impedimentos_redjum: 'Deudores judiciales morosos (REDJUM)',
  djbr: 'Declaración jurada de bienes y rentas',
  dj_intereses: 'Declaración jurada de intereses',
  dj_no_impedimentos: 'Declaración jurada de no tener impedimentos (Ley 31419)',
  otra_dj: 'Otra declaración jurada',
}

export const resultadosDeclaracion = { presentada: 'Presentada', sin_registros: 'Sin registros', con_registros: 'Con registros', no_aplica: 'No aplica' }

export const personasCertificado = { servidor: 'El servidor(a)', familiar: 'Un familiar' }

// Sugerencias, no lista cerrada: la nacionalidad admite cualquier texto.
export const nacionalidades = ['Peruana', 'Argentina', 'Boliviana', 'Brasileña', 'Chilena', 'Colombiana', 'Ecuatoriana', 'Española', 'Mexicana', 'Venezolana']

// Grupos del menú de la sección 01 (solo frontend: la directiva no define subsecciones y subseccion_legajo_id es nulo).
// Cada grupo muestra sus datos y sus documentos (códigos de Seccion01CatalogoSeeder). "general" son los datos
// personales de la ficha: se ven sin datos sensibles, pero sus documentos (a y b) los exigen.
export const gruposSeccion01 = {
  general: { titulo: 'Datos personales e identidad', codigos: ['S01_A_FICHA_DATOS', 'S01_B_DOCUMENTO_IDENTIDAD'] },
  's01-antecedentes': { titulo: 'Antecedentes e impedimentos', codigos: ['S01_C_ANTECEDENTES_PENALES', 'S01_D_ANTECEDENTES_POLICIALES', 'S01_H_IMPEDIMENTOS_INHABILITACIONES', 'S01_X_ANTECEDENTES_JUDICIALES'] },
  's01-declaraciones': { titulo: 'Declaraciones juradas', codigos: ['S01_E_DJ_BIENES_RENTAS', 'S01_F_DJ_NO_IMPEDIMENTOS_31419', 'S01_L_OTRAS_DJ', 'S01_X_DJ_INTERESES'] },
  's01-familia': { titulo: 'Familia y derechohabientes', codigos: ['S01_G_PARTIDA_MATRIMONIO_CONCUBINATO', 'S01_I_DNI_CONYUGE_CONCUBINO', 'S01_J_DNI_HIJOS_MENORES'] },
  's01-discapacidad': { titulo: 'Discapacidad', codigos: ['S01_K_CERTIFICADO_DISCAPACIDAD'] },
  's01-otros': { titulo: 'Otros documentos de la universidad', codigos: ['S01_OTROS_UNIVERSIDAD'] },
}

// Tablas de datos que acreditan los documentos de la sección 01 (tipos_documento.formulario_datos).
export const formulariosDatos01 = { familiares: 'Familiar', declaraciones_verificaciones: 'Declaración o verificación', certificados_discapacidad: 'Certificado de discapacidad' }

// Categorías de las secciones 01, 09 y 10: requieren datos_sensibles.ver (el backend responde 403).
export const categoriasSensibles = [...Object.keys(gruposSeccion01).filter(key => key !== 'general'), 'afiliaciones', 'controversias', 'registros-sst-bienestar']

export function etiqueta(lista, valor) {
  if (valor === null || valor === undefined || valor === '') return 'No registrado'
  return lista[valor] || String(valor).replaceAll('_', ' ')
}
