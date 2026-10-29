import { Link } from 'react-router-dom'
import Icon from '../../../components/ui/Icon'
import { rutaDocumentosIngreso, rutaFicha } from '../utils/registroPasos'

// Resultado del registro manual: la persona, su legajo abierto y el siguiente paso sin volver a buscarla. «Subir documentos
// de ingreso» abre su ficha en 2.2 con la lista de documentos a la vista. Sin legajo.ver no se ofrecen las fichas.
export default function ResultadoRegistro({ registro, puedeVerFicha, onOtra, onCerrar }) {
  const { usuario, legajo } = registro
  const nombre = [usuario.nombres, usuario.apellido_paterno, usuario.apellido_materno].filter(Boolean).join(' ')
  const accion = 'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-xs font-semibold'
  return <section aria-labelledby="registro-resultado-titulo" className="min-h-0 flex-1 overflow-y-auto bg-white px-4 py-8 md:px-6">
    <div className="mx-auto max-w-xl text-center">
      <span className="mx-auto grid size-12 place-items-center rounded-2xl bg-[#e4f2e9] text-[#2c7650]"><Icon name="check" size={24} /></span>
      <h3 id="registro-resultado-titulo" role="status" className="mt-4 text-lg font-semibold text-[#243f43]">Persona registrada</h3>
      <p className="mt-2 text-sm text-[#557566]"><strong>{nombre}</strong> quedó incorporada por {registro.numero_incorporacion || 1}.ª vez, con un nuevo legajo y vínculo laboral.</p>
      <dl className="mx-auto mt-5 grid max-w-sm grid-cols-2 gap-3 rounded-xl border border-[#e0e9e4] bg-[#f8fbf9] p-4 text-left text-xs">
        <div><dt className="text-[#7b9181]">N.º de legajo</dt><dd className="mt-1 text-base font-semibold text-[#243f43]">{legajo.numero_legajo}</dd></div>
        <div><dt className="text-[#7b9181]">DNI</dt><dd className="mt-1 text-base font-semibold text-[#243f43]">{usuario.numero_doc_identidad}</dd></div>
      </dl>
      <p className="mt-5 text-xs text-[#7b9181]">Siguiente paso: subir la resolución o el contrato y los demás documentos de incorporación (sección 02).</p>
      <div className="mt-5 flex flex-wrap justify-center gap-2">
        {puedeVerFicha && <Link to={rutaDocumentosIngreso(usuario.id)} className={`${accion} bg-[#26776d] text-white hover:bg-[#1b625a]`}><Icon name="upload" size={16} />Subir documentos de ingreso</Link>}
        {puedeVerFicha && <Link to={rutaFicha(usuario.id)} className={`${accion} border border-[#dbe6e1] bg-white text-[#4f6d63] hover:bg-[#edf6f2]`}>Ir a la ficha</Link>}
        <button type="button" onClick={onOtra} className={`${accion} border border-[#dbe6e1] bg-white text-[#4f6d63] hover:bg-[#edf6f2]`}><Icon name="plus" size={16} />Registrar otra persona</button>
      </div>
      {!puedeVerFicha && <p className="mt-4 text-[11px] text-[#8b9c91]">Tu rol no puede ver legajos: pide a la ORH que continúe con sus documentos.</p>}
      <button type="button" onClick={onCerrar} className="mt-6 text-xs font-semibold text-[#4f6d63] underline">Cerrar</button>
    </div>
  </section>
}
