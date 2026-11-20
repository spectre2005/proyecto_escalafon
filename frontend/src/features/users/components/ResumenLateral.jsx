import Icon from '../../../components/ui/Icon'
import { modalidadElegida, rutaUnidad } from '../../legajos/config/catalogosVinculo'
import { categoriasDocente, condicionesDocente, opcionesNivelCarrera, regimenesLaborales } from '../../legajos/config/seccion02'
import { iniciales } from '../utils/registroPasos'

// Resumen del registro a la derecha de los pasos 1 y 2: lo que ya se completó, el legajo que se abrirá y lo que queda
// «Sin responder». Solo muestra: no valida ni guarda.

const tiposPersonal = { docente: 'Docente', administrativo: 'Administrativo' }
const fecha = valor => valor ? valor.split('-').reverse().join('/') : ''

export default function ResumenLateral({ persona, ley, codigoLey, vinculo, datosPuesto = {}, opciones, escalas, sinResponder = [] }) {
  const nombre = [persona.nombres, persona.apellido_paterno, persona.apellido_materno].filter(Boolean).join(' ')
  const docente = persona.tipo_personal === 'docente'
  const unidad = rutaUnidad(opciones?.unidades, vinculo.unidad_organica_id).slice(-1)[0]
  const nivel = docente
    ? [condicionesDocente[datosPuesto.condicion_docente], categoriasDocente[datosPuesto.categoria]].filter(Boolean).join(' · ')
    : opcionesNivelCarrera(escalas, codigoLey, datosPuesto.grupo_ocupacional, datosPuesto.nivel_carrera)[datosPuesto.nivel_carrera]
  const modalidad = modalidadElegida(opciones, vinculo)
  const tipoVinculo = [regimenesLaborales[vinculo.regimen_laboral], modalidad?.nombre].filter(Boolean).join(' · ')
  const filas = [
    ['Unidad', unidad],
    ['Puesto', vinculo.nombre_puesto],
    [docente ? 'Condición' : 'Nivel', nivel],
    ['Vínculo', tipoVinculo && (vinculo.fecha_inicio ? `${tipoVinculo} desde ${fecha(vinculo.fecha_inicio)}` : tipoVinculo)],
  ]

  const tipoLey = [tiposPersonal[persona.tipo_personal], ley].filter(Boolean).join(' · ')
  const avance = [
    ['Identidad y correo', Boolean(persona.numero_doc_identidad && persona.nombres && persona.apellido_paterno && persona.email_institucional)],
    [tipoLey || 'Tipo de personal y ley', Boolean(persona.tipo_personal && ley)],
    ['Puesto y vínculo', Boolean(vinculo.nombre_puesto && vinculo.regimen_laboral && vinculo.fecha_inicio)],
  ]
  const conPuesto = filas.some(([, valor]) => valor)

  return <aside aria-label="Resumen del registro" className="grid content-start gap-4 rounded-2xl border border-slate-200 bg-white p-5 sm:p-6">
    <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">Resumen</p>
    <div className="flex min-w-0 items-center gap-3">
      <span aria-hidden="true" className="grid size-11 shrink-0 place-items-center rounded-xl bg-teal-50 text-sm font-bold text-teal-800">{iniciales(persona) || <Icon name="user" size={20} />}</span>
      <div className="min-w-0">
        <p className="truncate text-sm font-semibold text-slate-800">{nombre || 'Persona sin nombre aún'}</p>
        <p className="truncate text-xs text-slate-500">{persona.numero_doc_identidad ? `DNI ${persona.numero_doc_identidad}` : 'Sin DNI'}</p>
      </div>
    </div>
    <ul className="grid gap-2.5 text-sm">
      {avance.map(([texto, hecho]) => <li key={texto} className="flex items-center gap-2">
        <span aria-hidden="true" className={hecho ? 'text-teal-700' : 'text-slate-300'}>{hecho ? <Icon name="check" size={16} /> : <span className="block size-4 rounded-full border-2 border-current" />}</span>
        <span className={hecho ? 'text-slate-700' : 'text-slate-500'}>{texto}</span>
        <span className="sr-only">{hecho ? '(completo)' : '(pendiente)'}</span>
      </li>)}
    </ul>
    {conPuesto && <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-2 border-t border-slate-100 pt-3 text-[13px]">
      {filas.map(([etiqueta, valor]) => <div key={etiqueta} className="contents">
        <dt className="text-slate-500">{etiqueta}</dt>
        <dd className={`m-0 break-words ${valor ? 'text-slate-800' : 'text-slate-500'}`}>{valor || '—'}</dd>
      </div>)}
    </dl>}
    {opciones?.siguiente_legajo && <p className="rounded-xl bg-slate-50 px-3 py-2.5 text-[13px] text-slate-600">Legajo que se abrirá: <strong className="text-slate-800">{opciones.siguiente_legajo}</strong></p>}
    {sinResponder.length > 0 && <p className="flex gap-2 rounded-xl bg-amber-50 px-3 py-2.5 text-[13px] text-amber-800">
      <Icon name="clock" size={15} />
      <span>{sinResponder.length === 1 ? '1 condición sin responder' : `${sinResponder.length} condiciones sin responder`}. Puedes completarlas después.</span>
    </p>}
  </aside>
}
