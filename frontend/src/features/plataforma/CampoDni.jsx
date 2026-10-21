import { useId } from 'react'
import { ENTRADA, boton } from './estilos'
import { Campo } from './ui'

export default function CampoDni({ value, consulta, disabled }) {
  const id = useId()
  return <div className="rounded-xl border border-teal-200 bg-teal-50/50 p-4">
    <Campo etiqueta="DNI para completar los datos">
      <input value={value} onChange={event => consulta.cambiarDni(event.target.value)} disabled={disabled}
        inputMode="numeric" pattern="[0-9]{8}" maxLength={8} autoComplete="off" placeholder="Ingresa los 8 dígitos"
        aria-describedby={id} className={ENTRADA} />
    </Campo>
    <p id={id} role="status" aria-live="polite" className={`mt-2 text-xs ${consulta.estado.tipo === 'error' ? 'text-amber-800' : 'text-teal-800'}`}>
      {consulta.consultando ? 'Buscando nombres y apellidos…' : consulta.estado.mensaje || 'La búsqueda comienza al completar el DNI. También puedes ingresar los nombres manualmente.'}
    </p>
    {consulta.estado.tipo === 'error' && <button type="button" disabled={disabled || consulta.consultando} onClick={consulta.reintentar} className={`${boton('secundario', 'pequeno')} mt-2`}>Reintentar consulta</button>}
  </div>
}
