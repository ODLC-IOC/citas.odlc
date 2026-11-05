// Llamadas a la API PHP del propio subdominio. La sesión va en una cookie HttpOnly: aquí no hay credenciales.
export class ApiError extends Error {
  constructor(message, status, fields) { super(message); this.status = status; this.fields = fields || {}; }
}
export async function api(path, { method = 'GET', body, raw, headers } = {}) {
  const init = { method, credentials: 'same-origin', headers: { ...(headers || {}) } };
  if (raw) init.body = raw;
  else if (body !== undefined) { init.body = JSON.stringify(body); init.headers['Content-Type'] = 'application/json'; }
  let r;
  try { r = await fetch('/api' + path, init); } catch { throw new ApiError('Sin conexión. Inténtalo de nuevo.', 0); }
  const d = await r.json().catch(() => ({}));
  if (!r.ok) throw new ApiError(d.error || 'Error ' + r.status, r.status, d.fields);
  return d;
}
export function waHref(m, text) {
  let p = (m.guestPhone || '').replace(/\D/g, '');
  if (p.length === 9) p = '34' + p;
  return 'https://wa.me/' + p + '?text=' + encodeURIComponent(text);
}
export async function copy(text) {
  try { await navigator.clipboard.writeText(text); return true; } catch {
    const t = document.createElement('textarea'); t.value = text; document.body.appendChild(t); t.select();
    let ok = false; try { ok = document.execCommand('copy'); } catch {} t.remove(); return ok;
  }
}
