import { useId } from 'react'
import Icon from '../../components/ui/Icon'
import { fecha } from './estado'
import { ENTRADA, boton } from './estilos'
import { Campo } from './ui'

function DatoRuc({ etiqueta, valor, aviso = false, className = '' }) {
  return <div className={`min-w-0 rounded-xl border border-slate-100 bg-slate-50/70 px-3 py-2.5 ${className}`}>
    <dt className="text-xs text-slate-500">{etiqueta}</dt>
    <dd className={`mt-1 whitespace-pre-wrap break-words text-sm font-medium ${aviso ? 'text-amber-800' : 'text-slate-800'}`}>{valor || <span className="font-normal text-slate-400">No informado</span>}</dd>
  </div>
}

export default function CampoRuc({ value, consulta, disabled, children }) {
  const id = useId()
  const { estado, consultando, valido } = consulta
  const empresa = estado.empresa
  const invalido = value.length === 11 && !valido
  const mensaje = consultando ? 'Consultando los datos de la institución…'
    : invalido ? 'Revisa el RUC: el formato o dígito verificador no es correcto.'
      : estado.tipo === 'error' ? estado.mensaje
        : empresa ? `${empresa.origen === 'cache' ? 'Consulta reciente recuperada' : 'Datos obtenidos de Factiliza'}. Revisa la razón social antes de guardar.`
          : 'Al completar los 11 dígitos se buscará la razón social. También puedes completar los datos manualmente.'
  return <div className="rounded-2xl border border-teal-200 bg-teal-50/50 p-4">
    <div className="mb-3 flex items-center gap-2 text-sm font-semibold text-teal-900"><Icon name="search" size={18} />Identificar institución por RUC</div>
    <Campo etiqueta="RUC" ayuda="Opcional · 11 dígitos, sin espacios ni guiones.">
      <input value={value} onChange={event => consulta.cambiarRuc(event.target.value)} disabled={disabled} inputMode="numeric" pattern="[0-9]{11}" maxLength={11}
        autoComplete="off" placeholder="Ingresa el RUC de la institución" aria-describedby={id} aria-invalid={invalido} className={ENTRADA} />
    </Campo>
    <p id={id} role="status" aria-live="polite" className={`mt-2 text-xs leading-relaxed ${invalido || estado.tipo === 'error' ? 'text-amber-900' : 'text-teal-800'}`}>{mensaje}</p>
    {estado.tipo === 'error' && <button type="button" disabled={disabled || consultando} onClick={() => consulta.reintentar()} className={`${boton('secundario', 'pequeno')} mt-3`}>Reintentar consulta</button>}
    {children && <div className="mt-4 space-y-4">{children}</div>}
    {empresa && <div className="mt-5 space-y-4 border-t border-teal-100 pt-4">
      <section aria-label="Situación tributaria" className="rounded-2xl border border-slate-200 bg-white p-4">
        <h3 className="text-sm font-semibold text-slate-800">Situación tributaria</h3>
        <dl className="mt-3 grid gap-3 sm:grid-cols-2">
          <DatoRuc etiqueta="Tipo de contribuyente" valor={empresa.tipo_contribuyente} className="sm:col-span-2" />
          <DatoRuc etiqueta="Estado del contribuyente" valor={empresa.estado} aviso={!!empresa.estado && empresa.estado !== 'ACTIVO'} />
          <DatoRuc etiqueta="Condición del domicilio" valor={empresa.condicion} aviso={!!empresa.condicion && empresa.condicion !== 'HABIDO'} />
        </dl>
      </section>
      <section aria-label="Ubicación fiscal" className="rounded-2xl border border-slate-200 bg-white p-4">
        <h3 className="text-sm font-semibold text-slate-800">Ubicación fiscal</h3>
        <dl className="mt-3 grid gap-3 sm:grid-cols-3">
          <DatoRuc etiqueta="Departamento" valor={empresa.departamento} />
          <DatoRuc etiqueta="Provincia" valor={empresa.provincia} />
          <DatoRuc etiqueta="Distrito" valor={empresa.distrito} />
          <DatoRuc etiqueta="Dirección" valor={empresa.direccion} className="sm:col-span-3" />
          <DatoRuc etiqueta="Dirección completa" valor={empresa.direccion_completa} className="sm:col-span-3" />
          <DatoRuc etiqueta="Ubigeo SUNAT" valor={empresa.ubigeo_sunat} className="sm:col-span-3" />
        </dl>
      </section>
      <div className="flex flex-wrap items-center justify-between gap-3 text-xs text-slate-600">
        <p className="min-w-0 flex-1">Consultado el {fecha(empresa.consultado_en)}. Los datos tributarios se muestran como referencia de esta consulta.</p>
        <button type="button" disabled={disabled || consultando} onClick={() => consulta.reintentar(true)} className={boton('secundario', 'pequeno')}>Actualizar consulta</button>
      </div>
    </div>}
  </div>
}
