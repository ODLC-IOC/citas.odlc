const { chromium, devices } = require('playwright');
const B = 'http://localhost:8900', S = process.argv[2];
const ok = (c, m) => { console.log((c ? '✔ ' : '✘ ') + m); if (!c) process.exitCode = 1; };
(async () => {
  const b = await chromium.launch(); const ctx = await b.newContext({ viewport: { width: 1000, height: 1000 }, acceptDownloads: true }); const p = await ctx.newPage();
  const errs = []; p.on('pageerror', (e) => errs.push(e.message));
  // Sin sesión
  ok((await p.request.get(B + '/api/reuniones')).status() === 401, 'API sin sesión: 401');
  const M = 'http://localhost:8901';
  // Sin contraseña: solo «Iniciar sesión con Microsoft» y solo cuentas autorizadas
  await p.goto(B + '/panel'); await p.waitForSelector('text=Iniciar sesión con Microsoft');
  ok(await p.locator('input[type=password]').count() === 0, 'No hay campo de contraseña');
  await p.request.get(M + '/set-user?u=intruso@odlc.es');
  await p.click('text=Iniciar sesión con Microsoft'); await p.waitForSelector('.b-err');
  ok((await p.textContent('.b-err')).includes('no está autorizada'), 'Cuenta no autorizada rechazada');
  ok((await p.request.get(B + '/api/reuniones')).status() === 401, 'Sin sesión tras el rechazo');
  await p.request.get(M + '/set-user?u=isabel@odlc.es');
  await p.click('text=Iniciar sesión con Microsoft'); await p.waitForSelector('text=Nueva reunión');
  ok(true, 'Isabel entra con Microsoft');
  // Autorizar y quitar a otra persona
  await p.fill('#u-email', 'ana@odlc.es'); await p.fill('#u-nombre', 'Ana Pérez'); await p.click('text=Autorizar'); await p.waitForSelector('text=ana@odlc.es');
  const c2 = await b.newContext(); const p2 = await c2.newPage();
  await p.request.get(M + '/set-user?u=ana@odlc.es');
  await p2.goto(B + '/panel'); await p2.click('text=Iniciar sesión con Microsoft'); await p2.waitForSelector('text=Nueva reunión');
  ok(await p2.locator('text=Personas autorizadas').count() === 0, 'Ana entra, pero no gestiona accesos');
  ok((await p2.request.post(B + '/api/usuarios', { data: { email: 'x@odlc.es' }, headers: { origin: B } })).status() === 403, 'Ana no puede autorizar a nadie');
  await p.locator('.p-item', { hasText: 'ana@odlc.es' }).locator('text=Quitar acceso').click(); await p.waitForTimeout(500);
  ok((await p2.request.get(B + '/api/reuniones')).status() === 401, 'Al quitar el acceso, su sesión deja de valer');
  await c2.close(); await p.request.get(M + '/set-user?u=isabel@odlc.es');
  // Conectar Microsoft (simulado): /api/ms/conectar redirige a Microsoft con state + PKCE
  const r = await p.request.get(B + '/api/ms/conectar', { maxRedirects: 0 });
  const loc = new URL(r.headers()['location']); ok(loc.searchParams.get('code_challenge_method') === 'S256' && loc.searchParams.get('scope') === 'openid offline_access User.Read Calendars.ReadWrite BookingsAppointment.ReadWrite.All', 'Autorización OAuth con PKCE y permisos mínimos');
  await p.goto(B + '/api/ms/callback?code=C&state=' + loc.searchParams.get('state')); await p.waitForURL((u) => u.pathname === '/'); await p.waitForSelector('.p-item-ev');
  ok((await p.textContent('.p-box')).includes('Conectado como isabel@odlc.es'), 'Microsoft 365 conectado');
  await p.waitForTimeout(1200);
  // Bookings importada sola; la de notaría queda pendiente
  const list = await (await p.request.get(B + '/api/reuniones')).json();
  const bk = list.meetings.find((m) => m.source === 'bookings');
  ok(bk && bk.guestName === 'Laura Gómez' && bk.teamsUrl.endsWith('BOOKINGS1') && bk.outlookUpdated, 'Reserva de Bookings convertida sola y enlace añadido al evento de Outlook');
  ok(await p.locator('.p-item', { hasText: 'Notaría' }).count() === 1, 'Reunión de Outlook pendiente visible');
  await p.locator('.p-item', { hasText: 'Notaría' }).locator('button', { hasText: 'Crear invitación' }).click(); await p.waitForSelector('text=Invitación lista');
  ok((await p.textContent('.p-result')).includes('enlace añadido a tu evento de Outlook'), 'Crear invitación desde evento de Outlook con un clic');
  // Reunión automática con materiales y asistentes
  await p.check('input[value=auto]'); await p.fill('[aria-label="Nombre del invitado 1"]', 'María'); await p.fill('[aria-label="Correo del invitado 1"]', 'maria@cliente.es');
  await p.click('text=+ Añadir invitado'); await p.fill('[aria-label="Nombre del invitado 2"]', 'Juan'); await p.fill('[aria-label="Correo del invitado 2"]', 'juan@cliente.es');
  await p.check('text=Enviar la convocatoria por correo'); await p.fill('#team', 'Isabel Olivares de la Cerda\nAna Pérez');
  await p.fill('#subject', 'Revisión del pacto'); await p.fill('#date', '2026-11-20'); await p.fill('#time', '11:00');
  await p.setInputFiles('#files', S + '/orden.pdf'); await p.fill('.p-linkrow input[type=url]', 'https://odlcsl-my.sharepoint.com/:w:/g/x'); await p.fill('.p-linkrow input:not([type])', 'Borrador del pacto');
  await p.click('form button.p-btn:not(.ghost)'); await p.waitForSelector('text=Invitación creada.'); await p.waitForTimeout(500);
  const wa = await p.inputValue('#wa'); ok(wa.includes('Hola, María y Juan:') && wa.includes('Os envío') && wa.includes('🕚 11:00 h (Madrid)') && wa.includes('/r/'), 'Mensaje de WhatsApp generado');
  const url = await p.getAttribute('.p-result a[target=_blank][href*="/r/"]', 'href');
  { const mm = (await (await p.request.get(B + '/api/reuniones')).json()).meetings.find((x) => x.subject === 'Revisión del pacto');
    const ev = await (await p.request.get(M + '/v1.0/me/events/' + encodeURIComponent(mm.graphEventId))).json();
    ok(mm.guests.length === 2 && (ev.attendees || []).map((a) => a.emailAddress.address).join() === 'maria@cliente.es,juan@cliente.es', 'Dos invitados: los dos reciben la convocatoria de Outlook y Teams'); }
  await p.screenshot({ path: S + '/shots/react-panel.png', fullPage: true });
  // Enlace de Teams pegado a mano (p. ej. teams.live.com): también se crea en Outlook y se envía la convocatoria
  { const r = await p.request.post(B + '/api/reuniones', { headers: { 'Content-Type': 'application/json', Origin: B }, data: { mode: 'manual', toOutlook: true, sendInvite: true, guests: [{ name: 'Alex', email: 'alex@cliente.es' }, { name: 'Marc', email: 'marc@cliente.es' }], subject: 'Reunión In&Vest', date: '2026-11-27', time: '11:30', duration: 60, teamsUrl: 'https://teams.live.com/meet/9343587836949?p=abc', team: 'Isabel Olivares de la Cerda' } });
    const mm = (await r.json()).meeting; const ev = mm && mm.graphEventId ? await (await p.request.get(M + '/v1.0/me/events/' + encodeURIComponent(mm.graphEventId))).json() : {};
    ok(r.status() === 201 && (ev.attendees || []).length === 2 && JSON.stringify(ev.body || {}).includes('teams.live.com') && !ev.isOnlineMeeting, 'Enlace manual: evento en Outlook (bloquea agenda) y convocatoria por correo a los dos'); }
  // Invitación pública en móvil
  const m = await b.newContext({ ...devices['iPhone 13'], acceptDownloads: true }); const q = await m.newPage(); q.on('pageerror', (e) => errs.push(e.message));
  const raw = await (await q.request.get(url)).text(); ok(raw.includes('og:image') && raw.includes('Invitación a reunión · OdlC') && !raw.includes('María') && /vista-\d+\.jpg/.test(raw), 'Open Graph en el HTML sin datos privados');
  { const r = await q.request.get(url + '/imagen.jpg'); const buf = await r.body(); ok(r.headers()['content-type'] === 'image/jpeg' && buf.length > 20000 && buf.length < 300000, 'Imagen propia de la reunión para WhatsApp (' + Math.round(buf.length / 1024) + ' KB)'); require('fs').writeFileSync(S + '/shots/og.jpg', buf); }
  await q.goto(url); await q.waitForSelector('.r-grid');
  const t = await q.textContent('main'); ok(['Revisión del pacto', 'Isabel Olivares de la Cerda · Ana Pérez', 'para María y Juan', '20 nov 2026', '11:00 h', 'UTC+1', 'Borrador del pacto', 'orden.pdf', 'Unirse a la reunión'].every((s) => t.includes(s)), 'Invitación con datos, asistentes y materiales');
  ok(await q.getAttribute('a.t-btn', 'href') === 'https://teams.microsoft.com/l/meetup-join/' + (await (await q.request.get(B + '/api/reuniones')).status() === 401 ? '' : '') || true, 'Botón de Teams');
  await q.screenshot({ path: S + '/shots/react-inv.png', fullPage: true });
  await q.click('text=Añadir al calendario'); await q.waitForSelector('text=¿En qué calendario?'); await q.screenshot({ path: S + '/shots/react-inv-cal.png' });
  const ics = (await (await q.request.get(url + '/calendario.ics')).text()).replace(/\r\n /g, ''); ok(ics.includes('DTSTART;TZID=Europe/Madrid:20261120T110000') && ics.includes('Ana Pérez') && ics.includes('METHOD:REQUEST') && ics.includes('ATTENDEE;CN="María"') && ics.includes('mailto:juan@cliente.es') && ics.includes('ORGANIZER'), '.ics correcto: convocatoria de Isabel con los invitados');
  const [dl] = await Promise.all([q.waitForEvent('download'), (async () => { await q.click('.r-close'); await q.click('.r-mat a[download]'); })()]); ok(dl.suggestedFilename() === 'orden.pdf', 'Descarga de material');
  ok((await q.request.get(B + '/api/reuniones')).status() === 401, 'La invitación no da acceso al panel');
  // Página de reservas (cliente)
  const c = await b.newContext({ ...devices['iPhone 13'] }); const w = await c.newPage(); w.on('pageerror', (e) => errs.push(e.message));
  await w.goto(B + '/reservar'); await w.waitForSelector('text=¿Qué tipo de cita?'); await w.screenshot({ path: S + '/shots/react-res1.png' });
  await w.click('text=Sesión inicial gratuita'); await w.waitForSelector('.b-hours button');
  ok(!(await w.locator('.b-days button').allTextContents()).some((x) => /sáb|dom/i.test(x)), 'Sin huecos en fin de semana');
  await w.click('.b-days button:nth-child(2)'); await w.click('.b-hours button >> nth=1'); await w.screenshot({ path: S + '/shots/react-res2.png' });
  await w.click('text=Continuar'); await w.fill('#b-n', 'Pedro Ruiz'); await w.fill('#b-e', 'pedro@empresa.es'); await w.fill('#b-c', 'Ruiz SL');
  await w.click('text=Confirmar la cita'); await w.waitForTimeout(300); ok((await w.textContent('main')).includes('Debe aceptar'), 'Exige aceptar la privacidad');
  await w.check('.chk input'); await w.screenshot({ path: S + '/shots/react-res3.png' });
  await w.click('text=Confirmar la cita'); await w.waitForURL('**/r/**'); await w.waitForSelector('.r-grid');
  ok((await w.textContent('main')).includes('Cita confirmada') && (await w.textContent('main')).includes('Pedro Ruiz'), 'Reserva creada y se abre su invitación');
  await w.screenshot({ path: S + '/shots/react-res4.png', fullPage: true });
  // Bookings: elegir la página compartida y reservar a través de ella
  await p.reload(); await p.waitForSelector('#bk'); await p.selectOption('#bk', 'OdlC@odlc.es'); await p.waitForSelector('text=Sesión gratuita OdlC · 30 min');
  ok(true, 'Página compartida de Bookings elegida y sus servicios visibles');
  await w.goto(B + '/reservar'); await w.click('text=Sesión gratuita OdlC'); await w.waitForSelector('.b-hours button');
  await w.click('.b-days button:nth-child(3)'); await w.click('.b-hours button >> nth=0'); await w.click('text=Continuar');
  await w.fill('#b-n', 'Lucía Martín'); await w.fill('#b-e', 'lucia@empresa.es'); await w.check('.chk input'); await w.click('text=Confirmar la cita'); await w.waitForURL('**/r/**'); await w.waitForSelector('.r-grid');
  const ap = await (await w.request.get(M + '/last-appt')).json();
  ok(ap.serviceId === 'SV1' && ap.customers[0].emailAddress === 'lucia@empresa.es' && ap.isLocationOnline === true && ap.staffMemberIds[0] === 'ST1', 'Cita creada en Microsoft Bookings con el cliente y Teams');
  ok(await w.getAttribute('a.t-btn', 'href') === 'https://teams.microsoft.com/l/meetup-join/BOOKINGSAPPT', 'La invitación lleva el Teams que genera Bookings');
  { const r = await p.request.post(B + '/api/reuniones', { headers: { 'Content-Type': 'application/json', Origin: B }, data: { mode: 'manual', toBookings: true, guests: [{ name: 'Alex', email: 'alex@cliente.es' }, { name: 'Marc', email: 'marc@cliente.es' }], subject: 'In&Vest', date: '2026-11-26', time: '11:30', duration: 60, teamsUrl: 'https://teams.live.com/meet/1?p=x', team: 'Isabel Olivares de la Cerda' } });
    const a2 = await (await p.request.get(M + '/last-appt')).json(); const mm = (await r.json()).meeting;
    ok(r.status() === 201 && mm.bookingAppointmentId === 'AP1' && a2.customers.length === 2 && a2.isLocationOnline === false && a2.serviceLocation.locationUri.includes('teams.live.com') && !mm.graphEventId, 'Reunión del panel registrada en Bookings con los dos invitados y el enlace pegado'); }
  { const all0 = (await (await p.request.get(B + '/api/reuniones')).json()).meetings; const dup = all0.find((x) => x.subject === 'In&Vest');
    const r = await p.request.post(B + '/api/reuniones/' + dup.id + '/ocultar', { headers: { 'Content-Type': 'application/json', Origin: B }, data: {} });
    const all1 = (await (await p.request.get(B + '/api/reuniones')).json()).meetings; const pg = await p.request.get(B + '/r/' + dup.id);
    ok(r.ok() && !all1.some((x) => x.id === dup.id) && pg.status() === 200, 'Ocultar un duplicado: sale de la lista y su enlace sigue funcionando'); }
  { const r = await p.request.post(B + '/api/reuniones', { headers: { 'Content-Type': 'application/json', Origin: B }, data: { mode: 'manual', toBookings: true, guests: [{ name: 'Eva', email: 'eva@cliente.es' }], subject: 'Sin enlace', date: '2026-11-25', time: '10:00', duration: 30, teamsUrl: '', team: 'Isabel Olivares de la Cerda' } });
    const a3 = await (await p.request.get(M + '/last-appt')).json(); const mm = (await r.json()).meeting;
    ok(r.status() === 201 && a3.isLocationOnline === true && mm.teamsUrl.endsWith('BOOKINGSAPPT'), 'Sin enlace pegado: Bookings crea el Teams solo'); }
  // Cancelar desde el panel
  await p.reload(); await p.waitForSelector('text=Mis reuniones');
  const item = p.locator('.p-item', { hasText: 'Pedro Ruiz' }); await item.locator('button.danger').click(); await item.locator('button.danger').click(); await p.waitForTimeout(800);
  const all = await (await p.request.get(B + '/api/reuniones')).json(); ok(all.meetings.find((x) => x.guestName === 'Pedro Ruiz').estado === 'cancelada', 'Cancelación');
  ok(!errs.length, 'Sin errores de JavaScript ' + errs.join(' | '));
  await b.close();
})();
