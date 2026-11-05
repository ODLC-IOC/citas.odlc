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

export default function Booking() {
  const [info, setInfo] = useState(null);
  const [tipo, setTipo] = useState(null);
  const [slots, setSlots] = useState(null);
  const [day, setDay] = useState('');
  const [hour, setHour] = useState('');
  const [step, setStep] = useState(1);
  const [f, setF] = useState({ nombre: '', email: '', telefono: '', empresa: '', mensaje: '', acepto: false, web: '' });
  const [errs, setErrs] = useState({});
  const [msg, setMsg] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => { api('/reservas').then(setInfo).catch((e) => setMsg(e.message)); }, []);

  function chooseType(t) {
    setTipo(t); setSlots(null); setDay(''); setHour(''); setStep(2); setMsg('');
    api('/reservas?tipo=' + encodeURIComponent(t.id)).then((d) => {
      setSlots(d.huecos || {}); const first = Object.keys(d.huecos || {})[0]; if (first) setDay(first);
    }).catch((e) => setMsg(e.message));
  }
  const days = useMemo(() => Object.keys(slots || {}), [slots]);

  async function submit(e) {
    e.preventDefault(); setErrs({}); setMsg('');
    const local = {};
    if (!f.nombre.trim()) local.nombre = 'Escriba su nombre.';
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(f.email)) local.email = 'Escriba un correo válido.';
    if (!f.acepto) local.acepto = 'Debe aceptar la política de privacidad.';
    if (Object.keys(local).length) { setErrs(local); return; }
    setBusy(true);
    try {
      const d = await api('/reservar', { method: 'POST', body: { ...f, tipo: tipo.id, fecha: day, hora: hour } });
      window.location.assign('/r/' + d.id + '?nueva=1');
    } catch (err) {
      setErrs(err.fields); setMsg(err.message);
      if (err.fields && err.fields.hora) { setStep(2); chooseType(tipo); }
      setBusy(false);
    }
  }
  const set = (k) => (e) => setF({ ...f, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value });

  return (
    <Card kicker="Reservar una cita">
      <div className="b-steps" aria-hidden="true">{[1, 2, 3].map((i) => <span key={i} className={step >= i ? 'on' : ''} />)}</div>

      {!info && !msg && <p className="b-p">Cargando…</p>}
      {info && !info.disponible && (
        <><h1 className="b-h">Reservas no disponibles</h1>
          <p className="b-p">Escríbanos a <a href="mailto:isabel@odlc.es">isabel@odlc.es</a> y le proponemos una fecha.</p></>)}

      {info && info.disponible && step === 1 && (
        <><h1 className="b-h">¿Qué tipo de cita?</h1><p className="b-p">Reunión online por Microsoft Teams, hora de Madrid.</p>
          <div className="b-types">{info.tipos.map((t) => (
            <button key={t.id} type="button" onClick={() => chooseType(t)}><em>{t.duracion} min</em><b>{t.nombre}</b><small>{t.descripcion}</small></button>))}</div></>)}

      {step === 2 && tipo && (
        <><h1 className="b-h">Elija día y hora</h1><p className="b-p">{tipo.nombre} · {tipo.duracion} min · hora de Madrid</p>
          {!slots && <p className="b-p">Buscando huecos libres…</p>}
          {slots && !days.length && <p className="b-p">No quedan huecos en las próximas semanas. Escríbanos a <a href="mailto:isabel@odlc.es">isabel@odlc.es</a>.</p>}
          {days.length > 0 && <>
            <div className="b-days" role="group" aria-label="Día">{days.map((d) => { const x = fmtDay(d); return (
              <button key={d} type="button" aria-pressed={d === day} onClick={() => { setDay(d); setHour(''); }}><small>{x.wd}</small><b>{x.n}</b><small>{x.mo}</small></button>); })}</div>
            <div className="b-hours" role="group" aria-label="Hora">{(slots[day] || []).map((h) => (
              <button key={h} type="button" aria-pressed={h === hour} onClick={() => setHour(h)}>{h}</button>))}</div>
            <button type="button" className="t-btn" disabled={!hour} onClick={() => setStep(3)} style={{ opacity: hour ? 1 : 0.5 }}>Continuar</button></>}
          <button type="button" className="b-back" onClick={() => setStep(1)}>← Cambiar tipo de cita</button></>)}

      {step === 3 && (
        <form className="b-form" onSubmit={submit} noValidate>
          <div className="b-sum"><b>{tipo.nombre}</b>{fmtDay(day).long} · {hour} h (Madrid) · {tipo.duracion} min · Microsoft Teams</div>
          <div><label htmlFor="b-n">Nombre y apellidos *</label><input id="b-n" value={f.nombre} onChange={set('nombre')} autoComplete="name" />{errs.nombre && <p className="b-err">{errs.nombre}</p>}</div>
          <div><label htmlFor="b-e">Correo electrónico *</label><input id="b-e" type="email" value={f.email} onChange={set('email')} autoComplete="email" />{errs.email && <p className="b-err">{errs.email}</p>}</div>
          <div><label htmlFor="b-t">Teléfono</label><input id="b-t" type="tel" value={f.telefono} onChange={set('telefono')} autoComplete="tel" />{errs.telefono && <p className="b-err">{errs.telefono}</p>}</div>
          <div><label htmlFor="b-c">Empresa</label><input id="b-c" value={f.empresa} onChange={set('empresa')} autoComplete="organization" /></div>
          <div><label htmlFor="b-m">¿De qué le gustaría hablar?</label><textarea id="b-m" value={f.mensaje} onChange={set('mensaje')} maxLength={1000} /></div>
          <div className="b-trap" aria-hidden="true"><label>Web<input tabIndex={-1} value={f.web} onChange={set('web')} autoComplete="off" /></label></div>
          <label className="chk"><input type="checkbox" checked={f.acepto} onChange={set('acepto')} /><span>He leído la <a href="https://odlc.es/privacidad.html" target="_blank" rel="noopener">política de privacidad</a>. Mis datos se usarán solo para gestionar esta cita.</span></label>
          {errs.acepto && <p className="b-err">{errs.acepto}</p>}
          <button className="t-btn" type="submit" disabled={busy}>{busy ? 'Reservando…' : 'Confirmar la cita'}</button>
          <button type="button" className="b-back" onClick={() => setStep(2)}>← Cambiar día u hora</button>
        </form>)}
      {msg && <p className="b-err" role="alert">{msg}</p>}
    </Card>
  );
}
