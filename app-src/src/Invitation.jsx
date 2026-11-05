import { useEffect, useState } from 'react';
import Card, { Ico } from './Card.jsx';
import { api } from './api.js';

export default function Invitation({ id }) {
  const [m, setM] = useState(null);
  const [err, setErr] = useState('');
  const [open, setOpen] = useState(false);
  const nueva = new URLSearchParams(location.search).get('nueva');
  useEffect(() => { api('/invitacion/' + id).then((d) => setM(d.invitation)).catch((e) => setErr(e.message)); }, [id]);

  if (err) return <Card kicker="OdlC"><h1 className="r-title">Invitación no disponible</h1><p className="r-guest">El enlace no es correcto o la invitación ya no existe. Si necesita ayuda, escriba a <a href="mailto:isabel@odlc.es">isabel@odlc.es</a>.</p></Card>;
  if (!m) return <Card><p className="r-guest">Cargando…</p></Card>;
  const c = m.calendar;
  return (
    <Card>
      {nueva && m.estado === 'proxima' && <p className="r-ok">Cita confirmada. Le hemos enviado la convocatoria por correo.</p>}
      {m.estado === 'cancelada' && <p className="r-status cancelada">Reunión cancelada</p>}
      {m.estado === 'pasada' && <p className="r-status pasada">Reunión ya celebrada</p>}
      <p className="r-eyebrow">Reunión con OdlC</p>
      <h1 className="r-title">{m.subject}</h1>
      <p className="r-guest">{m.team.join(' · ')}{m.guestName && <><br />para {m.guestName}</>}</p>
      <dl className="r-grid">
        <div><dt>Fecha</dt><dd>{m.fecha}</dd><dd className="r-s">{m.dia}</dd></div>
        <div><dt>Hora</dt><dd>{m.hora} h</dd><dd className="r-s">hasta las {m.horaFin}</dd></div>
        <div><dt>Zona</dt><dd>Madrid</dd><dd className="r-s">{m.zona}</dd></div>
        <div><dt>Duración</dt><dd>{m.duration} min</dd><dd className="r-s">Microsoft Teams</dd></div>
      </dl>
      {m.description && <p className="r-desc" style={{ whiteSpace: 'pre-line' }}>{m.description}</p>}
      {m.materials.length > 0 && (
        <section className="r-mat" aria-label="Materiales"><h2>Materiales</h2><ul>
          {m.materials.map((f, i) => (
            <li key={i}><a href={f.url} {...(f.kind === 'file' ? { download: true } : { target: '_blank', rel: 'noopener noreferrer' })}>
              <span className="r-mt">{f.label}</span><span className="r-mn">{f.name}</span>{f.size && <span className="r-ms">{f.size}</span>}
            </a></li>))}
        </ul></section>)}
      {m.teamsUrl && <a className="t-btn" href={m.teamsUrl} target="_blank" rel="noopener noreferrer">{Ico.teams}Unirse a la reunión</a>}
      {m.estado === 'cancelada' && <a className="t-btn t-light" href={c.ics}>{Ico.cal}Actualizar mi calendario</a>}
      {m.estado === 'proxima' && <button type="button" className="t-btn t-light" onClick={() => setOpen(true)}>{Ico.cal}Añadir al calendario</button>}
      {open && (
        <div className="r-back" onClick={() => setOpen(false)}>
          <div className="r-sheet" role="dialog" aria-modal="true" aria-label="Elegir calendario" onClick={(e) => e.stopPropagation()}>
            <p className="r-sheet-t">¿En qué calendario?</p>
            <div className="r-opts">
              <a href={c.outlookWork} target="_blank" rel="noopener noreferrer"><b>Outlook</b><span>Microsoft 365</span></a>
              <a href={c.google} target="_blank" rel="noopener noreferrer"><b>Google</b><span>Calendar</span></a>
              <a href={c.ics}><b>Apple</b><span>iPhone · Mac</span></a>
              <a href={c.outlookPersonal} target="_blank" rel="noopener noreferrer"><b>Outlook.com</b><span>Hotmail</span></a>
            </div>
            <a className="r-other" href={c.ics} download="reunion-odlc.ics">Otro calendario · descargar .ics</a>
            <button type="button" className="r-close" onClick={() => setOpen(false)}>Cerrar</button>
          </div>
        </div>)}
    </Card>
  );
}
