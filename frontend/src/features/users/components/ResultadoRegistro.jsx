import { useEffect, useRef } from 'react'
import { Link } from 'react-router-dom'
import Icon from '../../../components/ui/Icon'
import { rutaDatosPersonales, rutaDocumentosIngreso, rutaFicha } from '../utils/registroPasos'

// Resultado del registro manual: la persona, su legajo abierto y lo que sigue, a un clic y sin volver a buscarla. «Subir
// la resolución de ingreso» abre su ficha en 2.2 con la lista de documentos a la vista; «Subir copia del DNI», su
// información personal. Sin legajo.ver no se ofrecen las fichas.
const tarjeta = 'flex min-w-0 flex-col gap-1 rounded-2xl p-4 text-left transition-colors'
const secundaria = `${tarjeta} border border-slate-200 bg-white hover:border-teal-300`

export default function ResultadoRegistro({ registro, puedeVerFicha, onOtra, onCerrar }) {
  // El formulario se oculta al registrar: el foco pasa al título del resultado (si no, cae fuera del diálogo).
  const titulo = useRef(null)
  useEffect(() => { titulo.current?.focus() }, [])
  const { usuario, legajo } = registro
  const nombre = [usuario.nombres, usuario.apellido_paterno, usuario.apellido_materno].filter(Boolean).join(' ')
  const incorporacion = registro.numero_incorporacion || 1
  return <section aria-labelledby="registro-resultado-titulo" className="min-h-0 flex-1 overflow-y-auto bg-slate-50 px-4 py-8 md:px-6">
    <div className="mx-auto grid max-w-2xl gap-5">
      <div className="grid justify-items-center gap-3 rounded-2xl border border-slate-200 bg-white px-6 py-8 text-center">
        <span className="grid size-14 place-items-center rounded-full bg-teal-50 text-teal-700"><Icon name="check" size={28} /></span>
        <h3 ref={titulo} tabIndex={-1} id="registro-resultado-titulo" className="text-xl font-bold text-slate-800 focus:outline-none">Registro listo</h3>
        <p className="max-w-lg text-sm text-slate-600">
          <strong className="text-slate-800">{nombre}</strong> ya forma parte del personal. Se abrió su legajo <strong className="text-slate-800">{legajo.numero_legajo}</strong> con su vínculo laboral
          {incorporacion > 1 ? ` (incorporación n.° ${incorporacion}; los legajos anteriores siguen en Legajos pasivos).` : '.'}
        </p>
        <p className="text-xs text-slate-500">DNI {usuario.numero_doc_identidad}</p>
      </div>

      <div className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-5">
        <h4 className="text-sm font-semibold text-slate-800">¿Qué sigue?</h4>
        <div className="grid gap-3 sm:grid-cols-2">
          {puedeVerFicha && <Link to={rutaDocumentosIngreso(usuario.id)} className={`${tarjeta} border-2 border-teal-700 bg-teal-50 hover:bg-teal-100`}>
            <span className="text-sm font-semibold text-teal-900">Subir la resolución de ingreso</span>
            <span className="text-xs text-teal-800">Sección 02 · la lectura con IA puede proponer los datos del documento</span>
          </Link>}
          {puedeVerFicha && <Link to={rutaDatosPersonales(usuario.id)} className={secundaria}>
            <span className="text-sm font-semibold text-slate-800">Subir copia del DNI</span>
            <span className="text-xs text-slate-500">Sección 01 · información personal</span>
          </Link>}
          {puedeVerFicha && <Link to={rutaFicha(usuario.id)} className={secundaria}>
            <span className="text-sm font-semibold text-slate-800">Ir a su ficha</span>
            <span className="text-xs text-slate-500">Ver todo el legajo</span>
          </Link>}
          <button type="button" onClick={onOtra} className={secundaria}>
            <span className="text-sm font-semibold text-slate-800">Registrar otra persona</span>
            <span className="text-xs text-slate-500">Empieza de nuevo con otro DNI</span>
          </button>
        </div>
        {!puedeVerFicha && <p className="text-xs text-slate-500">Tu rol no puede ver legajos: pide a quien tenga acceso a los legajos que continúe con sus documentos.</p>}
      </div>
      <button type="button" onClick={onCerrar} className="justify-self-center text-xs font-semibold text-slate-600 underline">Cerrar</button>
    </div>
  </section>
}
