// documentoId: documento recién subido que el registro acredita (el formulario se abre al subir el PDF).
export const conDocumento = (data, documentoId) => documentoId ? { ...data, documento_id: documentoId } : data

// Lista cerrada limitada a las claves permitidas (las valida el backend según el tipo del documento).
export const solo = (lista, claves) => claves ? Object.fromEntries(Object.entries(lista).filter(([key]) => claves.includes(key))) : lista
