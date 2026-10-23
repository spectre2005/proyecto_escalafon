import CerrarLegajo from './CerrarLegajo'

export default function FinalizarLegajo({ usuario, legajo }) {
  return <section role="status" className="mb-5 space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
    <h3 className="font-semibold">Todos los vínculos de este legajo terminaron</h3>
    <p>Finaliza y cierra este legajo. Después aparecerá «Abrir nuevo legajo» para la reincorporación de esta misma persona. Los documentos anteriores se conservarán como históricos.</p>
    <p>Mientras este legajo siga abierto no se permite registrar otro vínculo. Puedes completar el sustento de la desvinculación antes de cerrarlo.</p>
    <CerrarLegajo usuario={usuario} legajo={legajo.id} />
  </section>
}
