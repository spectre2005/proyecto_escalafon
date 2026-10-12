import DownloadPersonalReport from '../../reportes/components/DownloadPersonalReport'
import { advanceOnEnter } from '../../../shared/forms/advanceOnEnter'
import { useEffect, useRef, useState } from 'react'
import api, { apiErrorMessage } from '../../../axios'
import { downloadUsersTemplate, parseUsersFile, resolveUsersLocations } from '../utils/usuariosExcel'
import { saveUsers } from '../api/usuariosApi'
import { useUsers } from '../hooks/useUsers'
import Icon from '../../../components/ui/Icon'
import UbigeoFields from '../../../components/ui/UbigeoFields'
import { Link, useSearchParams } from 'react-router-dom'
import {
  columns,
  validateUser,
} from '../utils/usuariosCsv'
import { filterUsers } from '../utils/usuariosFiltering'
import { updateUserDraft } from '../utils/usuariosEmail'
import { regimenesPorTipo, regimenInicial } from '../utils/regimenesLaborales'
import TresEstados from '../../../components/ui/TresEstados'
import { usePuede } from '../../auth/session'

const emptyUser = {
  ...Object.fromEntries(columns.map((key) => [key, ''])),
  tipo_personal: 'docente',
  regimen_laboral: regimenInicial('docente'),
}
// Banderas de la persona en el alta: Sí / No / Sin responder (por defecto, nunca No). Las del puesto se registran
// en el vínculo laboral. con_discapacidad exige discapacidad.editar (el backend lo valida y audita).
const condicionesVacias = { tiene_derechohabientes: null, con_discapacidad: null }
const labels = {
  numero_doc_identidad: 'DNI',
  nombres: 'Nombres',
  apellido_paterno: 'Apellido paterno',
  apellido_materno: 'Apellido materno',
  email_institucional: 'Correo institucional',
  telefono: 'Teléfono',
}

export default function UsersPage() {
  const { users, setUsers, loading, loadError } = useUsers()
  const [tab, setTab] = useState('manual')
  const [registrationOpen, setRegistrationOpen] = useState(false)
  const registrationDialog = useRef(null)
  const [draft, setDraft] = useState(emptyUser)
  const [condiciones, setCondiciones] = useState(condicionesVacias)
  const puedeDiscapacidad = usePuede('discapacidad.editar')
  const [location, setLocation] = useState({ region: '', provincia: '', distrito: '' })
  const [ubigeoId, setUbigeoId] = useState('')
  const [batch, setBatch] = useState([])
  const [filename, setFilename] = useState('')
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState(null)
  const [searchParams, setSearchParams] = useSearchParams()
  const query = searchParams.get('q') || ''
  function updateFilter(key, value) {
    const next = new URLSearchParams(searchParams)
    if (value && value !== 'todos') next.set(key, value)
    else next.delete(key)
    next.delete('page')
    setSearchParams(next, { replace: true })
  }
  function setQuery(value) { updateFilter('q', value) }
  const filter = ['docente', 'administrativo'].includes(searchParams.get('tipo')) ? searchParams.get('tipo') : 'todos'
  const statusFilter = ['activo', 'inactivo'].includes(searchParams.get('estado')) ? searchParams.get('estado') : 'todos'
  const contactFilter = ['con', 'sin'].includes(searchParams.get('correo')) ? searchParams.get('correo') : 'todos'
  const [pageSize, setPageSize] = useState(10)
  function changePage(page) {
    const next = new URLSearchParams(searchParams)
    if (page > 1) next.set('page', String(page))
    else next.delete('page')
    setSearchParams(next, { replace: true })
  }
  const [saving, setSaving] = useState(false)
  const savingRef = useRef(false)
  const fileInput = useRef(null)

  useEffect(() => {
    if (!registrationOpen) return
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => { document.body.style.overflow = previousOverflow }
  }, [registrationOpen])

  function openRegistration() {
    setMessage(null)
    setRegistrationOpen(true)
    registrationDialog.current.showModal()
  }

  function closeRegistration() {
    if (savingRef.current || busy) return
    registrationDialog.current.close()
  }

  async function persistUsers(records) {
    if (savingRef.current || loading || loadError) return false
    savingRef.current = true
    setSaving(true)
    setMessage(null)
    try {
      const saved = await saveUsers(records)
      setUsers((previous) => [...saved, ...previous.filter(user => !saved.some(record => record.id === user.id))])
      changePage(1)
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
    correo: contactFilter,
  })
  const totalPages = Math.max(1, Math.ceil(visible.length / pageSize))
  const requestedPage = Number(searchParams.get('page'))
  const currentPage = Number.isInteger(requestedPage) && requestedPage > 0
    ? Math.min(requestedPage, totalPages) : 1
  const firstIndex = (currentPage - 1) * pageSize
  const pageUsers = visible.slice(firstIndex, firstIndex + pageSize)

  async function readFile(file) {
    if (!file || busy || savingRef.current) return
    setMessage(null)
    setBatch([])
    setFilename('')
    setBusy(true)
    try {
      if (!/\.(xlsx|csv)$/i.test(file.name)) throw new Error('Selecciona un archivo Excel (.xlsx) o CSV (.csv).')
      if (file.size > 2 * 1024 * 1024) throw new Error('El archivo supera el límite de 2 MB.')
      const records = await parseUsersFile(file)
      const needsLocations = records.some(record => record.region || record.provincia || record.distrito)
      const locations = needsLocations ? (await api.get('/ubicaciones?completo=1')).data.data : []
      const parsed = resolveUsersLocations(records, locations)
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
    if (Object.values(location).some(Boolean) && !ubigeoId) errors.push('Selecciona región, provincia y distrito para completar la ubicación.')
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
    const respuestas = { tiene_derechohabientes: condiciones.tiene_derechohabientes, con_discapacidad: puedeDiscapacidad ? condiciones.con_discapacidad : null }
    if (await persistUsers([{ ...user, ubigeo_id: ubigeoId || null, ...respuestas }])) {
      setDraft(emptyUser)
      setCondiciones(condicionesVacias)
      setLocation({ region: '', provincia: '', distrito: '' })
      setUbigeoId('')
      registrationDialog.current.close()
    }
  }

  async function importBatch() {
    if (!batch.length || invalid || busy) return
    if (await persistUsers(batch)) {
      setBatch([])
      setFilename('')
      registrationDialog.current.close()
    }
  }

  return (
    <div className="font-['DM_Sans',sans-serif] text-sm text-[#243f43] antialiased selection:bg-[#b7e7df] selection:text-[#0c494b] [&_button]:cursor-pointer [&_button:disabled]:cursor-not-allowed [&_button:disabled]:opacity-50 [&_:focus-visible]:outline-3 [&_:focus-visible]:outline-offset-3 [&_:focus-visible]:outline-[#57a69a] [&_h1]:font-['Manrope',sans-serif] [&_h2]:font-['Manrope',sans-serif] [&_h2]:text-[15px] [&_h2]:font-semibold [&_h2]:tracking-tight [&_input:not([type=file])]:min-w-0 [&_input:not([type=file])]:rounded-lg [&_input:not([type=file])]:border [&_input:not([type=file])]:border-[#dce7e0] [&_input:not([type=file])]:bg-[#fcfdfc] [&_input:not([type=file])]:p-3 [&_input:not([type=file])]:text-[#3f6251] [&_select]:min-w-0 [&_select]:rounded-lg [&_select]:border [&_select]:border-[#dce7e0] [&_select]:bg-[#fcfdfc] [&_select]:p-3 [&_select]:text-[#3f6251] [&_table]:w-full [&_table]:border-collapse [&_table]:text-left [&_table]:text-[11px] [&_th]:border-y [&_th]:border-[#edf2ee] [&_th]:bg-[#f7faf8] [&_th]:px-6 [&_th]:py-3.5 [&_th]:font-medium [&_th]:whitespace-nowrap [&_th]:text-[#7b9181] [&_td]:max-w-80 [&_td]:border-b [&_td]:border-[#edf2ee] [&_td]:px-6 [&_td]:py-4 [&_td]:wrap-anywhere">
          <div className="mb-6 flex items-start justify-between gap-4 md:items-center [&>a]:hidden md:[&>a]:inline-flex [&_h1]:mb-2.5 [&_h1]:text-[27px] [&_h1]:leading-tight [&_h1]:font-bold [&_h1]:tracking-tight md:[&_h1]:text-3xl [&>div>p:last-child]:text-xs [&>div>p:last-child]:leading-relaxed [&>div>p:last-child]:text-[#82938e]">
            <div>
              <p className="mb-2.5 text-[9px] font-bold tracking-[1.8px] text-[#719087]">
                DIRECTORIO INSTITUCIONAL
              </p>
              <h1>Personal</h1>
              <p>
                Agrega y revisa la información del personal en un solo lugar.
              </p>
            </div>
          </div>
          <div
            className="mt-5 flex flex-wrap items-center gap-3"
            aria-live="polite"
          >
            {loadError && (
              <p role="alert" className="text-xs text-red-700">
                {loadError}
              </p>
            )}
          </div>
          {message && !registrationOpen && (
            <div
              className={`mt-5 rounded-lg px-5 py-4 text-xs leading-relaxed ${message.error ? 'bg-[#fff0ed] text-[#ac4d3a]' : 'bg-[#e4f2e9] text-[#2c7650]'}`}
              role={message.error ? 'alert' : 'status'}
            >
              {message.text}
            </div>
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
              <div className="flex w-full flex-wrap items-center gap-2 md:w-auto">
                <button type="button" onClick={openRegistration} aria-haspopup="dialog" className="inline-flex items-center justify-center gap-2 rounded-lg bg-[#26776d] px-4.5 py-3 text-xs font-semibold text-white hover:bg-[#1b625a]">
                  <Icon name="plus" size={17} /> Agregar personal
                </button>
                <DownloadPersonalReport blank className="inline-flex items-center justify-center gap-2 rounded-lg border px-4.5 py-3 text-xs font-semibold transition-colors border-[#dbe6e1] bg-white text-[#4f6d63] hover:bg-[#edf6f2]" />
              </div>
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
                onChange={(event) => { updateFilter('tipo', event.target.value) }}
              >
                <option value="todos">Todo el personal</option>
                <option value="docente">Docentes</option>
                <option value="administrativo">Administrativos</option>
              </select>
              <select
                aria-label="Filtrar por estado"
                value={statusFilter}
                onChange={(event) => { updateFilter('estado', event.target.value) }}
              >
                <option value="todos">Todo estado</option>
                <option value="activo">Activos</option>
                <option value="inactivo">Inactivos</option>
              </select>
              <select aria-label="Filtrar por correo" value={contactFilter} onChange={event => updateFilter('correo', event.target.value)}>
                <option value="todos">Todo contacto</option>
                <option value="con">Con correo</option>
                <option value="sin">Sin correo</option>
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
                    <th>Reportes</th>
                  </tr>
                </thead>
                <tbody>
                  {pageUsers.map((user) => (
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
                    <td><DownloadPersonalReport usuario={user.id} className="whitespace-nowrap rounded-lg border border-teal-200 px-3 py-2 text-xs font-semibold text-teal-800 hover:bg-teal-50" /></td>
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
                    : 'Importa un archivo Excel o CSV o agrega tu primer usuario manualmente.'}
                </p>
              </div>
            )}
            <footer className="flex flex-wrap items-center justify-between gap-4 border-t border-[#edf2ee] px-4 py-4 text-xs text-[#60796c] md:px-6">
              <div className="flex flex-wrap items-center gap-3">
                <label className="flex items-center gap-2">
                  Usuarios por página
                  <select value={pageSize} onChange={event => { setPageSize(Number(event.target.value)); changePage(1) }}>
                    {[10, 15, 20].map(size => <option key={size} value={size}>{size}</option>)}
                  </select>
                </label>
                <span aria-live="polite">
                  {visible.length ? firstIndex + 1 : 0}–{firstIndex + pageUsers.length} de {visible.length} usuarios
                  {visible.length !== users.length && ` (${users.length} en total)`}
                </span>
              </div>
              <nav aria-label="Paginación de usuarios" className="flex items-center gap-3">
                <button type="button" onClick={() => changePage(currentPage - 1)} disabled={currentPage === 1} className="rounded-lg border border-[#dbe6e1] bg-white px-3 py-2 text-[#4f6d63] hover:bg-[#edf6f2]">Anterior</button>
                <span>Página {currentPage} de {totalPages}</span>
                <button type="button" onClick={() => changePage(currentPage + 1)} disabled={currentPage === totalPages} className="rounded-lg border border-[#dbe6e1] bg-white px-3 py-2 text-[#4f6d63] hover:bg-[#edf6f2]">Siguiente</button>
              </nav>
            </footer>
          </section>

          <dialog
            ref={registrationDialog}
            aria-labelledby="registration-title"
            aria-busy={saving || busy}
            className="fixed inset-0 m-auto max-h-[90dvh] w-[calc(100%_-_2rem)] max-w-3xl overflow-y-auto rounded-2xl border-0 bg-[#f6f8fa] p-0 text-[#243f43] shadow-2xl backdrop:bg-slate-900/50"
            onCancel={event => { if (savingRef.current || busy) event.preventDefault() }}
            onClose={() => setRegistrationOpen(false)}
          >
            {loading && <p role="status" className="px-6 pt-4 text-xs text-[#4f6d63]">Cargando usuarios para comprobar duplicados…</p>}
            {loadError && <p role="alert" className="px-6 pt-4 text-xs text-red-700">{loadError}</p>}
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
                  <h2 id="registration-title">Agregar personal</h2>
                  <p>Elige cómo deseas cargar los datos de los usuarios.</p>
                </div>
                <button type="button" onClick={closeRegistration} disabled={saving || busy} aria-label="Cerrar formulario" className="ml-auto grid size-9 shrink-0 place-items-center rounded-lg text-[#4f6d63] hover:bg-[#edf6f2]">
                  <Icon name="close" size={20} />
                </button>
              </div>
              <div
                className="flex gap-6 border-b border-[#e9efec] px-6"
                aria-label="Método de carga"
              >
                <button
                  type="button"
                  aria-pressed={tab === 'csv'}
                  disabled={saving || busy}
                  className="flex items-center gap-2 border-b-2 pb-3.5 text-xs aria-pressed:border-[#297d6a] aria-pressed:font-semibold aria-pressed:text-[#297d6a] border-transparent text-[#8a9b93]"
                  onClick={() => setTab('csv')}
                >
                  <Icon name="upload" size={17} /> Importar Excel / CSV
                </button>
                <button
                  type="button"
                  aria-pressed={tab === 'manual'}
                  disabled={saving || busy}
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
                      Excel (.xlsx) o CSV en UTF-8 · Hasta 2 MB · Máximo 1,000 usuarios
                    </small>
                    <input
                      ref={fileInput}
                      type="file"
                      accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"
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
                          Campos obligatorios, listas de opciones e instrucciones en Excel.
                        </small>
                      </span>
                    </span>
                    <button
                      type="button"
                      disabled={busy || saving}
                      onClick={async () => {
                        setBusy(true)
                        try { await downloadUsersTemplate() }
                        catch { setMessage({ error: true, text: 'No se pudo descargar la plantilla. Intenta nuevamente.' }) }
                        finally { setBusy(false) }
                      }}
                    >
                      Descargar Excel ↓
                    </button>
                  </div>
                  <p className="mt-4 text-xs leading-relaxed text-[#648776]">
                    Completa la hoja Personal desde la fila 2. Los campos con * son obligatorios.
                    DNI y teléfono deben conservar sus ceros iniciales. La ubicación es opcional;
                    si la incluyes, completa región, provincia y distrito. Revisa los datos antes de guardar.
                  </p>
                </div>
              ) : (
                <form
                  className="grid grid-cols-1 gap-4 px-4 py-5 md:grid-cols-2 md:p-6 [&_label]:flex [&_label]:flex-col [&_label]:gap-2 [&_label]:text-[11px] [&_label]:text-[#557566]"
                  onSubmit={addManual}
                  onKeyDown={advanceOnEnter}
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
                            ['numero_doc_identidad', 'telefono'].includes(key)
                              ? 'numeric'
                              : undefined
                          }
                          maxLength={
                            key === 'numero_doc_identidad'
                              ? 8
                              : key === 'telefono'
                                ? 9
                                : key === 'nombres'
                                  ? 120
                                  : key === 'email_institucional'
                                    ? 255
                                    : 80
                          }
                          required={
                            !['apellido_materno', 'telefono'].includes(key)
                          }
                          pattern={key === 'numero_doc_identidad' ? '[0-9]{8}' : key === 'telefono' ? '[0-9]{9}' : undefined}
                          title={key === 'numero_doc_identidad' ? 'El DNI debe contener 8 dígitos.' : key === 'telefono' ? 'El teléfono debe contener 9 dígitos.' : undefined}
                          onChange={(event) =>
                            setDraft(updateUserDraft(draft, key, ['numero_doc_identidad', 'telefono'].includes(key) ? event.target.value.replace(/\D/g, '').slice(0, key === 'telefono' ? 9 : 8) : event.target.value))
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
                            regimen_laboral: regimenInicial(event.target.value),
                          })
                        }
                      >
                        <option value="docente">Docente</option>
                        <option value="administrativo">Administrativo</option>
                      </select>
                    </label>
                    <label>
                      Régimen laboral *
                      <select
                        name="regimen_laboral"
                        required
                        value={draft.regimen_laboral}
                        onChange={(event) =>
                          setDraft({
                            ...draft,
                            regimen_laboral: event.target.value,
                          })
                        }
                      >
                        <option value="" disabled>
                          Selecciona un régimen
                        </option>
                        {(regimenesPorTipo[draft.tipo_personal] || []).map(
                          (regimen) => (
                            <option key={regimen.value} value={regimen.value}>
                              {regimen.label}
                            </option>
                          ),
                        )}
                      </select>
                    </label>
                    <UbigeoFields value={location} onChange={(next, id) => { setLocation(next); setUbigeoId(id) }} disabled={saving} />
                    <div className="col-span-full grid gap-4 border-t border-slate-100 pt-4 md:grid-cols-2">
                      <TresEstados name="alta-tiene_derechohabientes" label="¿Tiene derechohabientes?" ayuda="Cónyuge, concubino/a o hijos."
                        value={condiciones.tiene_derechohabientes} onChange={valor => setCondiciones(previas => ({ ...previas, tiene_derechohabientes: valor }))} />
                      <TresEstados name="alta-con_discapacidad" label="¿Tiene discapacidad el servidor o alguno de sus hijos?"
                        ayuda={puedeDiscapacidad ? 'Directiva, punto 1 k.' : 'Solo administración de la ORH puede registrarlo.'} disabled={!puedeDiscapacidad}
                        value={condiciones.con_discapacidad} onChange={valor => setCondiciones(previas => ({ ...previas, con_discapacidad: valor }))} />
                      <p className="text-xs text-slate-500 md:col-span-2">Si no lo sabes, deja «Sin responder». Las condiciones del puesto se registran en el vínculo laboral.</p>
                    </div>
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


          {message && (
            <div
              className={`mt-5 rounded-lg px-5 py-4 text-xs leading-relaxed ${message.error ? 'bg-[#fff0ed] text-[#ac4d3a]' : 'bg-[#e4f2e9] text-[#2c7650]'}`}
              role={message.error ? 'alert' : 'status'}
            >
              {message.text}
            </div>
          )}
          {tab === 'csv' && !!batch.length && (
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
                      <th>Correo institucional</th>
                      <th>Tipo de personal</th>
                      <th>Régimen laboral</th>
                      <th>Teléfono</th>
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
                          {' '}{user.apellido_materno}
                        </td>
                        <td>{user.email_institucional}</td>
                        <td>{user.tipo_personal}</td>
                        <td>{user.regimen_laboral}</td>
                        <td>{user.telefono || '—'}</td>
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


            <div className="flex justify-end px-6 py-4">
              <button type="button" onClick={closeRegistration} disabled={saving || busy} className="rounded-lg border border-[#dbe6e1] bg-white px-4 py-2.5 text-xs font-semibold text-[#4f6d63] hover:bg-[#edf6f2]">Cancelar</button>
            </div>
          </dialog>
    </div>
  )
}
