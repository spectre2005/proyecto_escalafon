import AuditPanel from '../../../shared/AuditPanel'
import { consultarAuditoria, exportarAuditoria } from '../api'

export default function AuditoriaPage() {
  return <AuditPanel plataforma load={consultarAuditoria} download={exportarAuditoria} />
}
