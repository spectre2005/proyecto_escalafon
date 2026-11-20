import { CON_PROCESO, modalidadElegida, rutaUnidad } from '../../legajos/config/catalogosVinculo'
import { gruposDelPuesto } from '../../legajos/config/puestosSugeridos'
import { avisosDeCoherencia } from '../utils/registroPasos'
import { categoriasDocente, condicionesDocente, dedicacionesDocente, gruposOcupacionales, regimenesLaborales } from '../../legajos/config/seccion02'

// Paso final del registro manual: todo lo que se va a guardar, por bloques, con «Cambiar» para volver a cada uno, y lo que
// queda sin responder (no impide registrar). No valida: el backend lo hace al registrar.

const tiposPersonal = { docente: 'Docente', administrativo: 'Administrativo' }
const fecha = valor => valor ? valor.split('-').reverse().join('/') : ''
const sinRespuesta = valor => valor === true ? 'Sí' : valor === false ? 'No' : 'Sin responder'

function Bloque({ titulo, paso, onIr, filas }) {
  return <section className="rounded-2xl border border-slate-200 bg-white">
    <div className="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3.5 sm:px-5">
      <h4 className="text-base font-semibold text-slate-800">{titulo}</h4>
      <button type="button" onClick={() => onIr(paso)} className="text-xs font-semibold text-teal-700 hover:underline" aria-label={`Cambiar ${titulo.toLowerCase()}`}>Cambiar</button>
    </div>
    <dl className="grid grid-cols-1 gap-x-3 gap-y-1 sm:grid-cols-[minmax(0,9.5rem)_minmax(0,1fr)] sm:gap-y-2.5 px-4 py-4 text-sm sm:px-5">
      {filas.filter(Boolean).map(([etiqueta, valor]) => <div key={etiqueta} className="contents">
        <dt className="text-slate-500">{etiqueta}</dt>
        <dd className={`m-0 break-words ${valor ? 'text-slate-800' : 'text-slate-500'}`}>{valor || 'Sin indicar'}</dd>
      </div>)}
    </dl>
  </section>
}

export default function ResumenRegistro({ nuevoIngreso = false, persona, ley, codigoLey = '', nivel = '', vinculo, datosPuesto = {}, opciones, sinResponder = [], onIr }) {
  const tipo = persona.tipo_personal
  const modalidad = modalidadElegida(opciones, vinculo)
  const designado = vinculo.regimen_laboral === 'designado'
  const avisos = [
    nuevoIngreso && 'Nuevo ingreso: su legajo actual pasará a Legajos pasivos como historial, con sus documentos, y se abrirá un legajo nuevo.',
    modalidad?.exige_fin_previsto && !vinculo.fecha_fin_prevista && `La modalidad «${modalidad.nombre}» exige la fecha de fin prevista: complétala en el paso 2.`,
    CON_PROCESO.includes(vinculo.regimen_laboral) && !vinculo.proceso_seleccion && 'Aún no se indica el proceso de selección. Puedes registrarlo ahora en el paso 2 o después en la sección 02.',
    ...avisosDeCoherencia({ vinculo, datosPuesto, codigoLey, tipo, gruposPuesto: gruposDelPuesto(vinculo.nombre_puesto, opciones?.puestos, vinculo.puesto_id) }),
  ].filter(Boolean)
  const puesto = tipo === 'docente'
    ? [['Condición docente', condicionesDocente[datosPuesto.condicion_docente]], ['Categoría', categoriasDocente[datosPuesto.categoria]], ['Dedicación', dedicacionesDocente[datosPuesto.dedicacion]]]
    : [['Grupo ocupacional', gruposOcupacionales[datosPuesto.grupo_ocupacional]], ['Nivel de carrera', nivel], ['Cargo estructural', datosPuesto.cargo_estructural], ['Cargo de confianza', datosPuesto.es_cargo_confianza ? 'Sí' : 'No']]

  return <div className="grid gap-5">
    <p className="text-sm text-slate-600">Todo se guarda de una sola vez: la persona, su legajo{opciones?.siguiente_legajo ? ` ${opciones.siguiente_legajo}` : ''} y su primer vínculo.</p>
    {avisos.length > 0 && <ul role="note" className="grid gap-1 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-relaxed text-amber-800">
      {avisos.map(aviso => <li key={aviso}>{aviso}</li>)}
    </ul>}
    <div className="grid items-start gap-4 lg:grid-cols-2">
    <Bloque titulo="Persona" paso={1} onIr={onIr} filas={[
      ['N.° de legajo', opciones?.siguiente_legajo ? `${opciones.siguiente_legajo} (se asigna al registrar)` : ''],
      ['Nombre', [persona.nombres, persona.apellido_paterno, persona.apellido_materno].filter(Boolean).join(' ')],
      ['DNI', persona.numero_doc_identidad], ['Correo', persona.email_institucional],
      ['Tipo de personal', tiposPersonal[tipo]], ['Ley', ley],
    ]} />
    <Bloque titulo="Puesto" paso={2} onIr={onIr} filas={[
      ['Unidad orgánica', rutaUnidad(opciones?.unidades, vinculo.unidad_organica_id).join(' › ')],
      ['Puesto', vinculo.nombre_puesto],
      ...puesto,
      ['Declaración jurada de bienes y rentas', sinRespuesta(vinculo.obligado_djbr)], ['Exige colegiatura', sinRespuesta(vinculo.exige_colegiatura)],
      ['Estudios básicos', sinRespuesta(vinculo.exige_estudios_basicos)], ['Período de prueba', sinRespuesta(vinculo.tiene_periodo_prueba)],
    ]} />
    <Bloque titulo="Vínculo laboral" paso={2} onIr={onIr} filas={[
      ['Tipo de vínculo', [regimenesLaborales[vinculo.regimen_laboral], modalidad?.nombre].filter(Boolean).join(' · ')],
      ['Vigencia', vinculo.fecha_inicio ? `${fecha(vinculo.fecha_inicio)} – ${vinculo.fecha_fin_prevista ? fecha(vinculo.fecha_fin_prevista) : 'sin fecha de fin'}` : ''],
      ...(designado ? [] : [['Proceso de selección', vinculo.proceso_seleccion], ['Resultado de selección', fecha(vinculo.fecha_resultado_seleccion)]]),
      ['Fecha de emisión', fecha(vinculo.documento_vinculo_fecha)],
    ]} />
    <section className="rounded-2xl border border-slate-200 bg-white px-4 py-4 sm:px-5">
      <h4 className="text-base font-semibold text-slate-800">Qué falta <span className="font-normal text-slate-500">(no impide registrar)</span></h4>
      <ul className="mt-3 grid gap-2 text-sm">
        {sinResponder.map(condicion => <li key={condicion} className="rounded-xl bg-amber-50 px-3 py-2.5 text-amber-800">{condicion}: <strong>Sin responder</strong></li>)}
        <li className="rounded-xl bg-slate-50 px-3 py-2.5 text-slate-600">Este registro no sube documentos. Después, en su legajo, sube el DNI en la sección 01 y la resolución o el contrato de ingreso en la sección 02.</li>
      </ul>
    </section>
    </div>
  </div>
}
