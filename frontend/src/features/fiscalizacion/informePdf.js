import { detalleMedio, mediosConsulta } from './mediosConsulta'
import { jsPDF } from 'jspdf'
import { fecha, fuentes, nombreDocumento, persona, resultados, resultadosFuente, tecnicos } from './config'

export function descargarInforme(datos) {
  const { data: proceso, alcance, consultas, derivaciones, revisor, cierre_responsable } = datos
  const pdf = new jsPDF()
  let y = 20
  function escribir(valor, titulo = false) {
    pdf.setFont('helvetica', titulo ? 'bold' : 'normal').setFontSize(titulo ? 11 : 9)
    for (const linea of pdf.splitTextToSize(String(valor || 'No registrado'), 170)) {
      if (y > 275) { pdf.addPage(); y = 20 }
      pdf.text(linea, 20, y); y += 4.7
    }
    y += 3
  }
  escribir(proceso.estado === 'cerrado' ? 'RESUMEN DEL PROCESO DE FISCALIZACIÓN' : 'BORRADOR PARA REVISIÓN - FISCALIZACIÓN', true)
  escribir(`Código: ${proceso.codigo || proceso.id}\nServidor: ${persona(proceso.usuario)}\nLegajo: ${proceso.legajo?.numero_legajo || 'No registrado'}\nResponsable: ${persona(proceso.responsable)}\nRevisión: ${cierre_responsable ? `Cierre individual por ${persona(proceso.responsable)}` : revisor ? persona(revisor) : 'Sin delegación registrada'}`)
  escribir(`Inicio: ${fecha(proceso.fecha_inicio)} | Límite: ${fecha(proceso.fecha_limite)} | Cierre: ${fecha(proceso.fecha_fin)}`)
  escribir('Motivo y alcance', true); escribir(proceso.motivo)
  escribir('Fundamento', true); escribir(proceso.fundamento)
  alcance.forEach((item, indice) => {
    escribir(`${indice + 1}. ${nombreDocumento(item.datos_snapshot)}`, true)
    escribir(`Emisor: ${item.datos_snapshot.emisor || 'No registrado'}\nHuella de referencia: ${item.hash_sha256 || 'No registrada'}`)
    for (const v of item.verificaciones) {
      escribir(`${fuentes[v.fuente] || v.fuente}: ${tecnicos[v.estado_tecnico] || v.estado_tecnico} / ${resultadosFuente[v.resultado_fuente] || v.resultado_fuente}\nFecha: ${v.created_at}\n${v.detalle.mensaje || ''}`)
      if (v.detalle.proveedor) {
        escribir(`Proveedor: ${v.detalle.proveedor} | DNI: ${v.detalle.dni_consultado} | Fecha fuente: ${v.detalle.fecha_fuente || 'No informada'}`)
        for (const r of v.detalle.registros || []) escribir(`${r.grado_o_titulo} | ${r.institucion_educativa || r.universidad || ''} | ${r.fecha_emision || r.fecha_diploma || ''} | ${r.codigo_minedu || r.resolucion || ''}`)
        for (const [fuente, estado] of Object.entries(v.detalle.fuentes || {})) escribir(`${fuente}: ${estado || 'Sin estado'}`)
      }
    }
    for (const c of consultas.filter(c => c.documento_id === item.documento_id)) {
      escribir(`Consulta a ${c.entidad_consultada} - ${c.numero_oficio || 'Sin referencia'}\nEnvío: ${fecha(c.fecha_envio)} | Respuesta: ${fecha(c.fecha_respuesta)}\nResultado: ${resultados[c.resultado] || c.resultado}\n${c.descripcion_hallazgo || 'Respuesta pendiente.'}`)
      escribir(`Medio: ${mediosConsulta[c.medio]?.nombre || c.medio}`)
      for (const d of detalleMedio(c)) escribir(`${d.label}: ${d.valor}`)
      if (c.datos_medio?.mensaje_id) escribir(`Envío confirmado por el proveedor: ${c.datos_medio.mensaje_id}`)
      if (c.datos_medio?.cuerpo) escribir(c.datos_medio.cuerpo)
    }
    escribir(`Evaluación: ${resultados[item.resultado] || item.resultado}\n${item.conclusion || 'Sin evaluar'}\nEvidencia documental: ${item.evidencia_documento_id || 'No registrada'}`)
  })
  escribir('Conclusiones y recomendaciones', true); escribir(proceso.conclusiones)
  escribir('Revisión', true); escribir(proceso.revision_observaciones)
  for (const d of derivaciones) escribir(`Derivación: ${d.area} - ${d.referencia}\n${d.motivo}\nEnvío: ${fecha(d.fecha_envio)} | Cargo: ${fecha(d.fecha_cargo)}`)
  escribir('Este resumen no incorpora firma digital ni sustituye el informe técnico emitido por la autoridad responsable.')
  pdf.save(`${proceso.codigo || 'Fiscalizacion'}-${proceso.estado === 'cerrado' ? 'resumen' : 'borrador'}.pdf`)
}
