// permiso: el ítem se oculta si la sesión no lo tiene. Es solo presentación; el backend valida.
export const navigation = [
  { label: 'Inicio', to: '/', icon: 'home', group: 'Principal' },
  { label: 'Personal', to: '/personal', icon: 'users', group: 'Principal', permiso: 'personal.ver' },
  { label: 'Legajos', to: '/legajos', icon: 'folder', group: 'Principal', permiso: 'legajo.ver' },
  // Evaluaciones y Requerimientos leen hoy del CRUD genérico (sistema.administrar).
  // Evaluaciones pasará a seccion07.ver cuando tenga su propio endpoint.
  { label: 'Evaluaciones', to: '/evaluaciones', icon: 'check', group: 'Principal', permiso: 'sistema.administrar' },
  { label: 'Reportes', icon: 'chart', group: 'Principal' },
  { label: 'Requerimientos', to: '/requerimientos', icon: 'clock', group: 'Principal', permiso: 'sistema.administrar' },
  { label: 'Fiscalización', icon: 'search', group: 'Administración', permiso: 'sistema.administrar' },
  { label: 'Accesos y custodia', icon: 'shield', group: 'Administración', permiso: 'sistema.administrar' },
  { label: 'Configuración', to: '/configuracion', icon: 'settings', group: 'Administración', permiso: 'sistema.administrar' },
]
