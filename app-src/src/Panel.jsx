import { useEffect, useRef, useState } from 'react';
import { api, ApiError, waHref, copy } from './api.js';
import Card, { LOGO_CLARO } from './Card.jsx';
import { parseOutlookIcs, teamsFrom } from './ics.js';

const ME = 'Isabel Olivares de la Cerda';
const NOGUEST = { name: '', email: '', phone: '' };
const EMPTY = { mode: 'manual', guests: [NOGUEST], team: ME, subject: '', date: '', time: '', duration: '60', teamsUrl: '', description: '', notes: '', sendInvite: true, toOutlook: true, toBookings: false, bkService: '' };
const LABEL = { proxima: 'Próxima', pasada: 'Pasada', cancelada: 'Cancelada' };
const ORIGEN = { panel: '', reserva: ' · Reserva web', bookings: ' · Bookings', outlook: ' · Outlook' };
const fmtSize = (b) => (b < 1048576 ? Math.max(1, Math.round(b / 1024)) + ' KB' : (b / 1048576).toFixed(1).replace('.', ',') + ' MB');

function Top({ label, onLogout }) {
  return <header className="p-top"><div className="wrap"><img src={LOGO_CLARO} alt="OdlC" width="96" height="31" /><span>{label}</span>{onLogout && <button type="button" onClick={onLogout}>Salir</button>}</div></header>;
}

function Login() {
  const q = new URLSearchParams(location.search);
  const err = q.get('acceso') === 'error' ? q.get('detalle') || 'No se ha podido entrar.' : '';
  return (
    <Card kicker="Gestor de citas">
      <h1 className="b-h">Acceso privado</h1>
      <p className="b-p">Solo para personas autorizadas por OdlC, con su cuenta de Microsoft.</p>
      <a className="t-btn ms-btn" href="/api/entrar"><svg viewBox="0 0 21 21" width="18" height="18" aria-hidden="true"><rect x="1" y="1" width="9" height="9" fill="#f25022"/><rect x="11" y="1" width="9" height="9" fill="#7fba00"/><rect x="1" y="11" width="9" height="9" fill="#00a4ef"/><rect x="11" y="11" width="9" height="9" fill="#ffb900"/></svg>Iniciar sesión con Microsoft</a>
      {err && <p className="b-err" role="alert" style={{ marginTop: 14 }}>{err}</p>}
    </Card>);
}

export default function Panel() {
  const [user, setUser] = useState(undefined);
  useEffect(() => { api('/session').then((d) => setUser(d.user)).catch(() => setUser(null)); }, []);
  if (user === undefined) return <div className="p-page"><Top label="Reuniones" /></div>;
  if (!user) return <Login />;
  return <Dashboard user={user} onLogout={() => api('/logout', { method: 'POST', body: {} }).finally(() => { history.replaceState(null, '', '/'); setUser(null); })} />;
}

function Dashboard({ user, onLogout }) {
  const [ms, setMs] = useState(null);
  const [meetings, setMeetings] = useState([]);
  const [events, setEvents] = useState(null);
  const [filter, setFilter] = useState('proxima');
  const [form, setForm] = useState(EMPTY);
  const [editing, setEditing] = useState(null);
  const [fields, setFields] = useState({});
  const [formMsg, setFormMsg] = useState(['', '']);
  const [busy, setBusy] = useState(false);
  const [files, setFiles] = useState([]);
  const [link, setLink] = useState({ url: '', name: '' });
  const [result, setResult] = useState(null);
  const [waText, setWaText] = useState('');
  const [resMsg, setResMsg] = useState(['', '']);
  const [rowMsg, setRowMsg] = useState({});
  const [armed, setArmed] = useState('');
  const [bk, setBk] = useState(null);
  const formRef = useRef(null); const resRef = useRef(null); const fileRef = useRef(null);
  const qs = new URLSearchParams(location.search);
  const [msNote] = useState(qs.get('acceso') === 'error' ? 'No se ha podido conectar: ' + (qs.get('detalle') || '') : '');

  const loadList = () => api('/reuniones').then((d) => setMeetings(d.meetings)).catch(() => {});
  const loadEvents = () => api('/outlook/eventos').then((d) => { setEvents(d.events); if (d.importadas) loadList(); }).catch((e) => setEvents({ error: e.message }));
  useEffect(() => {
    if (qs.get('ms') || qs.get('acceso')) history.replaceState(null, '', '/');
    api('/ms/estado').then((s) => { setMs(s); if (s.connected && s.teams) setForm((f) => (f.mode === 'manual' && !f.teamsUrl ? { ...f, mode: 'auto' } : f)); if (s.connected) { loadEvents(); api('/bookings').then(setBk).catch(() => {}); } }).catch(() => setMs({ error: true }));
    loadList();
  }, []);
  const autoOk = ms && ms.connected;
  const bkOn = !!(autoOk && bk && bk.elegido && bk.servicios && bk.servicios.length);
  const setGuest = (i, k) => (e) => setForm({ ...form, guests: form.guests.map((g, j) => (j === i ? { ...g, [k]: e.target.value } : g)) });
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value });

  function reset() { setForm(ms && ms.teams ? { ...EMPTY, mode: 'auto' } : EMPTY); setEditing(null); setFields({}); setFiles([]); setLink({ url: '', name: '' }); if (fileRef.current) fileRef.current.value = ''; }
  function edit(m) {
    setEditing(m); setFields({}); setFiles([]); setFormMsg(['', '']);
    setForm({ mode: m.mode, guests: m.guests && m.guests.length ? m.guests.map((g) => ({ ...NOGUEST, ...g })) : [NOGUEST], team: (m.team || [ME]).join('\n'), subject: m.subject,
      date: m.local.date, time: m.local.time, duration: String(m.duration), teamsUrl: m.teamsUrl || '', description: m.description || '', notes: m.notes || '', sendInvite: !!m.sendInvite, toOutlook: !m.graphEventId });
    formRef.current.scrollIntoView({ behavior: 'smooth' });
  }
  function show(m, updated, text) {
    setResult(m); setWaText(text || (updated ? m.whatsappText.replace(/(Te|Os) envío la invitación/, '$1 envío la invitación actualizada') : m.whatsappText)); setResMsg(['', '']);
    setTimeout(() => resRef.current && resRef.current.scrollIntoView({ behavior: 'smooth' }), 50);
  }

  async function uploadMaterials(meeting) {
    let cur = meeting; const errors = [];
    for (let i = 0; i < files.length; i++) {
      const f = files[i]; setFormMsg(['Subiendo material ' + (i + 1) + ' de ' + files.length + '…', '']);
      try { cur = (await api('/reuniones/' + meeting.id + '/materiales', { method: 'POST', raw: f, headers: { 'Content-Type': f.type || 'application/octet-stream', 'X-File-Name': encodeURIComponent(f.name) } })).meeting; }
      catch (e) { errors.push(f.name + ': ' + e.message); }
    }
    if (link.url.trim()) {
      try { cur = (await api('/reuniones/' + meeting.id + '/materiales', { method: 'POST', body: { url: link.url.trim(), name: link.name.trim() } })).meeting; }
      catch (e) { errors.push(e.message); }
    }
    return [cur, errors];
  }

  async function submit(e) {
    e.preventDefault(); setFields({});
    const p = { ...form, duration: Number(form.duration), toBookings: bkOn && form.toBookings, bkService: form.bkService || (bkOn ? bk.servicios[0].id : '') }; const f = {};
    if (!p.subject.trim()) f.subject = 'Escribe el asunto.';
    if (!p.date) f.date = 'Elige una fecha.';
    if (!p.time) f.time = 'Elige una hora.';
    p.guests.forEach((g, i) => { if (g.email && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(g.email)) f['guests.' + i + '.email'] = 'El correo no es válido.'; });
    if ((editing ? editing.mode : p.mode) === 'manual' && !teamsFrom(p.teamsUrl)) f.teamsUrl = 'Pega un enlace de Microsoft Teams válido.';
    if (p.sendInvite && (p.mode === 'auto' || p.toOutlook) && !p.guests.some((g) => g.email)) f['guests.0.email'] = 'Para enviar la convocatoria, escribe al menos un correo.';
    if (Object.keys(f).length) { setFields(f); setFormMsg(['Revisa los campos marcados.', 'err']); return; }
    setBusy(true); setFormMsg([p.mode === 'auto' && !editing ? 'Creando el evento en Outlook y la reunión de Teams…' : 'Guardando…', '']);
    try {
      const d = editing ? await api('/reuniones/' + editing.id, { method: 'PUT', body: p }) : await api('/reuniones', { method: 'POST', body: p });
      const [m, errors] = await uploadMaterials(d.meeting);
      setFormMsg([editing ? 'Cambios guardados.' : 'Invitación creada.', 'ok']);
      show(m, !!editing); if (errors.length) setResMsg(['No se han podido añadir: ' + errors.join(' · '), 'err']);
      reset(); loadList();
    } catch (err) { setFields(err.fields || {}); setFormMsg([err.message, 'err']); }
    setBusy(false);
  }

  async function removeMaterial(f) {
    try { const d = await api('/reuniones/' + editing.id + '/materiales/' + f.id, { method: 'DELETE' }); setEditing(d.meeting); loadList(); }
    catch (e) { setFormMsg([e.message, 'err']); }
  }

  async function importIcs(e) {
    const file = e.target.files[0]; e.target.value = ''; if (!file) return;
    try {
      const r = parseOutlookIcs(await file.text());
      setForm((cur) => ({ ...cur, mode: r.teamsUrl ? 'manual' : cur.mode, subject: r.subject || cur.subject, date: r.date, time: r.time, duration: r.duration ? String(r.duration) : cur.duration,
        teamsUrl: r.teamsUrl || cur.teamsUrl, description: cur.description || r.description || '' }));
      setFormMsg(['Reunión importada de Outlook' + (r.teamsUrl ? ' con su enlace de Teams' : ' (sin enlace de Teams: pégalo a mano)') + '. Revisa y pulsa Crear invitación.', r.teamsUrl ? 'ok' : 'err']);
    } catch (err) { setFormMsg([err.message, 'err']); }
  }

  async function importEvent(ev) {
    try { const d = await api('/outlook/importar', { method: 'POST', body: { eventId: ev.eventId } }); show(d.meeting, false); loadList(); loadEvents(); }
    catch (e) { setRowMsg({ [ev.eventId]: e.message }); }
  }
  async function hide(m) {
    if (armed !== 'h' + m.id) { setArmed('h' + m.id); setTimeout(() => setArmed(''), 5000); return; }
    try { await api('/reuniones/' + m.id + '/ocultar', { method: 'POST', body: {} }); loadList(); }
    catch (e) { setRowMsg({ [m.id]: e.message }); }
  }
  async function cancel(m) {
    if (armed !== m.id) { setArmed(m.id); setTimeout(() => setArmed(''), 5000); return; }
    try { const d = await api('/reuniones/' + m.id + '/cancelar', { method: 'POST', body: {} }); loadList(); show(d.meeting, false, d.cancelText); }
    catch (e) { setRowMsg({ [m.id]: e.message }); }
  }
  // WhatsApp solo enseña la vista previa pequeña: para que se vea en grande se envía la imagen como foto y el mensaje como pie
  async function shareImage(m, text) {
    try {
      const blob = await (await fetch(m.url + '/imagen.jpg', { cache: 'no-store' })).blob();
      const file = new File([blob], 'invitacion-odlc.jpg', { type: 'image/jpeg' });
      if (navigator.canShare && navigator.canShare({ files: [file] })) {
        await navigator.share({ files: [file], text });
        setResMsg(['Elige WhatsApp y el chat: se envía la imagen en grande con el mensaje y el enlace.', 'ok']); return;
      }
      // Ordenador sin «Compartir»: se copia la imagen; en WhatsApp pegas (Ctrl+V) y luego el mensaje como pie de foto
      const bmp = await createImageBitmap(blob); const c = document.createElement('canvas'); c.width = bmp.width; c.height = bmp.height; c.getContext('2d').drawImage(bmp, 0, 0);
      const png = await new Promise((r) => c.toBlob(r, 'image/png'));
      await navigator.clipboard.write([new ClipboardItem({ 'image/png': png })]);
      setResMsg(['Imagen copiada. En WhatsApp pega con Ctrl+V; en el pie de foto pega el mensaje (botón «Copiar mensaje»).', 'ok']);
    } catch (e) { if (e && e.name !== 'AbortError') setResMsg(['No se ha podido compartir la imagen. Usa «Descargar imagen» y adjúntala en WhatsApp.', 'err']); }
  }
  async function doCopy(text, key) { const ok = await copy(text); if (key) setRowMsg({ [key]: ok ? 'Copiado.' : 'No se ha podido copiar.' }); else setResMsg([ok ? 'Copiado.' : 'No se ha podido copiar.', ok ? 'ok' : 'err']); }

  const pending = Array.isArray(events) ? events.filter((e) => !e.meetingId) : [];
  const items = meetings.filter((m) => filter === 'todas' || m.estado === filter).sort((a, b) => (filter === 'proxima' ? a.startUtc - b.startUtc : b.startUtc - a.startUtc));
  // Una sola lista: reuniones con invitación y citas de Outlook/Bookings aún sin invitación, agrupadas por día
  const rows = [...items.map((m) => ({ kind: 'm', at: m.startUtc, day: m.fecha, m })),
    ...(filter === 'proxima' || filter === 'todas' ? pending.map((ev) => ({ kind: 'ev', at: ev.startUtc, day: ev.fecha, ev })) : [])]
    .sort((a, b) => (filter === 'proxima' ? a.at - b.at : b.at - a.at));
  const days = []; rows.forEach((r) => { const last = days[days.length - 1]; if (last && last[0] === r.day) last[1].push(r); else days.push([r.day, [r]]); });
  const modeNow = editing ? editing.mode : form.mode;

  let msText = 'Comprobando…';
  if (ms && ms.error) msText = 'No se ha podido comprobar la conexión.';
  else if (ms && !ms.configured) msText = 'Modo automático no configurado todavía (faltan las claves de Microsoft Entra ID). El modo manual funciona.';
  else if (ms && !ms.connected) msText = 'Sin conectar. Conecta ' + ms.adminEmail + ' para crear reuniones de Teams, ver tus citas de Outlook y Bookings y abrir la página de reservas.';
  
  else if (ms) msText = 'Conectado como ' + ms.account + '. Outlook, Teams y reservas en citas.odlc.es activos.';

  return (
    <div className="p-page"><Top label="Reuniones" onLogout={onLogout} />
      <main className="p-main">
        <section className="p-box"><div className="p-ms">
          <div><h2>Microsoft 365</h2><p className="p-muted" style={{ margin: 0 }}>{msNote || msText}</p></div>
          <div className="p-actions" style={{ margin: 0 }}>
            {user.admin && ms && ms.configured && !ms.connected && <a className="p-btn ghost" href="/api/ms/conectar">Conectar con Microsoft 365</a>}
            {user.admin && ms && ms.connected && <button className="p-btn ghost" type="button" onClick={() => api('/ms/desconectar', { method: 'POST', body: {} }).then(() => location.reload())}>Desconectar</button>}
            <a className="p-btn ghost" href="/reservar" target="_blank" rel="noopener">Ver página de reservas</a>
          </div></div></section>

        {autoOk && user.admin && <BookingsBox />}


        <section className="p-box"><h2>Mis reuniones</h2>
          <div className="p-tabs" role="group" aria-label="Filtrar">{[['proxima', 'Próximas'], ['pasada', 'Pasadas'], ['cancelada', 'Canceladas'], ['todas', 'Todas']].map(([k, l]) =>
            <button key={k} type="button" aria-pressed={filter === k} onClick={() => setFilter(k)}>{l}</button>)}</div>
          {events && events.error && <p className="p-msg err">Outlook: {events.error}</p>}
          {!rows.length && <p className="p-muted">No hay reuniones en esta lista.</p>}
          {days.map(([day, list]) => (
            <div className="p-day" key={day}><h3 className="p-day-t">{day}</h3>
              {list.map((r) => r.kind === 'ev' ? (
                <article className="p-item p-item-ev" key={r.ev.eventId}>
                  <h3>{r.ev.guestName || r.ev.guestEmail || 'Sin invitado'}<span className="p-badge proxima">{r.ev.bookings ? 'Bookings' : 'Outlook'} · sin invitación</span></h3>
                  <p>{r.ev.subject}</p><p>{r.ev.hora}–{r.ev.horaFin} h (Madrid)</p>
                  <div className="p-actions"><button className="p-btn" type="button" onClick={() => importEvent(r.ev)}>Crear invitación</button></div>
                  {rowMsg[r.ev.eventId] && <p className="p-msg err">{rowMsg[r.ev.eventId]}</p>}
                </article>) : (() => { const m = r.m; return (
                <article className="p-item" key={m.id}>
                  <h3>{m.guestName || 'Sin nombre de invitado'}<span className={'p-badge ' + m.estado}>{LABEL[m.estado]}</span></h3>
                  <p>{m.subject}</p>
                  <p>{m.hora}–{m.horaFin} h (Madrid) · {m.duration} min{ORIGEN[m.source] || ''}</p>
                  {m.notes && <p>Notas: {m.notes}</p>}
                  {m.materials && m.materials.length > 0 && <p>Materiales: {m.materials.map((f) => f.name).join(', ')}</p>}
                  <div className="p-actions">
                    <a className="p-btn ghost" href={m.url} target="_blank" rel="noopener">Abrir invitación</a>
                    <button className="p-btn ghost" type="button" onClick={() => doCopy(m.url, m.id)}>Copiar enlace</button>
                    {m.estado !== 'cancelada' && <>
                      <button className="p-btn wa" type="button" onClick={() => show(m, false)}>WhatsApp</button>
                      <button className="p-btn ghost" type="button" onClick={() => edit(m)}>Editar</button>
                      <button className="p-btn danger" type="button" onClick={() => cancel(m)}>{armed === m.id ? '¿Seguro? Sí, cancelar' : 'Cancelar'}</button></>}
                    <button className="p-btn ghost" type="button" title="Quitar de la lista sin cancelar ni avisar a nadie; el enlace sigue funcionando" onClick={() => hide(m)}>{armed === 'h' + m.id ? '¿Ocultar? Sí' : 'Ocultar'}</button>
                  </div>
                  {rowMsg[m.id] && <p className="p-msg">{rowMsg[m.id]}</p>}
                </article>); })())}
            </div>))}
        </section>

        <section className="p-box" ref={formRef}>
          <h1>{editing ? 'Editar reunión' : 'Nueva reunión'}</h1>
          <p className="p-muted">Hora de Madrid (Europe/Madrid). Los campos con * son obligatorios.</p>
          <form onSubmit={submit} noValidate>
            <div className="p-mode" role="radiogroup" aria-label="Enlace de Teams">
              <label><input type="radio" name="mode" value="manual" checked={modeNow === 'manual'} disabled={!!editing} onChange={set('mode')} /><span><b>Pegar enlace de Teams</b>Creo la reunión en Outlook o Teams y pego aquí su enlace.</span></label>
              {/* Solo con Teams de empresa: con Teams gratuito se pega el enlace */}
              {(ms && ms.teams || (editing && editing.mode !== 'manual')) &&               <label><input type="radio" name="mode" value="auto" checked={modeNow === 'auto' || modeNow === 'outlook'} disabled={!!editing || !autoOk} onChange={set('mode')} /><span><b>Crear en Outlook y Teams</b>El sistema crea el evento en mi calendario con el enlace real de Teams.</span></label>}
            </div>
            {!editing && <div className="p-import">
              <label className="p-btn ghost" htmlFor="ics-file">Importar reunión de Outlook (.ics)</label>
              <input id="ics-file" type="file" accept=".ics,text/calendar" hidden onChange={importIcs} />
              <small>En Outlook: abre la reunión → Archivo → Guardar como → iCalendar (.ics), o arrástrala al escritorio.</small></div>}
            <div className="p-grid" style={{ marginTop: 18 }}>
              <div className="p-field full"><label>Invitados</label>
                <div className="p-guests">
                  {form.guests.map((g, i) => (
                    <div className="p-guest" key={i}>
                      <input aria-label={'Nombre del invitado ' + (i + 1)} placeholder="Nombre" value={g.name} maxLength={80} onChange={setGuest(i, 'name')} />
                      <input aria-label={'Correo del invitado ' + (i + 1)} type="email" placeholder="Correo" value={g.email} onChange={setGuest(i, 'email')} />
                      <input aria-label={'Móvil del invitado ' + (i + 1)} type="tel" placeholder="Móvil (+34 600 000 000)" value={g.phone} onChange={setGuest(i, 'phone')} />
                      {form.guests.length > 1 && <button type="button" className="p-guest-del" aria-label={'Quitar invitado ' + (i + 1)} onClick={() => setForm({ ...form, guests: form.guests.filter((_, j) => j !== i) })}>Quitar</button>}
                      {(fields['guests.' + i + '.email'] || fields['guests.' + i + '.phone']) && <small className="p-err" style={{ color: '#9B3127' }}>{fields['guests.' + i + '.email'] || fields['guests.' + i + '.phone']}</small>}
                    </div>))}
                </div>
                {form.guests.length < 10 && <button type="button" className="p-btn ghost p-guest-add" onClick={() => setForm({ ...form, guests: [...form.guests, NOGUEST] })}>+ Añadir invitado</button>}
                <small>Los nombres aparecen en el saludo y en la invitación. Correo y móvil son opcionales y no se muestran en la invitación; con correo, a cada uno le llega la convocatoria de Outlook y Teams.</small></div>
              <Field id="team" label="Asistentes de OdlC" help="Uno por línea. Aparecen en la invitación y en el calendario."><textarea id="team" rows={2} value={form.team} onChange={set('team')} maxLength={400} /></Field>
              <Field id="subject" label="Asunto *" err={fields.subject}><input id="subject" value={form.subject} onChange={set('subject')} maxLength={160} /></Field>
              <Field id="date" label="Fecha *" err={fields.date}><input id="date" type="date" value={form.date} onChange={set('date')} /></Field>
              <Field id="time" label="Hora *" err={fields.time}><input id="time" type="time" step="300" value={form.time} onChange={set('time')} /></Field>
              <Field id="duration" label="Duración *" err={fields.duration}><select id="duration" value={form.duration} onChange={set('duration')}>{[15, 30, 45, 60, 90].map((d) => <option key={d} value={d}>{d} minutos</option>)}</select></Field>
              {modeNow === 'manual' && <Field full id="teamsUrl" label="Enlace de Microsoft Teams *" help="Puedes pegar el texto entero de la invitación de Teams: se extrae el enlace solo." err={fields.teamsUrl}>
                <input id="teamsUrl" type="url" value={form.teamsUrl} placeholder="https://teams.microsoft.com/l/meetup-join/…" onChange={(e) => setForm({ ...form, teamsUrl: teamsFrom(e.target.value) || e.target.value })} /></Field>}
              {bkOn && !editing && <div className="p-field full"><label><input type="checkbox" checked={form.toBookings} onChange={set('toBookings')} style={{ width: 'auto', marginRight: 8 }} />Registrar en mi Microsoft Bookings (me bloquea la agenda y Bookings envía la confirmación por correo a cada invitado)</label>
                {form.toBookings && bk.servicios.length > 1 && <select aria-label="Servicio de Bookings" value={form.bkService} onChange={set('bkService')} style={{ marginTop: 8 }}>{bk.servicios.map((x) => <option key={x.id} value={x.id}>{x.nombre}</option>)}</select>}</div>}
              {modeNow === 'manual' && autoOk && !(bkOn && form.toBookings && !editing) && !(editing && editing.graphEventId) && <div className="p-field full"><label><input type="checkbox" checked={form.toOutlook} onChange={set('toOutlook')} style={{ width: 'auto', marginRight: 8 }} />Crear también el evento en mi Outlook con este enlace (me bloquea la agenda y Bookings)</label></div>}
              {!(bkOn && form.toBookings && !editing) && (modeNow !== 'manual' || (autoOk && form.toOutlook) || (editing && editing.graphEventId)) && <div className="p-field full"><label><input type="checkbox" checked={form.sendInvite} onChange={set('sendInvite')} style={{ width: 'auto', marginRight: 8 }} />Enviar la convocatoria por correo (Outlook) a los invitados</label></div>}
              <Field full id="description" label="Descripción para el invitado" help="Aparece en la invitación y en su calendario. No escribas información confidencial."><textarea id="description" value={form.description} onChange={set('description')} maxLength={2000} /></Field>
              <div className="p-field full"><label htmlFor="files">Materiales para la reunión</label>
                <input id="files" ref={fileRef} type="file" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.png,.jpg,.jpeg,.txt,.csv" onChange={(e) => setFiles(Array.from(e.target.files))} />
                <small>PDF, Word, Excel, PowerPoint o imágenes, hasta 10 MB cada uno. Si el documento va a cambiar, mejor un enlace de OneDrive: siempre abrirán la última versión.</small>
                <div className="p-linkrow"><input type="url" placeholder="Enlace de OneDrive o SharePoint" value={link.url} onChange={(e) => setLink({ ...link, url: e.target.value })} /><input placeholder="Nombre (opcional)" value={link.name} onChange={(e) => setLink({ ...link, name: e.target.value })} /></div>
                <ul className="p-mats">
                  {editing && (editing.materials || []).map((f) => <li key={f.id}><span>{(f.kind === 'link' ? 'Enlace · ' : '') + f.name + (f.size ? ' · ' + fmtSize(f.size) : '')}</span><button type="button" onClick={() => removeMaterial(f)}>Quitar</button></li>)}
                  {files.map((f, i) => <li key={i}><span>{f.name} · {fmtSize(f.size)}{f.size > 10485760 ? ' · supera 10 MB' : ' · se subirá al guardar'}</span></li>)}
                </ul></div>
              <Field full id="notes" label="Notas privadas" help="Solo las ves tú en este panel."><textarea id="notes" value={form.notes} onChange={set('notes')} maxLength={2000} /></Field>
            </div>
            <div className="p-actions"><button className="p-btn" disabled={busy}>{editing ? 'Guardar cambios' : 'Crear invitación'}</button>{editing && <button type="button" className="p-btn ghost" onClick={reset}>Cancelar edición</button>}</div>
            <p className={'p-msg ' + formMsg[1]} role="status">{formMsg[0]}</p>
          </form>
        </section>

        {result && (
          <section className="p-box p-result" ref={resRef}><h2>Invitación lista</h2>
            <p className="p-muted">{result.fecha}, {result.hora} h (Madrid) · {result.url}{result.outlookUpdated ? ' · enlace añadido a tu evento de Outlook' : ''}</p>
            <div className="p-field"><label htmlFor="wa">Mensaje de WhatsApp (puedes retocarlo)</label><textarea id="wa" rows={13} value={waText} onChange={(e) => setWaText(e.target.value)} /></div>
            <div className="p-actions">
              <a className="p-btn wa" href={waHref(result, waText)} target="_blank" rel="noopener" onClick={() => setResMsg(['WhatsApp se ha abierto con el mensaje. Se enviará cuando pulses Enviar en WhatsApp.', 'ok'])}>Enviar por WhatsApp</a>
              <button className="p-btn ghost" type="button" onClick={() => shareImage(result, waText)}>Imagen + mensaje (grande)</button>
              <a className="p-btn ghost" href={result.url + '/imagen.jpg'} download={'invitacion-odlc-' + result.local.date + '.jpg'}>Descargar imagen</a>
              <a className="p-btn ghost" href={result.url} target="_blank" rel="noopener">Abrir invitación</a>
              <button className="p-btn ghost" type="button" onClick={() => doCopy(result.url)}>Copiar enlace</button>
              <button className="p-btn ghost" type="button" onClick={() => doCopy(waText)}>Copiar mensaje</button>
            </div>
            <p className={'p-msg ' + resMsg[1]} role="status">{resMsg[0]}</p>
          </section>)}

        {user.admin && <Users />}
      </main></div>);
}

function BookingsBox() {
  const [d, setD] = useState(null); const [msg, setMsg] = useState('');
  const load = () => api('/bookings').then(setD).catch((e) => setMsg(e.message));
  useEffect(() => { load(); }, []);
  async function choose(e) { await api('/bookings', { method: 'POST', body: { business: e.target.value } }); load(); }
  return (
    <section className="p-box"><h2>Página de reservas · Microsoft Bookings</h2>
      <p className="p-muted">Elige tu página de reservas <b>compartida</b> de Bookings. La página de citas.odlc.es mostrará sus servicios y cada reserva se creará en Bookings, que envía la confirmación al cliente con Teams. Sin elegir ninguna, las reservas van directas a tu calendario de Outlook.</p>
      {!d && !msg && <p className="p-muted">Cargando…</p>}
      {d && d.error && <p className="p-msg err">{d.error}</p>}
      {d && <div className="p-field"><label htmlFor="bk">Página de reservas compartida</label>
        <select id="bk" value={d.elegido} onChange={choose}><option value="">— Ninguna: reservas directas en Outlook —</option>{d.negocios.map((b) => <option key={b.id} value={b.id}>{b.nombre}</option>)}</select>
        {d.negocios.length === 0 && <small>No hay páginas compartidas. Créala en Bookings: Nueva página de reservas → Compartida.</small>}</div>}
      {d && d.elegido && <ul className="p-mats">{(d.servicios || []).map((sv) => <li key={sv.id}><span>{sv.nombre} · {sv.duracion} min</span></li>)}</ul>}
      {msg && <p className="p-msg err">{msg}</p>}
    </section>);
}

function Users() {
  const [list, setList] = useState([]); const [email, setEmail] = useState(''); const [nombre, setNombre] = useState(''); const [msg, setMsg] = useState(['', '']);
  useEffect(() => { api('/usuarios').then((d) => setList(d.usuarios)).catch(() => {}); }, []);
  async function add(e) {
    e.preventDefault(); setMsg(['', '']);
    try { const d = await api('/usuarios', { method: 'POST', body: { email, nombre } }); setList(d.usuarios); setEmail(''); setNombre(''); setMsg(['Autorizado.', 'ok']); }
    catch (err) { setMsg([err.message, 'err']); }
  }
  async function remove(u) {
    try { const d = await api('/usuarios/' + encodeURIComponent(u.email), { method: 'DELETE' }); setList(d.usuarios); } catch (err) { setMsg([err.message, 'err']); }
  }
  return (
    <section className="p-box"><h2>Personas autorizadas</h2>
      <p className="p-muted">Solo estas cuentas de Microsoft pueden entrar en el panel. Deben ser cuentas de OdlC (@odlc.es) o personas invitadas a tu Microsoft 365.</p>
      {list.map((u) => (
        <article className="p-item" key={u.email}><h3>{u.nombre || u.email}{u.admin && <span className="p-badge proxima">Administradora</span>}</h3><p>{u.email}</p>
          {!u.admin && <div className="p-actions"><button className="p-btn danger" type="button" onClick={() => remove(u)}>Quitar acceso</button></div>}</article>))}
      <form className="p-grid" style={{ marginTop: 14 }} onSubmit={add} noValidate>
        <div className="p-field"><label htmlFor="u-email">Correo de su cuenta de Microsoft</label><input id="u-email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} /></div>
        <div className="p-field"><label htmlFor="u-nombre">Nombre</label><input id="u-nombre" value={nombre} onChange={(e) => setNombre(e.target.value)} /></div>
        <div className="p-actions full" style={{ marginTop: 0 }}><button className="p-btn" type="submit">Autorizar</button></div>
      </form>
      <p className={'p-msg ' + msg[1]}>{msg[0]}</p>
    </section>);
}

function Field({ id, label, help, err, full, children }) {
  return <div className={'p-field' + (full ? ' full' : '')}><label htmlFor={id}>{label}</label>{children}{help && <small>{help}</small>}{err && <small className="p-err" style={{ color: '#9B3127' }}>{err}</small>}</div>;
}
