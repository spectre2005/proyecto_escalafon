// permiso: el ítem se oculta si la sesión no lo tiene (con una lista, basta uno). Es solo presentación; el backend valida.
export const navigation = [
  { label: 'Inicio', to: '/', icon: 'home', group: 'Principal' },
  { label: 'Personal', to: '/personal', icon: 'users', group: 'Principal', permiso: 'personal.ver' },
  { label: 'Legajos', to: '/legajos', icon: 'folder', group: 'Principal', permiso: 'legajo.ver' },
  // funcionalidades: el ítem se oculta si la institución las desactivó todas (configuración de funcionalidades).
  { label: 'Evaluaciones', to: '/evaluaciones', modulo: 'evaluaciones', icon: 'check', group: 'Principal', permiso: 'seccion07.ver', funcionalidades: ['s07.evaluaciones', 's07.progresion', 's07.desplazamientos'] },
  { label: 'Reportes', to: '/reportes', icon: 'chart', group: 'Principal', permiso: ['personal.ver', 'legajo.ver'] },
  { label: 'Requerimientos', to: '/requerimientos', icon: 'clock', group: 'Principal', permiso: 'solicitudes.propias' },
  { label: 'Vencimientos', to: '/vencimientos', icon: 'clock', group: 'Principal', permiso: 'legajo.ver' },
  { label: 'Fiscalización', icon: 'search', group: 'Administración', permiso: 'sistema.administrar' },
  { label: 'Accesos y custodia', to: '/accesos-y-custodia', icon: 'shield', group: 'Administración', permiso: ['sistema.administrar', 'configuracion.modulos'] },
  // La identidad institucional y las funcionalidades conservan sus permisos independientes.
  { label: 'Configuración', to: '/configuracion', icon: 'settings', group: 'Administración', permiso: 'sistema.administrar' },
]
