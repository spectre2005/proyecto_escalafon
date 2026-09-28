import './App.css'
import logo from './assets/uniq-logo.png'
import campus from './assets/uniq_logo.jpg'

function Icon({ name, size = 20, ...props }) {
  const paths = {
    mail: <><rect x="3" y="5" width="18" height="14" rx="3" /><path d="m4 7 8 6 8-6" /></>,
    lock: <><rect x="5" y="10" width="14" height="11" rx="3" /><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3" /></>,
    eye: <><path d="M3 3l18 18M10.6 10.6a2 2 0 0 0 2.8 2.8M9.8 5.2A11 11 0 0 1 21 12a14 14 0 0 1-3 4M6.3 6.3A14 14 0 0 0 3 12c4 8 10 8 14 5" /></>,
    arrow: <><path d="M4 12h16m-6-6 6 6-6 6" /></>,
    folder: <><path d="M3 7V5a2 2 0 0 1 2-2h4l3 4h7a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z" /><path d="M3 9h18" /></>,
    chart: <><path d="M4 20h16M6 16v-5m6 5V8m6 8V4M4 7l6-4 5 1 5-3" /></>,
    clock: <><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></>,
    document: <><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9Z" /><path d="M14 3v6h6M8 13h8m-8 4h5" /></>,
    shield: <><path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6Z" /><path d="m8 12 3 3 5-6" /></>,
    help: <><path d="M4 13v-2a8 8 0 0 1 16 0v2M20 17v1a3 3 0 0 1-3 3h-4" /><rect x="2" y="11" width="5" height="7" rx="2" /><rect x="17" y="11" width="5" height="7" rx="2" /></>,
    pin: <><path d="M19 10c0 5-7 11-7 11S5 15 5 10a7 7 0 0 1 14 0Z" /><circle cx="12" cy="10" r="2" /></>,
  }
  return <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" {...props}>{paths[name]}</svg>
}

const features = [
  { icon: 'folder', title: 'Legajo personal', text: 'Tu información, siempre a la mano' },
  { icon: 'chart', title: 'Evaluación y puntajes', text: 'Cada logro cuenta en tu trayectoria' },
  { icon: 'clock', title: 'Historial laboral', text: 'Tu experiencia en un solo lugar' },
  { icon: 'document', title: 'Documentos y resoluciones', text: 'Todo organizado para ti' },
]

export default function App() {
  return (
    <main className="login-page">
      <div className="login-shell">
        <section className="institution-panel" aria-labelledby="welcome-title">
          <img className="campus-image" src={campus} alt="Vista aérea del campus de la UNIQ en Quillabamba" />
          <div className="panel-overlay" />
          <header className="institution-brand">
            <img src={logo} alt="Emblema UNIQ" className="brand-logo" />
            <div><strong>UNIQ</strong><span>Universidad Nacional Intercultural<br />de Quillabamba</span></div>
          </header>
          <div className="institution-content">
            <span className="eyebrow"><span /> SISTEMA DE ESCALAFÓN</span>
            <h1 id="welcome-title">Tu trayectoria.<br />Tus logros.<br /><em>Un solo lugar.</em></h1>
            <p className="welcome-copy">Un espacio para acompañar tu desarrollo y<br className="desktop-break" /> conectar cada etapa de tu vida profesional.</p>
            <div className="features">
              {features.map(({ icon, title, text }) => (
                <div className="feature" key={icon}>
                  <span className="feature-icon"><Icon name={icon} size={22} /></span>
                  <div><h2>{title}</h2><p>{text}</p></div>
                </div>
              ))}
            </div>
          </div>
          <footer className="institution-footer"><span><Icon name="pin" size={15} /> Quillabamba, Cusco · Perú</span><span className="footer-line" /></footer>
        </section>

        <section className="access-panel" aria-labelledby="login-title">
          <div className="access-topline"><span>PORTAL DEL PERSONAL</span><span className="institutional-badge"><span /> Acceso institucional</span></div>
          <div className="login-content">
            <div className="system-brand"><div className="system-logo"><img src={logo} alt="" /></div><div><span>SISTEMA DE</span><strong>Escalafón<span className="brand-dot">.</span></strong><p>PERSONAL DOCENTE Y NO DOCENTE</p></div></div>
            <div className="login-heading"><span className="section-marker" /><p className="small-label">TE DAMOS LA BIENVENIDA</p><h2 id="login-title">Iniciar sesión</h2><p>Ingresa con tu cuenta institucional para acceder<br className="desktop-break" /> a tu información académica y laboral.</p></div>
            <form className="login-form" onSubmit={(event) => event.preventDefault()}>
              <label htmlFor="email">Correo institucional</label>
              <div className="input-wrap"><Icon name="mail" /><input id="email" name="email" type="email" placeholder="nombre@uniq.edu.pe" autoComplete="username" /></div>
              <label htmlFor="password">Contraseña</label>
              <div className="input-wrap"><Icon name="lock" /><input id="password" name="password" type="password" placeholder="Ingresa tu contraseña" autoComplete="current-password" /><span className="password-decoration"><Icon name="eye" /></span></div>
              <div className="form-options"><label className="remember"><input type="checkbox" defaultChecked /> Recordarme</label><button className="text-button" type="button">¿Olvidaste tu contraseña?</button></div>
              <button className="login-button" type="button">Ingresar al sistema <Icon name="arrow" /></button>
            </form>
            <div className="security-note"><Icon name="shield" size={16} /><span>Tu información en un entorno institucional.</span></div>
            <div className="support-card"><span className="support-icon"><Icon name="help" size={23} /></span><div><strong>¿Necesitas ayuda para ingresar?</strong><p>Contacta al área de Escalafón o Recursos Humanos.</p></div></div>
          </div>
          <footer className="access-footer"><span>© {new Date().getFullYear()} UNIQ · Todos los derechos reservados</span><span>Gestión del talento humano</span></footer>
        </section>
      </div>
    </main>
  )
}
