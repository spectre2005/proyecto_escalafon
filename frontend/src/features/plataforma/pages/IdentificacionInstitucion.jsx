import { Aviso } from '../componentes'
import { fecha } from '../estado'
import { ENTRADA, TARJETA, boton } from '../estilos'
import { Campo } from '../ui'

function Dato({ etiqueta, children }) {
  return <div><dt className="text-xs text-slate-500">{etiqueta}</dt><dd className="mt-1 break-all text-sm text-slate-800">{children || '—'}</dd></div>
}

/**
 * Identificación de la institución: sus datos técnicos o, mientras se edita, el formulario.
 * edicion: lo que devuelve useEdicionIdentificacion() (../useEdicionIdentificacion), que usa también la cabecera.
 */
export default function IdentificacionInstitucion({ institucion, edicion }) {
  const { borrador, setBorrador, guardando, error, cancelar, guardar } = edicion
  const editable = institucion.ubicacion_editable
  const campo = (nombre, etiqueta, props = {}) => <Campo etiqueta={etiqueta}><input value={borrador[nombre] ?? ''} onChange={event => setBorrador({ ...borrador, [nombre]: event.target.value })} disabled={guardando || (nombre !== 'nombre' && !editable)} className={ENTRADA} {...props} /></Campo>
  return borrador ? <form onSubmit={guardar} aria-busy={guardando} className={`${TARJETA} mt-6 space-y-4`}>
    <Aviso>{error}</Aviso>
    {campo('nombre', 'Nombre *', { required: true, minLength: 3, maxLength: 150 })}
    {campo('slug', 'Identificador *', { required: true, maxLength: 40 })}
    {campo('base_datos', 'Base de datos *', { required: true, maxLength: 63 })}
    {campo('almacenamiento', 'Almacenamiento *', { required: true, maxLength: 120 })}
    {!editable && <p className="text-xs text-slate-500">Ya aprovisionada o en operación: el identificador, la base de datos y el almacenamiento no se cambian desde el panel.</p>}
    <div className="flex justify-end gap-3"><button type="button" onClick={cancelar} disabled={guardando} className={boton('secundario')}>Cancelar</button><button type="submit" disabled={guardando} className={boton('principal')}>{guardando ? 'Guardando…' : 'Guardar'}</button></div>
  </form> : <dl className={`${TARJETA} mt-6 grid gap-5 sm:grid-cols-2`}>
    <Dato etiqueta="Identificador">{institucion.slug}</Dato>
    <Dato etiqueta="Base de datos">{institucion.base_datos}</Dato>
    <Dato etiqueta="Almacenamiento">{institucion.almacenamiento}</Dato>
    <Dato etiqueta="Aprovisionamiento verificado">{institucion.aprovisionada ? fecha(institucion.aprovisionada_en) : 'Pendiente'}</Dato>
    <Dato etiqueta="Registrada">{fecha(institucion.created_at)}</Dato>
    <Dato etiqueta="Última modificación">{fecha(institucion.updated_at)}</Dato>
  </dl>
}
