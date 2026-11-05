// Lee una reunión exportada de Outlook (.ics) y la convierte a hora de Madrid.
const TEAMS_RE = /https:\/\/(?:[a-z0-9-]+\.)*(?:teams\.microsoft\.com|teams\.live\.com|teams\.cloud\.microsoft)\/[^\s<>"'\\)]+/i;
export function teamsFrom(text) { const m = String(text || '').replace(/\\n/g, ' ').match(TEAMS_RE); return m ? m[0].replace(/[.,;]+$/, '') : ''; }
const unesc = (v) => v.replace(/\\n/gi, '\n').replace(/\\([,;\\])/g, '$1');
function parts(ms, tz) {
  return new Intl.DateTimeFormat('en-CA', { timeZone: tz, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' })
    .formatToParts(new Date(ms)).reduce((o, x) => ((o[x.type] = x.value), o), {});
}
function offset(ms, tz) { const p = parts(ms, tz); return Date.UTC(+p.year, p.month - 1, +p.day, +p.hour % 24, +p.minute, +p.second) - ms; }
function toUtc(prop) {
  const m = prop.value.match(/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2}))?/); if (!m) return null;
  const naive = Date.UTC(+m[1], m[2] - 1, +m[3], +m[4] || 0, +m[5] || 0);
  if (/Z$/.test(prop.value)) return naive;
  const tzid = (prop.params.match(/TZID=("?)([^;:"]+)\1/i) || [])[2] || 'Europe/Madrid';
  const tz = /romance|madrid|w\. europe|central europe|paris|brussels/i.test(tzid) ? 'Europe/Madrid' : /gmt standard|london|dublin|lisbon/i.test(tzid) ? 'Europe/London' : /^utc$|greenwich/i.test(tzid) ? 'UTC' : tzid;
  try { let u = naive - offset(naive, tz); return naive - offset(u, tz); } catch { let u = naive - offset(naive, 'Europe/Madrid'); return naive - offset(u, 'Europe/Madrid'); }
}
export function parseOutlookIcs(text) {
  const ev = {}; let inEv = false, done = false;
  for (const l of text.replace(/\r?\n[ \t]/g, '').split(/\r?\n/)) {
    if (l === 'BEGIN:VEVENT' && !done) { inEv = true; continue; }
    if (l === 'END:VEVENT') { if (inEv) done = true; inEv = false; continue; }
    if (!inEv) continue;
    const i = l.indexOf(':'); if (i < 0) continue;
    const head = l.slice(0, i), name = head.split(';')[0].toUpperCase();
    if (!(name in ev)) ev[name] = { params: head, value: l.slice(i + 1) };
  }
  if (!ev.DTSTART) throw new Error('El archivo no contiene una reunión.');
  const s = toUtc(ev.DTSTART), e = ev.DTEND ? toUtc(ev.DTEND) : null;
  if (s === null) throw new Error('No se ha podido leer la fecha.');
  const p = parts(s, 'Europe/Madrid');
  let duration = null;
  if (e) { const mins = Math.round((e - s) / 60000); duration = [15, 30, 45, 60, 90].reduce((a, b) => (Math.abs(b - mins) < Math.abs(a - mins) ? b : a)); }
  const teamsUrl = ['X-MICROSOFT-SKYPETEAMSMEETINGURL', 'LOCATION', 'URL', 'DESCRIPTION'].map((k) => teamsFrom(ev[k] && ev[k].value)).find(Boolean) || teamsFrom(text);
  const description = ev.DESCRIPTION ? unesc(ev.DESCRIPTION.value).split(/_{10,}|Reunión de Microsoft Teams|Microsoft Teams meeting/i)[0].trim().slice(0, 2000) : '';
  return { subject: ev.SUMMARY ? unesc(ev.SUMMARY.value).slice(0, 160) : '', date: `${p.year}-${p.month}-${p.day}`, time: `${p.hour}:${p.minute}`, duration, teamsUrl, description };
}
