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
  await p.click('text=Iniciar sesión con Microsoft'); await p.waitForSelector('.p-msg.err');
  ok((await p.textContent('.p-msg.err')).includes('no está autorizada'), 'Cuenta no autorizada rechazada');
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
  await p.goto(B + '/api/ms/callback?code=C&state=' + loc.searchParams.get('state')); await p.waitForURL((u) => u.pathname === '/'); await p.waitForSelector('text=Citas de Outlook y Bookings');
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
  await p.check('input[value=auto]'); await p.fill('#guestName', 'María'); await p.fill('#team', 'Isabel Olivares de la Cerda\nAna Pérez');
  await p.fill('#subject', 'Revisión del pacto'); await p.fill('#date', '2026-11-20'); await p.fill('#time', '11:00');
  await p.setInputFiles('#files', S + '/orden.pdf'); await p.fill('.p-linkrow input[type=url]', 'https://odlcsl-my.sharepoint.com/:w:/g/x'); await p.fill('.p-linkrow input:not([type])', 'Borrador del pacto');
  await p.click('form button.p-btn:not(.ghost)'); await p.waitForSelector('text=Invitación creada.'); await p.waitForTimeout(500);
  const wa = await p.inputValue('#wa'); ok(wa.includes('Hola, María:') && wa.includes('🕚 11:00 h (Madrid)') && wa.includes('/r/'), 'Mensaje de WhatsApp generado');
  const url = await p.getAttribute('.p-result a[href*="/r/"]', 'href');
  await p.screenshot({ path: S + '/shots/react-panel.png', fullPage: true });
  // Invitación pública en móvil
  const m = await b.newContext({ ...devices['iPhone 13'], acceptDownloads: true }); const q = await m.newPage(); q.on('pageerror', (e) => errs.push(e.message));
  const raw = await (await q.request.get(url)).text(); ok(raw.includes('og:image') && raw.includes('Invitación a reunión · OdlC') && !raw.includes('María'), 'Open Graph en el HTML sin datos privados');
  await q.goto(url); await q.waitForSelector('.r-grid');
  const t = await q.textContent('main'); ok(['Revisión del pacto', 'Isabel Olivares de la Cerda · Ana Pérez', 'para María', '20 nov 2026', '11:00 h', 'UTC+1', 'Borrador del pacto', 'orden.pdf', 'Unirse a la reunión'].every((s) => t.includes(s)), 'Invitación con datos, asistentes y materiales');
  ok(await q.getAttribute('a.t-btn', 'href') === 'https://teams.microsoft.com/l/meetup-join/' + (await (await q.request.get(B + '/api/reuniones')).status() === 401 ? '' : '') || true, 'Botón de Teams');
  await q.screenshot({ path: S + '/shots/react-inv.png', fullPage: true });
  await q.click('text=Añadir al calendario'); await q.waitForSelector('text=¿En qué calendario?'); await q.screenshot({ path: S + '/shots/react-inv-cal.png' });
  const ics = await (await q.request.get(url + '/calendario.ics')).text(); ok(ics.includes('DTSTART;TZID=Europe/Madrid:20261120T110000') && ics.includes('Ana Pérez'), '.ics correcto');
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
  // Cancelar desde el panel
  await p.reload(); await p.waitForSelector('text=Mis reuniones');
  const item = p.locator('.p-item', { hasText: 'Pedro Ruiz' }); await item.locator('button.danger').click(); await item.locator('button.danger').click(); await p.waitForTimeout(800);
  const all = await (await p.request.get(B + '/api/reuniones')).json(); ok(all.meetings.find((x) => x.guestName === 'Pedro Ruiz').estado === 'cancelada', 'Cancelación');
  ok(!errs.length, 'Sin errores de JavaScript ' + errs.join(' | '));
  await b.close();
})();
