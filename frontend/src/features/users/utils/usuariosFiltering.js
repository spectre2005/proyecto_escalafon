// estado: acceso al sistema (cuenta, user.activo). situacion: situación laboral (user.situacion, del backend).
export function filterUsers(users, { query = '', tipoPersonal = 'todos', estado = 'todos', correo = 'todos', situacion = 'todos' } = {}) {
  const normalizedQuery = query.toLocaleLowerCase()

  return users.filter((user) => {
    const matchesType = tipoPersonal === 'todos' || user.tipo_personal === tipoPersonal
    const matchesStatus = estado === 'todos' || user.activo === (estado === 'activo')
    const matchesSituation = situacion === 'todos' || user.situacion === situacion
    const hasEmail = Boolean(user.email_institucional?.trim())
    const matchesContact = correo === 'todos' || hasEmail === (correo === 'con')
    const searchableText = `${user.nombres} ${user.apellido_paterno} ${user.apellido_materno} ${user.numero_doc_identidad} ${user.email_institucional}`
      .toLocaleLowerCase()

    return matchesType && matchesStatus && matchesSituation && matchesContact && searchableText.includes(normalizedQuery)
  })
}
