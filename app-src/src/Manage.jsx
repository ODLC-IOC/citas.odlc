import { useEffect, useMemo, useState } from 'react';
import Card from './Card.jsx';
import { api } from './api.js';

const fmtDay = (d) => {
  const dt = new Date(d + 'T12:00:00');
  return {
    wd: dt.toLocaleDateString('es-ES', { weekday: 'short' }).replace('.', ''),
    n: dt.getDate(),
    mo: dt.toLocaleDateString('es-ES', { month: 'short' }).replace('.', ''),
    long: dt.toLocaleDateString('es-ES', { weekday: 'long', day: 'numeric', month: 'long' }),
  };
};

// El invitado cambia o cancela su cita con el código de la invitación y su correo
export default function Manage() {
  const q = new URLSearchParams(location.search);
  const [codigo, setCodigo] = useState((q.get('c') || '').toUpperCase());
  const [email, setEmail] = useState(q.get('e') || '');
  const [data, setData] = useState(null);
  const [view, setView] = useState('ver'); // ver | cambiar | cancelar | hecho
  const [done, setDone] = useState('');
  const [day, setDay] = useState('');
  const [hour, setHour] = useState('');
  const [msg, setMsg] = useState('');
  const [busy, setBusy] = useState(false);
  const days = useMemo(() => Object.keys((data && data.huecos) || {}), [data]);
  useEffect(() => { if (days.length && !day) setDay(days[0]); }, [days]);

  const creds = () => ({ codigo: codigo.trim().toUpperCase(), email: email.trim() });

  async function buscar(e) {
    e && e.preventDefault(); setMsg('');
    if (!codigo.trim() || !email.trim()) { setMsg('Escriba el código de la cita y su correo.'); return; }
    setBusy(true);
    try { const d = await api('/cita/buscar', { method: 'POST', body: creds() }); setData(d); setView('ver'); setDay(''); setHour(''); }
    catch (err) { setMsg(err.message); }
    setBusy(false);
  }
  async function cancelar() {
    setBusy(true); setMsg('');
    try { const d = await api('/cita/cancelar', { method: 'POST', body: creds() }); setData({ ...data, cita: d.cita, editable: false }); setDone('cancelada'); setView('hecho'); }
    catch (err) { setMsg(err.message); }
    setBusy(false);
  }
  async function cambiar() {
    setBusy(true); setMsg('');
    try { const d = await api('/cita/cambiar', { method: 'POST', body: { ...creds(), fecha: day, hora: hour } }); setData({ ...data, cita: d.cita }); setDone('cambiada'); setView('hecho'); }
    catch (err) { setMsg(err.message); if (err.status === 422) buscar(); }
    setBusy(false);
  }

  const c = data && data.cita;
  return (
    <Card kicker="Gestionar mi cita">
      {!data && (
        <form className="b-form" onSubmit={buscar} noValidate>
          <h1 className="b-h">Cambiar o cancelar su cita</h1>
          <p className="b-p">Escriba el código que aparece en su invitación y el correo con el que le invitamos.</p>
          <div><label htmlFor="g-c">Código de la cita</label><input id="g-c" value={codigo} onChange={(e) => setCodigo(e.target.value.toUpperCase())} maxLength={12} autoComplete="off" style={{ letterSpacing: '3px', textTransform: 'uppercase' }} /></div>
          <div><label htmlFor="g-e">Su correo electrónico</label><input id="g-e" type="email" value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="email" /></div>
          <button className="t-btn" type="submit" disabled={busy}>{busy ? 'Buscando…' : 'Ver mi cita'}</button>
        </form>)}

      {c && (
        <>
          <div className="b-sum"><b>{c.subject}</b>{c.dia} {c.fecha} · {c.hora} h – {c.horaFin} h (Madrid) · {c.duration} min · Microsoft Teams
            {c.estado === 'cancelada' && <><br /><strong style={{ color: '#9B3127' }}>Cita cancelada</strong></>}</div>

          {view === 'hecho' && (
            <><h1 className="b-h">{done === 'cancelada' ? 'Cita cancelada' : 'Cita cambiada'}</h1>
              <p className="b-p">{done === 'cancelada'
                ? 'Hemos avisado a Isabel y liberado el hueco. Si quiere otra fecha, puede reservar de nuevo.'
                : 'Hemos actualizado la cita y avisado a Isabel. Le llegará la actualización al calendario.'}</p>
              {done === 'cancelada'
                ? <a className="t-btn" href="/reservar">Reservar otra fecha</a>
                : <a className="t-btn" href={'/r/' + c.id}>Ver la invitación</a>}</>)}

          {view === 'ver' && (data.editable
            ? <div style={{ display: 'grid', gap: 10 }}>
                <a className="t-btn" href={'/r/' + c.id}>Ver la invitación</a>
                <button type="button" className="t-btn t-light" onClick={() => setView('cambiar')}>Cambiar fecha u hora</button>
                <button type="button" className="b-back" onClick={() => setView('cancelar')}>Cancelar la cita</button>
              </div>
            : c.estado !== 'cancelada' && <p className="b-p">Esta cita ya no se puede cambiar desde aquí. Si lo necesita, escriba a <a href="mailto:isabel@odlc.es">isabel@odlc.es</a>.</p>)}

          {view === 'cancelar' && (
            <><h1 className="b-h">¿Cancelar la cita?</h1><p className="b-p">Se avisará a Isabel y se liberará el hueco.</p>
              <button type="button" className="t-btn" disabled={busy} onClick={cancelar} style={{ background: '#9B3127', borderColor: '#9B3127' }}>{busy ? 'Cancelando…' : 'Sí, cancelar la cita'}</button>
              <button type="button" className="b-back" onClick={() => setView('ver')}>← No, volver</button></>)}

          {view === 'cambiar' && (
            <><h1 className="b-h">Elija nuevo día y hora</h1><p className="b-p">Hora de Madrid · {c.duration} min</p>
              {!days.length && <p className="b-p">No quedan huecos libres en las próximas semanas. Escríbanos a <a href="mailto:isabel@odlc.es">isabel@odlc.es</a>.</p>}
              {days.length > 0 && <>
                <div className="b-days" role="group" aria-label="Día">{days.map((d) => { const x = fmtDay(d); return (
                  <button key={d} type="button" aria-pressed={d === day} onClick={() => { setDay(d); setHour(''); }}><small>{x.wd}</small><b>{x.n}</b><small>{x.mo}</small></button>); })}</div>
                <div className="b-hours" role="group" aria-label="Hora">{(data.huecos[day] || []).map((h) => (
                  <button key={h} type="button" aria-pressed={h === hour} onClick={() => setHour(h)}>{h}</button>))}</div>
                {hour && <p className="b-p">Nueva fecha: <b>{fmtDay(day).long} · {hour} h</b></p>}
                <button type="button" className="t-btn" disabled={!hour || busy} onClick={cambiar} style={{ opacity: hour ? 1 : 0.5 }}>{busy ? 'Cambiando…' : 'Confirmar el cambio'}</button></>}
              <button type="button" className="b-back" onClick={() => setView('ver')}>← Volver</button></>)}
        </>)}
      {msg && <p className="b-err" role="alert">{msg}</p>}
    </Card>
  );
}
