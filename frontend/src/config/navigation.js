// permiso: el ítem se oculta si la sesión no lo tiene (con una lista, basta uno). Es solo presentación; el backend valida.
export const navigation = [
  { label: 'Inicio', to: '/', icon: 'home', group: 'Principal' },
  { label: 'Personal', to: '/personal', icon: 'users', group: 'Principal', permiso: 'personal.ver' },
  { label: 'Legajos', to: '/legajos', icon: 'folder', group: 'Principal', permiso: 'legajo.ver' },
  // funcionalidades: el ítem se oculta si la institución las desactivó todas (configuración de funcionalidades).
  { label: 'Evaluaciones', to: '/evaluaciones', icon: 'check', group: 'Principal', permiso: 'seccion07.ver', funcionalidades: ['s07.evaluaciones', 's07.progresion', 's07.desplazamientos'] },
  { label: 'Reportes', to: '/reportes', permiso: 'sistema.administrar', icon: 'chart', group: 'Principal' },
  // Requerimientos lee hoy del CRUD genérico (sistema.administrar).
  { label: 'Requerimientos', to: '/requerimientos', icon: 'clock', group: 'Principal', permiso: 'sistema.administrar' },
  { label: 'Vencimientos', to: '/vencimientos', icon: 'clock', group: 'Principal', permiso: 'legajo.ver' },
  { label: 'Auditoría', to: '/auditoria', icon: 'search', group: 'Administración', permiso: 'sistema.administrar' },
  { label: 'Revisión documental', to: '/revision-documental', icon: 'check', group: 'Administración', permiso: 'documento.revisar' },
  { label: 'Avisos', to: '/avisos', icon: 'clock', group: 'Administración', permiso: 'sistema.administrar' },
  { label: 'Fiscalización', to: '/fiscalizacion', icon: 'search', group: 'Administración', permiso: 'sistema.administrar' },
  { label: 'Accesos y custodia', to: '/custodia', icon: 'shield', group: 'Administración', permiso: 'sistema.administrar' },
  // Configuración institucional (sistema.administrar) y de funcionalidades (configuracion.modulos, asignación explícita).
  { label: 'Configuración', to: '/configuracion', icon: 'settings', group: 'Administración', permiso: ['sistema.administrar', 'configuracion.modulos'] },
]
