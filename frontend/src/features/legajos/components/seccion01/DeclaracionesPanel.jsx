import { saveRegistro } from '../../api/seccion01Api'
import { etiqueta, resultadosDeclaracion, tiposDeclaracion } from '../../config/seccion01'
import Campo, { Formulario, Opciones } from './Campo'
import RegistrosPanel from './RegistrosPanel'
import { hoy, useBorrador, useGuardar } from './useGuardar'

const fecha = value => value ? value.split('-').reverse().join('/') : 'No registrada'

function DeclaracionForm({ usuario, onSaved, onCancel }) {
  const [draft, set] = useBorrador({ tipo: '', anio: '', fecha: '', resultado: '', vigente_hasta: '', observacion: '' })
  const { busy, error, errors, guardar } = useGuardar(data => saveRegistro(usuario, 'declaraciones-verificaciones', { ...data, anio: data.anio ? Number(data.anio) : null }), onSaved)
  return <Formulario titulo="Registrar declaración o verificación" guardar="Guardar registro" busy={busy} error={error} onSubmit={() => guardar(draft)} onCancel={onCancel}
    nota="No se puede editar después: si hay un error, se anula y se registra de nuevo. El responsable se guarda con tu sesión.">
    <Campo label="Tipo" required error={errors.tipo} className="sm:col-span-2"><select required value={draft.tipo} onChange={set('tipo')}><Opciones lista={tiposDeclaracion} vacio="Selecciona" /></select></Campo>
    <Campo label="Fecha de emisión o consulta" required error={errors.fecha}><input type="date" required max={hoy()} value={draft.fecha} onChange={set('fecha')} /></Campo>
    <Campo label="Resultado" required error={errors.resultado}><select required value={draft.resultado} onChange={set('resultado')}><Opciones lista={resultadosDeclaracion} vacio="Selecciona" /></select></Campo>
    <Campo label="Año al que corresponde" error={errors.anio}><input type="number" min={1950} max={new Date().getFullYear() + 1} placeholder="Ej.: año de la declaración de bienes" value={draft.anio} onChange={set('anio')} /></Campo>
    <Campo label="Vigente hasta" error={errors.vigente_hasta}><input type="date" min={draft.fecha || undefined} value={draft.vigente_hasta} onChange={set('vigente_hasta')} /></Campo>
    <Campo label="Observación" error={errors.observacion} className="sm:col-span-2"><textarea rows={2} maxLength={300} value={draft.observacion} onChange={set('observacion')} /></Campo>
  </Formulario>
}

export default function DeclaracionesPanel({ usuario }) {
  return <RegistrosPanel
    usuario={usuario}
    tipo="declaraciones-verificaciones"
    titulo="Declaraciones y verificaciones"
    agregar="Registrar declaración"
    vacio="No hay declaraciones ni verificaciones registradas."
    tituloRegistro={record => etiqueta(tiposDeclaracion, record.values.tipo)}
    subtitulo={record => `${fecha(record.values.fecha)}${record.values.anio ? ` · Año ${record.values.anio}` : ''}`}
    campos={[
      ['Resultado', v => etiqueta(resultadosDeclaracion, v.resultado)],
      ['Vigente hasta', v => v.vigente_hasta ? fecha(v.vigente_hasta) : 'Sin vencimiento registrado'],
      ['Observación', v => v.observacion || 'Sin observación'],
    ]}
    renderForm={props => <DeclaracionForm usuario={usuario} {...props} />}
  />
}
