import { useRef, useState } from 'react'
import { apiErrorMessage } from '../../axios'
import { saveUsers } from './usuariosApi'
import { useUsers } from './useUsers'
import Icon from '../../components/ui/Icon'
import { Link, useSearchParams } from 'react-router-dom'
import {
  columns,
  downloadCsv,
  parseUsersCsv,
  validateUser,
} from './usuariosCsv'
import { filterUsers } from './usuariosFiltering'
import { updateUserDraft } from './usuariosEmail'

const emptyUser = Object.fromEntries(
  columns.map((key) => [key, key === 'tipo_personal' ? 'docente' : '']),
)
const labels = {
  numero_doc_identidad: 'DNI',
  nombres: 'Nombres',
  apellido_paterno: 'Apellido paterno',
  apellido_materno: 'Apellido materno',
  email_institucional: 'Correo institucional',
  regimen_laboral: 'Régimen laboral',
  telefono: 'Teléfono',
}

export default function UsersPage() {
  const { users, setUsers, loading, loadError, refreshUsers: refreshData } = useUsers()
  const [tab, setTab] = useState('csv')
  const [draft, setDraft] = useState(emptyUser)
  const [batch, setBatch] = useState([])
  const [filename, setFilename] = useState('')
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState(null)
  const [searchParams, setSearchParams] = useSearchParams()
  const query = searchParams.get('q') || ''
  function setQuery(value) {
    setSearchParams(value ? { q: value } : {}, { replace: true })
  }
  const [filter, setFilter] = useState('todos')
  const [statusFilter, setStatusFilter] = useState('todos')
  const [saving, setSaving] = useState(false)
  const savingRef = useRef(false)
  const fileInput = useRef(null)
  function refreshUsers() {
    if (!savingRef.current) refreshData()
  }

  async function persistUsers(records) {
    if (savingRef.current || loading || loadError) return false
    savingRef.current = true
    setSaving(true)
    setMessage(null)
    try {
      const saved = await saveUsers(records)
      setUsers((previous) => [...saved, ...previous])
      setMessage({
        text: `${saved.length} registro(s) guardado(s) en la base de datos.`,
      })
      return true
    } catch (error) {
      setMessage({ error: true, text: apiErrorMessage(error) })
      return false
    } finally {
      savingRef.current = false
      setSaving(false)
    }
  }

  const checked = batch.map((user, index) => {
    const errors = validateUser(user)
    const previous = [...users, ...batch.slice(0, index)]
    if (
      previous.some(
        (item) => item.numero_doc_identidad === user.numero_doc_identidad,
      )
    )
      errors.push('DNI duplicado.')
    if (
      previous.some(
        (item) =>
          item.email_institucional.toLowerCase() ===
          user.email_institucional.toLowerCase(),
      )
    )
      errors.push('Correo duplicado.')
    return { user, errors }
  })
  const invalid = checked.filter((item) => item.errors.length).length
  const visible = filterUsers(users, {
    query,
    tipoPersonal: filter,
    estado: statusFilter,
  })

  async function readFile(file) {
    if (!file || busy || savingRef.current) return
    setMessage(null)
    setBatch([])
    setFilename('')
    setBusy(true)
    try {
      if (!/\.csv$/i.test(file.name))
        throw new Error('Selecciona un archivo CSV.')
      if (file.size > 2 * 1024 * 1024)
        throw new Error('El archivo supera el límite de 2 MB.')
      const parsed = parseUsersCsv(await file.text())
      setBatch(parsed)
      setFilename(file.name)
    } catch (error) {
      setMessage({ error: true, text: error.message })
    } finally {
      setBusy(false)
      if (fileInput.current) fileInput.current.value = ''
    }
  }

  async function addManual(event) {
    event.preventDefault()
    const user = Object.fromEntries(
      Object.entries(draft).map(([key, value]) => [key, value.trim()]),
    )
    const errors = validateUser(user)
    if (
      users.some(
        (item) =>
          item.numero_doc_identidad === user.numero_doc_identidad ||
          item.email_institucional.toLowerCase() ===
            user.email_institucional.toLowerCase(),
      )
    )
      errors.push('Ya existe un usuario con ese DNI o correo.')
    if (errors.length)
      return setMessage({ error: true, text: errors.join(' ') })
    if (await persistUsers([user])) setDraft(emptyUser)
  }

  async function importBatch() {
    if (!batch.length || invalid || busy) return
    if (await persistUsers(batch)) {
      setBatch([])
      setFilename('')
    }
  }

  return (
    <div className="font-['DM_Sans',sans-serif] text-sm text-[#243f43] antialiased selection:bg-[#b7e7df] selection:text-[#0c494b] [&_button]:cursor-pointer [&_button:disabled]:cursor-not-allowed [&_button:disabled]:opacity-50 [&_:focus-visible]:outline-3 [&_:focus-visible]:outline-offset-3 [&_:focus-visible]:outline-[#57a69a] [&_h1]:font-['Manrope',sans-serif] [&_h2]:font-['Manrope',sans-serif] [&_h2]:text-[15px] [&_h2]:font-semibold [&_h2]:tracking-tight [&_input:not([type=file])]:min-w-0 [&_input:not([type=file])]:rounded-lg [&_input:not([type=file])]:border [&_input:not([type=file])]:border-[#dce7e0] [&_input:not([type=file])]:bg-[#fcfdfc] [&_input:not([type=file])]:p-3 [&_input:not([type=file])]:text-[#3f6251] [&_select]:min-w-0 [&_select]:rounded-lg [&_select]:border [&_select]:border-[#dce7e0] [&_select]:bg-[#fcfdfc] [&_select]:p-3 [&_select]:text-[#3f6251] [&_table]:w-full [&_table]:border-collapse [&_table]:text-left [&_table]:text-[11px] [&_th]:border-y [&_th]:border-[#edf2ee] [&_th]:bg-[#f7faf8] [&_th]:px-6 [&_th]:py-3.5 [&_th]:font-medium [&_th]:whitespace-nowrap [&_th]:text-[#7b9181] [&_td]:max-w-80 [&_td]:border-b [&_td]:border-[#edf2ee] [&_td]:px-6 [&_td]:py-4 [&_td]:wrap-anywhere">
          <div className="mb-6 flex items-start justify-between gap-4 md:items-center [&>a]:hidden md:[&>a]:inline-flex [&_h1]:mb-2.5 [&_h1]:text-[27px] [&_h1]:leading-tight [&_h1]:font-bold [&_h1]:tracking-tight md:[&_h1]:text-3xl [&>div>p:last-child]:text-xs [&>div>p:last-child]:leading-relaxed [&>div>p:last-child]:text-[#82938e]">
            <div>
              <p className="mb-2.5 text-[9px] font-bold tracking-[1.8px] text-[#719087]">
                DIRECTORIO INSTITUCIONAL
              </p>
              <h1>Carga de usuarios</h1>
              <p>
                Agrega y revisa la información del personal en un solo lugar.
              </p>
            </div>
            <a
              className="inline-flex items-center justify-center gap-2 rounded-lg border px-4.5 py-3 text-xs font-semibold transition-colors border-[#dbe6e1] bg-white text-[#4f6d63] hover:bg-[#edf6f2]"
              href="#usuarios"
            >
              <Icon name="users" size={18} /> Ver usuarios
            </a>
          </div>
          <div className="mb-6 rounded-lg border border-[#e4dfc7] bg-[#faf8ef] px-4 py-3 text-[11px] leading-relaxed text-[#827347]">
            <strong>Desarrollo local.</strong> Los registros se guardan en la
            base de datos. El acceso administrativo aún no tiene autenticación.
            Los nuevos usuarios quedan inactivos hasta implementar su
            activación.
          </div>
          <section
            className="mb-7 grid grid-cols-3 gap-2 md:gap-4.5 [&_article]:rounded-xl [&_article]:border [&_article]:border-[#e4ebe8] [&_article]:bg-white [&_article]:p-3 md:[&_article]:flex md:[&_article]:items-center md:[&_article]:gap-4 md:[&_article]:p-5 [&_p]:mb-1.5 [&_p]:min-h-7 [&_p]:text-[10px] [&_p]:text-[#7f928b] md:[&_p]:min-h-0 md:[&_p]:text-[11px] [&_strong]:font-['Manrope',sans-serif] [&_strong]:text-2xl md:[&_strong]:text-[26px] [&_article:nth-child(2)>span]:bg-[#eff3fa] [&_article:nth-child(2)>span]:text-[#6384ad] [&_article:nth-child(3)>span]:bg-[#faf5e8] [&_article:nth-child(3)>span]:text-[#b3995e]"
            aria-label="Resumen de usuarios"
          >
            {[
              ['Usuarios registrados', users.length, 'users'],
              [
                'Personal docente',
                users.filter((user) => user.tipo_personal === 'docente').length,
                'file',
              ],
              [
                'Personal administrativo',
                users.filter((user) => user.tipo_personal === 'administrativo')
                  .length,
                'check',
              ],
            ].map(([label, count, icon]) => (
              <article key={label}>
                <span className="mb-3 grid size-8 shrink-0 place-items-center rounded-xl bg-[#edf6f1] text-[#3c8d7b] md:mb-0 md:size-11.5">
                  <Icon name={icon} />
                </span>
                <div>
                  <p>{label}</p>
                  <strong>{count.toString().padStart(2, '0')}</strong>
                </div>
              </article>
            ))}
          </section>

          <div
            className="grid scroll-mt-5 grid-cols-1 gap-6"
            id="carga"
          >
            <section className="overflow-hidden rounded-xl border border-[#e0e9e4] bg-white">
              <div className="flex items-center gap-3 px-4 py-5 md:p-6 [&_p]:mt-1.5 [&_p]:text-[11px] [&_p]:leading-relaxed [&_p]:text-[#889991]">
                <span className="grid size-8 shrink-0 place-items-center rounded-lg bg-[#edf5f1] text-[11px] text-[#518775]">
                  01
                </span>
                <div>
                  <h2>Agregar información</h2>
                  <p>Elige cómo deseas cargar los datos de los usuarios.</p>
                </div>
              </div>
              <div
                className="flex gap-6 border-b border-[#e9efec] px-6"
                aria-label="Método de carga"
              >
                <button
                  type="button"
                  aria-pressed={tab === 'csv'}
                  className="flex items-center gap-2 border-b-2 pb-3.5 text-xs aria-pressed:border-[#297d6a] aria-pressed:font-semibold aria-pressed:text-[#297d6a] border-transparent text-[#8a9b93]"
                  onClick={() => setTab('csv')}
                >
                  <Icon name="upload" size={17} /> Importar CSV
                </button>
                <button
                  type="button"
                  aria-pressed={tab === 'manual'}
                  className="flex items-center gap-2 border-b-2 pb-3.5 text-xs aria-pressed:border-[#297d6a] aria-pressed:font-semibold aria-pressed:text-[#297d6a] border-transparent text-[#8a9b93]"
                  onClick={() => setTab('manual')}
                >
                  <Icon name="plus" size={17} /> Registro manual
                </button>
              </div>
              {tab === 'csv' ? (
                <div className="px-4 py-5 md:p-6">
                  <div
                    className="rounded-xl border-2 border-dashed border-[#bbd4c8] bg-[#fbfdfc] px-4 pt-7 pb-6 text-center [&_h3]:mb-2 [&_h3]:text-[15px] [&_h3]:font-semibold [&_p]:mb-4.5 [&_p]:text-xs [&_p]:text-[#8a9b93] [&_small]:mt-4 [&_small]:block [&_small]:text-[10px] [&_small]:text-[#93a399]"
                    onDragOver={(event) => event.preventDefault()}
                    onDrop={(event) => {
                      event.preventDefault()
                      readFile(event.dataTransfer.files[0])
                    }}
                    aria-busy={busy}
                  >
                    <span className="mx-auto mb-4 grid size-15 place-items-center rounded-2xl bg-[#eaf4ee] text-[#448c75]">
                      <Icon name="upload" size={30} />
                    </span>
                    <h3>
                      {busy ? 'Leyendo archivo…' : 'Arrastra tu archivo aquí'}
                    </h3>
                    <p>o selecciónalo desde tu equipo</p>
                    <button
                      className="inline-flex items-center justify-center gap-2 rounded-lg border px-4.5 py-3 text-xs font-semibold transition-colors border-transparent bg-[#26776d] text-white hover:bg-[#1b625a]"
                      disabled={busy || saving}
                      onClick={() => fileInput.current.click()}
                    >
                      Seleccionar archivo
                    </button>
                    <small>
                      CSV en UTF-8 · Hasta 2 MB · Máximo 1,000 usuarios
                    </small>
                    <input
                      ref={fileInput}
                      type="file"
                      accept=".csv,text/csv"
                      hidden
                      onChange={(event) => readFile(event.target.files[0])}
                    />
                  </div>
                  <div className="mt-5 flex flex-wrap items-center justify-between gap-3 md:flex-nowrap [&>span]:flex [&>span]:items-center [&>span]:gap-2.5 [&>span]:text-[#648776] [&_strong]:block [&_strong]:text-[11px] [&_strong]:text-[#566f61] [&_small]:mt-1 [&_small]:block [&_small]:text-[10px] [&_small]:text-[#8b9e92] [&_button]:text-[11px] [&_button]:whitespace-nowrap [&_button]:text-[#36836b]">
                    <span>
                      <Icon name="file" />
                      <span>
                        <strong>Empieza con nuestra plantilla</strong>
                        <small>
                          Incluye las columnas necesarias para la carga.
                        </small>
                      </span>
                    </span>
                    <button
                      type="button"
                      onClick={() => downloadCsv([], 'plantilla_usuarios.csv')}
                    >
                      Descargar CSV ↓
                    </button>
                  </div>
                </div>
              ) : (
                <form
                  className="grid grid-cols-1 gap-4 px-4 py-5 md:grid-cols-2 md:p-6 [&_label]:flex [&_label]:flex-col [&_label]:gap-2 [&_label]:text-[11px] [&_label]:text-[#557566]"
                  onSubmit={addManual}
                  aria-busy={saving}
                >
                  <fieldset
                    disabled={saving || loading || !!loadError}
                    className="contents"
                  >
                    {Object.entries(labels).map(([key, label]) => (
                      <label key={key}>
                        {label}
                        {!['apellido_materno', 'telefono'].includes(key) &&
                          ' *'}
                        <input
                          name={key}
                          placeholder={key === 'email_institucional' ? 'nombre-apellido@uniq.edu.pe' : undefined}
                          value={draft[key]}
                          type={
                            key === 'email_institucional' ? 'email' : 'text'
                          }
                          inputMode={
                            key === 'numero_doc_identidad'
                              ? 'numeric'
                              : undefined
                          }
                          maxLength={
                            key === 'numero_doc_identidad'
                              ? 8
                              : key === 'regimen_laboral' || key === 'telefono'
                                ? 20
                                : key === 'nombres'
                                  ? 120
                                  : key === 'email_institucional'
                                    ? 255
                                    : 80
                          }
                          required={
                            !['apellido_materno', 'telefono'].includes(key)
                          }
                          onChange={(event) =>
                            setDraft(updateUserDraft(draft, key, event.target.value))
                          }
                        />
                        {key === 'email_institucional' && (
                          <small>Se completa con el primer nombre y apellido paterno. Puedes editarlo.</small>
                        )}
                      </label>
                    ))}
                    <label>
                      Tipo de personal *
                      <select
                        value={draft.tipo_personal}
                        onChange={(event) =>
                          setDraft({
                            ...draft,
                            tipo_personal: event.target.value,
                          })
                        }
                      >
                        <option value="docente">Docente</option>
                        <option value="administrativo">Administrativo</option>
                      </select>
                    </label>
                    <div className="col-span-full flex items-center justify-between gap-2.5 [&_small]:text-[10px] [&_small]:text-[#8b9c91]">
                      <small>* Campos obligatorios</small>
                      <button
                        className="inline-flex items-center justify-center gap-2 rounded-lg border px-4.5 py-3 text-xs font-semibold transition-colors border-transparent bg-[#26776d] text-white hover:bg-[#1b625a]"
                        type="submit"
                      >
                        <Icon name="plus" size={17} />{' '}
                        {saving ? 'Guardando…' : 'Guardar usuario'}
                      </button>
                    </div>
                  </fieldset>
                </form>
              )}
            </section>

          </div>

          <div
            className="mt-5 flex flex-wrap items-center gap-3"
            aria-live="polite"
          >
            <button
              type="button"
              onClick={refreshUsers}
              disabled={loading || saving}
              className="rounded-lg border border-[#dbe6e1] bg-white px-4 py-2 text-xs text-[#4f6d63]"
            >
              {loading ? 'Cargando usuarios…' : 'Actualizar lista'}
            </button>
            {loadError && (
              <p role="alert" className="text-xs text-red-700">
                {loadError}
              </p>
            )}
          </div>
          {message && (
            <div
              className={`mt-5 rounded-lg px-5 py-4 text-xs leading-relaxed ${message.error ? 'bg-[#fff0ed] text-[#ac4d3a]' : 'bg-[#e4f2e9] text-[#2c7650]'}`}
              role={message.error ? 'alert' : 'status'}
            >
              {message.text}
            </div>
          )}
          {!!batch.length && (
            <section className="overflow-hidden rounded-xl border border-[#e0e9e4] bg-white mt-6 scroll-mt-5 [&>p]:px-6">
              <div className="flex flex-col items-start justify-between gap-4 px-4 py-5 md:flex-row md:items-center md:p-6 [&_h2]:wrap-anywhere [&_p]:mt-1.5 [&_p]:text-[11px] [&_p]:leading-relaxed [&_p]:text-[#889991]">
                <div>
                  <h2>Vista previa · {filename}</h2>
                  <p>
                    {batch.length} registros · {invalid} con errores. Se
                    muestran los primeros 20.
                  </p>
                </div>
                <button
                  className="inline-flex items-center justify-center gap-2 rounded-lg border px-4.5 py-3 text-xs font-semibold transition-colors border-[#dbe6e1] bg-white text-[#4f6d63] hover:bg-[#edf6f2]"
                  disabled={saving}
                  onClick={() => {
                    setBatch([])
                    setFilename('')
                  }}
                >
                  Descartar
                </button>
              </div>
              <div className="overflow-x-auto">
                <table>
                  <thead>
                    <tr>
                      <th>Registro</th>
                      <th>DNI</th>
                      <th>Usuario</th>
                      <th>Validación</th>
                    </tr>
                  </thead>
                  <tbody>
                    {checked.slice(0, 20).map(({ user, errors }, i) => (
                      <tr key={i}>
                        <td>{i + 1}</td>
                        <td>{user.numero_doc_identidad}</td>
                        <td>
                          {user.nombres} {user.apellido_paterno}
                        </td>
                        <td
                          className={
                            errors.length
                              ? 'text-xs leading-relaxed text-[#ae4736]'
                              : ''
                          }
                        >
                          {errors.length ? errors.join(' ') : 'Correcto'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {invalid > 0 && (
                <p
                  className="text-xs leading-relaxed text-[#ae4736]"
                  role="alert"
                >
                  Corrige el archivo y vuelve a seleccionarlo. No se importará
                  ningún registro mientras existan errores.
                  {checked.filter((item) => item.errors.length).length > 0 && (
                    <span>
                      {' '}
                      Primer error: registro{' '}
                      {checked.findIndex((item) => item.errors.length) +
                        1},{' '}
                      {checked
                        .find((item) => item.errors.length)
                        .errors.join(' ')}
                    </span>
                  )}
                </p>
              )}
              <div className="flex justify-end px-6 py-5">
                <button
                  className="inline-flex items-center justify-center gap-2 rounded-lg border px-4.5 py-3 text-xs font-semibold transition-colors border-transparent bg-[#26776d] text-white hover:bg-[#1b625a]"
                  disabled={
                    !!invalid || busy || saving || loading || !!loadError
                  }
                  onClick={importBatch}
                >
                  {saving ? 'Guardando…' : `Guardar ${batch.length} usuarios`}
                </button>
              </div>
            </section>
          )}

          <section
            className="overflow-hidden rounded-xl border border-[#e0e9e4] bg-white mt-6 scroll-mt-5"
            id="usuarios"
          >
            <div className="flex flex-col items-start justify-between gap-4 px-4 py-5 md:flex-row md:items-center md:p-6 [&_h2]:wrap-anywhere [&_p]:mt-1.5 [&_p]:text-[11px] [&_p]:leading-relaxed [&_p]:text-[#889991]">
              <div>
                <h2>
                  Usuarios registrados{' '}
                  <span className="ml-1 inline-block rounded-md bg-[#edf5ef] px-2 py-1 align-middle text-[10px] text-[#60856d]">
                    {users.length}
                  </span>
                </h2>
                <p>Consulta los registros guardados en la base de datos.</p>
              </div>
              <button
                className="inline-flex items-center justify-center gap-2 rounded-lg border px-4.5 py-3 text-xs font-semibold transition-colors border-[#dbe6e1] bg-white text-[#4f6d63] hover:bg-[#edf6f2]"
                disabled={!users.length}
                onClick={() => downloadCsv(users, 'usuarios.csv')}
              >
                Descargar datos ↓
              </button>
            </div>
            <div className="flex flex-col gap-3.5 px-4 pb-5 md:flex-row md:px-6 [&_select]:text-[11px] md:[&_select]:ml-auto">
              <label className="flex flex-1 items-center gap-2.5 rounded-lg border border-[#dce7e0] px-3 text-[#8da294] md:max-w-105 [&_input]:w-full [&_input]:border-0! [&_input]:bg-transparent! [&_input]:pl-0! [&_input]:text-[11px]">
                <Icon name="search" size={18} />
                <input
                  aria-label="Buscar usuarios"
                  placeholder="Buscar por nombre, DNI o correo…"
                  value={query}
                  onChange={(event) => setQuery(event.target.value)}
                />
              </label>
              <select
                aria-label="Filtrar por tipo de personal"
                value={filter}
                onChange={(event) => setFilter(event.target.value)}
              >
                <option value="todos">Todo el personal</option>
                <option value="docente">Docentes</option>
                <option value="administrativo">Administrativos</option>
              </select>
              <select
                aria-label="Filtrar por estado"
                value={statusFilter}
                onChange={(event) => setStatusFilter(event.target.value)}
              >
                <option value="todos">Todo estado</option>
                <option value="activo">Activos</option>
                <option value="inactivo">Inactivos</option>
              </select>
            </div>
            <div className="overflow-x-auto">
              <table>
                <thead>
                  <tr>
                    <th>Usuario</th>
                    <th>DNI</th>
                    <th>Correo institucional</th>
                    <th>Tipo de personal</th>
                    <th>Régimen laboral</th>
                    <th>Estado</th>
                  </tr>
                </thead>
                <tbody>
                  {visible.map((user) => (
                    <tr key={user.numero_doc_identidad}>
                      <td>
                        <Link to={`/legajos/${user.id}`} className="font-semibold text-teal-800 hover:underline">
                          {user.nombres} {user.apellido_paterno}{' '}
                          {user.apellido_materno}
                        </Link>
                      </td>
                      <td>{user.numero_doc_identidad}</td>
                      <td>{user.email_institucional}</td>
                      <td>
                        <span
                          className={`rounded-full px-2.5 py-1 text-[10px] ${user.tipo_personal === 'administrativo' ? 'bg-[#f6f0e1] text-[#9e8750]' : 'bg-[#eaf3f9] text-[#557f9b]'}`}
                        >
                          {user.tipo_personal === 'docente'
                            ? 'Docente'
                            : 'Administrativo'}
                        </span>
                      </td>
                      <td>{user.regimen_laboral}</td>
                      <td>
                        <span
                          className={`rounded-full px-2.5 py-1 text-[10px] ${user.activo ? 'bg-[#eaf4ee] text-[#3d8060]' : 'bg-[#faf3e5] text-[#9b7a37]'}`}
                        >
                          {user.activo ? 'Activo' : 'Inactivo'}
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {!loading && !loadError && !visible.length && (
              <div className="px-5 py-9 text-center text-[#9ab3a3] [&>svg]:mx-auto [&_h3]:mt-3 [&_h3]:mb-2 [&_h3]:text-[13px] [&_h3]:font-medium [&_h3]:text-[#657f6e] [&_p]:text-[11px] [&_p]:text-[#96a69b]">
                <Icon name="users" size={32} />
                <h3>
                  {users.length
                    ? 'No se encontraron usuarios'
                    : 'Tu lista de usuarios empieza aquí'}
                </h3>
                <p>
                  {users.length
                    ? 'Prueba con otro nombre o cambia el filtro.'
                    : 'Importa un archivo CSV o agrega tu primer usuario manualmente.'}
                </p>
              </div>
            )}
            <footer className="border-t border-[#edf2ee] px-6 py-4 text-[10px] text-[#92a296]">
              {visible.length} de {users.length} usuarios · Datos guardados
            </footer>
          </section>
    </div>
  )
}
