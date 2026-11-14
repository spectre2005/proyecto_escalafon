import { CON_PROCESO, modalidadElegida, rutaUnidad } from '../../legajos/config/catalogosVinculo'
import { categoriasDocente, condicionesDocente, dedicacionesDocente, gruposOcupacionales, regimenesLaborales } from '../../legajos/config/seccion02'

// Paso final del registro manual: todo lo que se va a guardar, por bloques, con un enlace para corregir cada uno.
// No valida: el backend lo hace al registrar. Solo avisa de lo que conviene revisar antes.

const tiposPersonal = { docente: 'Docente', administrativo: 'Administrativo' }
const fecha = valor => valor ? valor.split('-').reverse().join('/') : ''
const sinRespuesta = valor => valor === true ? 'Sí' : valor === false ? 'No' : 'Sin responder'

function Bloque({ titulo, paso, onIr, filas }) {
  return <section className="rounded-xl border border-[#e3ebe7] bg-white">
    <div className="flex items-center justify-between gap-3 border-b border-[#eef3f0] px-4 py-2.5">
      <h4 className="text-[12px] font-semibold text-[#243f43]">{titulo}</h4>
      <button type="button" onClick={() => onIr(paso)} className="text-[11px] font-semibold text-[#26776d] hover:underline">Corregir en el paso {paso}</button>
    </div>
    <dl className="grid gap-x-6 gap-y-3 px-4 py-3 sm:grid-cols-2 lg:grid-cols-3">
      {filas.filter(Boolean).map(([etiqueta, valor]) => <div key={etiqueta} className="min-w-0">
        <dt className="text-[10px] font-semibold uppercase tracking-wider text-[#8b9c91]">{etiqueta}</dt>
        <dd className={`mt-0.5 break-words text-[13px] tabular-nums ${valor ? 'font-medium text-[#243f43]' : 'text-[#9aaaa1]'}`}>{valor || 'Sin indicar'}</dd>
      </div>)}
    </dl>
  </section>
}

export default function ResumenRegistro({ nuevoIngreso = false, persona, ley, vinculo, datosPuesto = {}, opciones, onIr }) {
  const tipo = persona.tipo_personal
  const modalidad = modalidadElegida(opciones, vinculo)
  const designado = vinculo.regimen_laboral === 'designado'
  const avisos = [
    nuevoIngreso && 'Nuevo ingreso: su legajo actual pasará a Legajos pasivos como historial, con sus documentos, y se abrirá un legajo nuevo.',
    modalidad?.exige_fin_previsto && !vinculo.fecha_fin_prevista && `La modalidad «${modalidad.nombre}» exige la fecha de fin prevista: complétala en el paso 3.`,
    CON_PROCESO.includes(vinculo.regimen_laboral) && !vinculo.proceso_seleccion && 'Aún no se indica el proceso de selección. Puedes registrarlo ahora en el paso 3 o después en la sección 02.',
  ].filter(Boolean)
  const puesto = tipo === 'docente'
    ? [['Condición docente', condicionesDocente[datosPuesto.condicion_docente]], ['Categoría', categoriasDocente[datosPuesto.categoria]], ['Dedicación', dedicacionesDocente[datosPuesto.dedicacion]]]
    : [['Grupo ocupacional', gruposOcupacionales[datosPuesto.grupo_ocupacional]], ['Cargo estructural', datosPuesto.cargo_estructural], ['Cargo de confianza', datosPuesto.es_cargo_confianza ? 'Sí' : 'No']]

  return <div className="grid gap-4">
    <p className="text-[12px] text-[#557566]">Revisa los datos antes de registrar. Al confirmar se crea la persona, se abre su legajo y se registra su primer vínculo en una sola operación.</p>
    {avisos.length > 0 && <ul role="note" className="grid gap-1 rounded-lg border border-[#f1d9a6] bg-[#fff8e8] px-4 py-3 text-[11px] leading-relaxed text-[#7a5a17]">
      {avisos.map(aviso => <li key={aviso}>{aviso}</li>)}
    </ul>}
    <Bloque titulo="Persona" paso={1} onIr={onIr} filas={[
      ['N.° de legajo', opciones?.siguiente_legajo ? `${opciones.siguiente_legajo} (se asigna al registrar)` : ''],
      ['Nombre', [persona.nombres, persona.apellido_paterno, persona.apellido_materno].filter(Boolean).join(' ')],
      ['DNI', persona.numero_doc_identidad], ['Correo', persona.email_institucional],
      ['Tipo de personal', tiposPersonal[tipo]], ['Ley', ley],
    ]} />
    <Bloque titulo="Puesto y cargo" paso={2} onIr={onIr} filas={[
      ['Unidad orgánica', rutaUnidad(opciones?.unidades, vinculo.unidad_organica_id).join(' › ')],
      ['Puesto', vinculo.nombre_puesto],
      ...puesto,
      ['DJBR', sinRespuesta(vinculo.obligado_djbr)], ['Exige colegiatura', sinRespuesta(vinculo.exige_colegiatura)],
      ['Estudios básicos', sinRespuesta(vinculo.exige_estudios_basicos)], ['Período de prueba', sinRespuesta(vinculo.tiene_periodo_prueba)],
    ]} />
    <Bloque titulo="Vínculo laboral" paso={3} onIr={onIr} filas={[
      ['Tipo de vínculo', [regimenesLaborales[vinculo.regimen_laboral], modalidad?.nombre].filter(Boolean).join(' · ')],
      ['Vigencia', vinculo.fecha_inicio ? `${fecha(vinculo.fecha_inicio)} – ${vinculo.fecha_fin_prevista ? fecha(vinculo.fecha_fin_prevista) : 'sin fecha de fin'}` : ''],
      ...(designado ? [] : [['Proceso de selección', vinculo.proceso_seleccion], ['Resultado de selección', fecha(vinculo.fecha_resultado_seleccion)]]),
      ['Fecha de emisión', fecha(vinculo.documento_vinculo_fecha)],
    ]} />
  </div>
}
