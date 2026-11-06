// Buscador instantáneo de la guía: funciona sin IA, con los temas que el backend ya filtró por permiso.
const VACIAS = new Set(['como', 'donde', 'para', 'puedo', 'quiero', 'hacer', 'hago', 'que', 'una', 'uno', 'los', 'las', 'del', 'por', 'con', 'sistema', 'necesito', 'debo', 'cual', 'esta', 'este', 'hay'])

const normalizar = texto => texto.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')

function palabras(texto) {
  return [...new Set(normalizar(texto).split(/[^\p{L}\p{N}]+/u).filter(palabra => palabra.length >= 3 && !VACIAS.has(palabra)))]
}

function aparece(palabra, tema) {
  const raiz = palabra.slice(0, 5)
  return [...tema.palabras.map(normalizar), ...palabras(`${tema.titulo} ${tema.pasos.join(' ')}`)].some(otra => otra.startsWith(raiz))
}

function puntaje(buscadas, tema, frecuencias) {
  const claves = tema.palabras.map(normalizar)
  const titulo = palabras(tema.titulo)
  const pasos = palabras(tema.pasos.join(' '))
  return buscadas.reduce((puntos, palabra) => {
    const raiz = palabra.slice(0, 5)
    const coincide = lista => lista.some(otra => otra.startsWith(raiz))
    const valor = coincide(claves) ? 3 : coincide(titulo) ? 2 : coincide(pasos) ? 1 : 0
    // «Legajo» o «documento» salen en casi todos los temas: no deben ganarle a «certificado».
    return puntos + (frecuencias[palabra] >= 4 ? Math.min(valor, 1) : valor)
  }, 0)
}

export function buscarTemas(temas, texto) {
  const buscadas = palabras(texto)
  if (!buscadas.length) return []
  const frecuencias = Object.fromEntries(buscadas.map(palabra => [palabra, temas.filter(tema => aparece(palabra, tema)).length]))
  return temas
    .map((tema, indice) => ({ tema, indice, puntos: puntaje(buscadas, tema, frecuencias) }))
    .filter(({ puntos }) => puntos > 0)
    .sort((a, b) => b.puntos - a.puntos || a.indice - b.indice)
    .map(({ tema }) => tema)
}

// «/legajos/abc» pertenece a los temas de «/legajos», y «/legajos-otros» no.
export function temasDePantalla(temas, ruta) {
  return temas.filter(tema => ruta === tema.ruta || ruta.startsWith(`${tema.ruta}/`))
}

const ORIGENES = { ia: 'Respuesta de la IA', guia: 'Según la guía' }

export function etiquetaOrigen(origen) {
  return ORIGENES[origen] || null
}

// El tema del que se habló por última vez, para que «¿y después?» siga la conversación.
export function ultimoTema(mensajes) {
  return [...mensajes].reverse().find(mensaje => mensaje.temas?.length)?.temas[0].id ?? null
}
