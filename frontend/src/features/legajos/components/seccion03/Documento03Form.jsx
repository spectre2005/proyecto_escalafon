import ReconocerTitulo from './ReconocerTitulo'
import FormSplit from '../../../../components/pdf/FormSplit'
import PdfPreviewField from '../../../../components/pdf/PdfPreviewField'
import { useEffect, useRef, useState } from 'react'
import Campo, { Formulario, Opciones } from '../seccion01/Campo'
import { hoy, useBorrador, useGuardar } from '../seccion01/useGuardar'
import { agregarDocumento03 } from '../../api/seccion03Api'
import { modalidadesPresentacion, folios, modalidadRecordada, recordarModalidad } from '../../config/documentos'
import { SugerenciaFolio } from '../documentos/AyudasCarga'
import { niveles03, capacitaciones03 } from '../../config/seccion03'
import { conValor, reemplazarPropuesta } from '../../config/acreditacion'

// Datos del documento que se subsana y de su registro (formación, colegiatura o capacitación, en doc.datos): se proponen
// para corregir solo lo necesario. Folios y PDF son del documento nuevo.
function datosDe(doc) {
  if (!doc) return {}
  const registro = doc.datos || {}
  return conValor({
    nombre: doc.nombre, emisor: doc.emisor, fecha_emision: doc.fecha_emision, modalidad_presentacion: doc.modalidad_presentacion,
    nivel: registro.nivel, numero_registro: registro.numero_registro,
    pais: registro.pais, numero_diploma: registro.numero_diploma, fecha_obtencion: registro.fecha_obtencion, numero_colegiatura: registro.numero_colegiatura, vigente_hasta: registro.vigente_hasta,
    tipo_capacitacion: registro.tipo_capacitacion, horas: registro.horas === undefined || registro.horas === null ? '' : String(registro.horas),
  })
}

// subsana: documento que se subsana (desde «Subsanar»). soloSubsanar: el tipo ya no se ofrece a la persona; solo se
// admite subsanar un documento existente (el backend rechaza una carga nueva).
export default function Documento03Form({
  usuario,
  item,
  datos,
  onSaved,
  onCancel,
  subsana = '',
  soloSubsanar = false
}) {
  const nombre = useRef(null)

  useEffect(() => {
    nombre.current?.focus()
  }, [])

  const [pdf, setPdf] = useState(null)
  const [lecturaOcupada, setLecturaOcupada] = useState(false)
  const [versionArchivo, setVersionArchivo] = useState(0)
  const [reconocimientoToken, setReconocimientoToken] = useState('')
  const esTitulo = ['b', 'e'].includes(item.letra)
  const esBasico = item.letra === 'a'
  const esReconocible = esTitulo || esBasico
  const [errorPdf, setErrorPdf] = useState('')
  const [paginas, setPaginas] = useState(null)

  const vigentes = item.documentos.filter(
    doc => !doc.subsanado && doc.estado !== 'anulado'
  )

  const vacios = {
    nombre: '',
    emisor: '',
    fecha_emision: '',
    // Última modalidad usada en este navegador (se puede cambiar).
    modalidad_presentacion: modalidadRecordada(),
    numero_registro: '',
    pais: '',
    numero_diploma: '',
    fecha_obtencion: '',
    nivel: esBasico ? 'educacion_basica' : '',
    numero_colegiatura: '',
    vigente_hasta: '',
    tipo_capacitacion: '',
    horas: '',
  }
  // Un tipo de un solo documento con uno vigente solo se subsana: se elige ese documento y se proponen sus datos.
  const inicial = subsana || (!item.permite_multiples && vigentes.length === 1 ? vigentes[0].id : '')
  const documento = id => item.documentos.find(doc => doc.id === id)
  const [propuesta, setPropuesta] = useState(() => datosDe(documento(inicial)))
  const [draft, set, poner] = useBorrador({
    ...vacios,
    ...propuesta,
    folio_inicio: datos.folio_sugerido,
    folio_fin: datos.folio_sugerido,
    subsana_a_documento_id: inicial,
  })
  function elegirSubsana(event) {
    const nueva = datosDe(documento(event.target.value))
    const siguiente = reemplazarPropuesta(draft, propuesta, nueva, vacios)
    setPropuesta(nueva)
    poner('subsana_a_documento_id')(event.target.value)
    for (const campo of Object.keys(vacios)) if (siguiente[campo] !== draft[campo]) poner(campo)(siguiente[campo])
  }
  const debeSubsanar = soloSubsanar || !item.permite_multiples

  const { busy, error, errors, guardar } = useGuardar(values => {
    const comunes = [
      'nombre',
      'emisor',
      'fecha_emision',
      'folio_inicio',
      'folio_fin',
      'modalidad_presentacion',
      'subsana_a_documento_id'
    ]

    const especificos = ['b', 'e'].includes(item.letra)
      ? ['numero_registro', 'nivel', 'pais', 'numero_diploma', 'fecha_obtencion']
      : esBasico ? ['nivel', 'pais', 'numero_diploma', 'fecha_obtencion']
      : item.letra === 'c'
        ? ['numero_colegiatura', 'vigente_hasta']
        : ['f', 'g'].includes(item.letra)
          ? ['tipo_capacitacion', 'horas']
          : []

    const payload = Object.fromEntries(
      [...comunes, ...especificos].map(key => [
        key,
        values[key]
      ])
    )

    return agregarDocumento03(
      usuario,
      {
        ...payload,
        tipo_documento_id: item.id,
        ...(reconocimientoToken ? { reconocimiento_token: reconocimientoToken, reconocimiento_confirmado: 1 } : {})
      },
      pdf
    )
  }, () => { recordarModalidad(draft.modalidad_presentacion); onSaved() })

  return (
    <Formulario
      modal
      titulo={draft.subsana_a_documento_id ? 'Subsanar documento' : 'Agregar documento'}
      guardar="Guardar documento"
      busy={busy || lecturaOcupada}
      busyText={lecturaOcupada ? 'Leyendo documento…' : 'Guardando…'}
      error={error}
      onCancel={onCancel}
      onSubmit={() => {
        if (pdf && !errorPdf && !lecturaOcupada) guardar(draft)
      }}
      nota="Lo registrado no se edita ni se elimina: para corregirlo, agrega un documento nuevo que subsane al anterior."
    >
      <FormSplit
        pdf={
          <PdfPreviewField
            label={`${esReconocible ? esBasico ? 'PDF o imagen del certificado' : 'PDF o imagen del título' : 'Archivo PDF'} (máximo ${datos.tamano_maximo_kb} KB)`}
            allowImages={esReconocible}
            required
            maxMB={
              datos.tamano_maximo_kb
                ? datos.tamano_maximo_kb / 1024
                : 2
            }
            help={esReconocible ? 'Selecciona un PDF, JPG o PNG. Las imágenes se incorporan como PDF. Puedes reconocer los datos antes de guardar.' : 'Selecciona el PDF para ver su contenido antes de guardarlo.'}
            error={errorPdf || errors.pdf}
            onPages={setPaginas}
            onChange={(file, message) => {
              setPdf(file)
              setVersionArchivo(version => version + 1)
              setReconocimientoToken('')
              setLecturaOcupada(false)
              setErrorPdf(message)
            }}
          />
        }
      >
        {esReconocible && pdf && !errorPdf && <ReconocerTitulo key={versionArchivo} archivo={pdf} usuario={usuario} item={item} draft={draft} onBusy={setLecturaOcupada} onApply={(campos, token) => { for (const [campo, valor] of Object.entries(campos)) poner(campo)(valor); setReconocimientoToken(token) }} />}
        {esBasico && <p className="col-span-full text-xs leading-relaxed text-slate-500">Puedes <a className="font-semibold text-teal-700 underline" href="/ejemplos/certificado-estudios-basicos-prueba.pdf" download>descargar un formato de prueba sin validez</a> para ensayar el escaneo. No corresponde a un estudiante ni acredita estudios.</p>}
        {errors.reconocimiento_token && <p role="alert" className="col-span-full text-xs text-rose-700">{errors.reconocimiento_token.join(' ')}</p>}
        <Campo
          label="Nombre del documento"
          required
          error={errors.nombre}
          className="sm:col-span-2"
        >
          <input
            ref={nombre}
            required
            maxLength={200}
            value={draft.nombre}
            onChange={set('nombre')}
          />
        </Campo>

        <Campo
          label="Institución emisora"
          required
          error={errors.emisor}
        >
          <input
            required
            maxLength={200}
            value={draft.emisor}
            onChange={set('emisor')}
          />
        </Campo>

        <Campo
          label="Fecha de emisión"
          required
          error={errors.fecha_emision}
        >
          <input
            type="date"
            required
            max={hoy()}
            value={draft.fecha_emision}
            onChange={set('fecha_emision')}
          />
        </Campo>

        {niveles03[item.letra] && (
          <>
            <Campo
              label="Nivel académico"
              required
              error={errors.nivel}
            >
              <select
                required
                value={draft.nivel}
                onChange={set('nivel')}
              >
                <Opciones
                  lista={niveles03[item.letra]}
                  vacio="Selecciona"
                />
              </select>
            </Campo>

            {!esBasico && <Campo
              label="N.º de registro (opcional)"
              error={errors.numero_registro}
            >
              <input
                maxLength={40}
                value={draft.numero_registro}
                onChange={set('numero_registro')}
              />
            </Campo>}
          </>
        )}

        {esReconocible && <>
          <Campo label="País de la institución (si se conoce)" error={errors.pais}><input maxLength={60} value={draft.pais} onChange={set('pais')} /></Campo>
          <Campo label={esBasico ? "Serie y número del certificado (si figuran)" : "Número de diploma (si figura)"} error={errors.numero_diploma}><input maxLength={40} value={draft.numero_diploma} onChange={set('numero_diploma')} /></Campo>
          <Campo label="Fecha de obtención (si difiere de la expedición)" error={errors.fecha_obtencion}><input type="date" max={hoy()} value={draft.fecha_obtencion} onChange={set('fecha_obtencion')} /></Campo>
          <p className="col-span-full text-xs leading-relaxed text-slate-500">{esBasico ? 'Registra la institución educativa o CEBA que expide el certificado. La serie y el número no son un registro SUNEDU. Un formato en blanco no acredita estudios.' : 'El país, número de diploma y registro oficial se conservan por separado. Un título extranjero requiere revisar su reconocimiento o revalidación; cargarlo no los acredita.'}</p>
        </>}

        {item.letra === 'c' && (
          <>
            <Campo
              label="Número de colegiatura"
              required
              error={errors.numero_colegiatura}
            >
              <input
                required
                maxLength={30}
                value={draft.numero_colegiatura}
                onChange={set('numero_colegiatura')}
              />
            </Campo>

            <Campo
              label="Habilitación vigente hasta (si figura)"
              error={errors.vigente_hasta}
            >
              <input
                type="date"
                min={draft.fecha_emision}
                value={draft.vigente_hasta}
                onChange={set('vigente_hasta')}
              />
            </Campo>
          </>
        )}

        {capacitaciones03[item.letra] && (
          <>
            <Campo
              label="Tipo de capacitación"
              required
              error={errors.tipo_capacitacion}
            >
              <select
                required
                value={draft.tipo_capacitacion}
                onChange={set('tipo_capacitacion')}
              >
                <Opciones
                  lista={capacitaciones03[item.letra]}
                  vacio="Selecciona"
                />
              </select>
            </Campo>

            {/* HORAS SIN FLECHITAS */}
            <Campo
              label="Horas (si figuran)"
              error={errors.horas}
            >
              <input
                type="number"
                min="1"
                max="100000"
                value={draft.horas}
                onChange={set('horas')}
                className="[appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
              />
            </Campo>
          </>
        )}

        {/* FOLIO INICIAL SIN FLECHITAS */}
        <Campo
          label="Folio inicial sugerido"
          required
          error={errors.folio_inicio}
        >
          <input
            type="number"
            required
            min="1"
            value={draft.folio_inicio}
            onChange={set('folio_inicio')}
            className="[appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
          />
        </Campo>

        {/* FOLIO FINAL SIN FLECHITAS */}
        <Campo
          label="Folio final"
          required
          error={errors.folio_fin}
        >
          <input
            type="number"
            required
            min={draft.folio_inicio || 1}
            value={draft.folio_fin}
            onChange={set('folio_fin')}
            className="[appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
          />
        </Campo>
        <SugerenciaFolio inicio={draft.folio_inicio} paginas={paginas} valor={draft.folio_fin} onUsar={poner('folio_fin')} />

        <Campo
          label="Modalidad de presentación"
          required
          error={errors.modalidad_presentacion}
        >
          <select
            required
            value={draft.modalidad_presentacion}
            onChange={set('modalidad_presentacion')}
          >
            <Opciones
              lista={modalidadesPresentacion}
              vacio="Selecciona"
            />
          </select>
        </Campo>

        {vigentes.length > 0 && (
          <Campo
            label="Documento que subsana"
            required={debeSubsanar}
            error={errors.subsana_a_documento_id}
            className="sm:col-span-2"
          >
            <select
              required={debeSubsanar}
              value={draft.subsana_a_documento_id}
              onChange={elegirSubsana}
            >
              <option value="">
                {!debeSubsanar
                  ? 'Es un documento adicional'
                  : 'Selecciona el documento que corriges'}
              </option>

              {vigentes.map(doc => (
                <option
                  key={doc.id}
                  value={doc.id}
                >
                  {doc.nombre} · folios{' '}
                  {folios(
                    doc.folio_inicio,
                    doc.folio_fin
                  )}
                </option>
              ))}
            </select>
          </Campo>
        )}

        {errors.tipo_documento_id && (
          <p
            role="alert"
            className="col-span-full text-sm text-rose-700"
          >
            {errors.tipo_documento_id.join(' ')}
          </p>
        )}
      </FormSplit>
    </Formulario>
  )
}
