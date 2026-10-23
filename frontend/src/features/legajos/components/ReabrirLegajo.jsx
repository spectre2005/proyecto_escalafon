import AbrirLegajo from './AbrirLegajo'

// Compatibilidad con vistas anteriores: una reincorporación abre otro legajo.
export default function ReabrirLegajo({ usuario }) {
  return <div className="space-y-3">
    <p className="text-xs text-slate-600">Los legajos cerrados se conservan como históricos. Para una reincorporación abre un nuevo legajo de la misma persona.</p>
    <AbrirLegajo usuario={usuario} />
  </div>
}
