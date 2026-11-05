# citas.odlc.es · Reservas, reuniones e invitaciones de OdlC

App en React (código en `app-src/`) con API en PHP 8 (`api/`), servida por Hostinger en `public_html/citas` (subdominio `citas.odlc.es`). La web principal está en el repositorio `ODLC-IOC/ODLC-WEB`.

| URL | Qué es |
|---|---|
| `citas.odlc.es/` | **Gestor de citas** (panel privado): acceso solo con Microsoft y solo personas autorizadas |
| `citas.odlc.es/reservar` | Página de reservas para clientes (con Microsoft Bookings o directa a Outlook) |
| `citas.odlc.es/r/:id` | Invitación pública, `.ics` y materiales (solo lectura) |
| `citas.odlc.es/api/...` | API PHP |

### Estructura

- `index.html` y `app/`: la app compilada (es lo que se publica). Se genera con `cd app-src && npm install && npm run build`; el resultado se sube a Git para que Hostinger no tenga que compilar.
- `app-src/`: código fuente React y sus estilos (copia de `styles.css` y `tarjeta.css` de odlc.es, tipografías Lora y Poppins y logotipo original). Bloqueado al público con `.htaccess`.
- `api/`: PHP (`index.php` API, `r.php` invitaciones, `_lib/` lógica; `_lib` bloqueado al público).
- `pruebas/`: servidor local, Microsoft simulado y pruebas de extremo a extremo (28 comprobaciones superadas el 05/11/2026).

### Publicación en Hostinger

hPanel → odlc.es → GIT → crear repositorio `https://github.com/ODLC-IOC/citas.odlc.git`, rama `main`, directorio `citas` → Implementar. Para que se actualice solo, añadir en GitHub (Settings → Webhooks) la URL de implementación automática que da Hostinger. La carpeta `public_html/citas` debe estar vacía la primera vez.

### Datos y claves

En `public_html/_datos/` (fuera de Git, bloqueado con `.htaccess`): `config.php` con las claves, la lista de personas autorizadas, las reuniones, los materiales y la conexión con Microsoft. `config.php`:

```php
<?php return [
  'admin_email' => 'isabel@odlc.es',   // administradora: siempre autorizada
  'session_secret' => '…',              // 64 caracteres aleatorios
  'token_key' => '…',                   // 32 bytes en base64 (cifra el token del calendario)
  'site_url' => 'https://citas.odlc.es',
  'ms_tenant_id' => '…', 'ms_client_id' => '…', 'ms_client_secret' => '…',
  'auto_bookings' => true,              // convertir solas las reservas de Bookings
];
```

### Acceso al panel: sin contraseñas

- Solo **«Iniciar sesión con Microsoft»**, con el directorio de Microsoft 365 de OdlC (inquilino propio): cuentas @odlc.es o personas invitadas a ese Microsoft 365.
- Además, la cuenta tiene que estar en **Personas autorizadas** (sección del panel que solo ve la administradora). isabel@odlc.es está siempre; el resto se añade o se quita desde ahí. Al quitar a alguien, su sesión deja de valer en ese momento.
- Se identifica a cada persona por su nombre de inicio de sesión en el directorio de OdlC (`preferred_username`), comprobando inquilino (`tid`), emisor, destinatario, caducidad y `nonce`; nunca por el campo «email», que otras organizaciones pueden rellenar a su gusto.
- Solo la administradora puede conectar el calendario y gestionar accesos.

### Seguridad

Sesión en cookie `HttpOnly`, `Secure`, `SameSite=Strict`, firmada, de 8 horas; peticiones que cambian datos solo desde el propio sitio; OAuth 2.0 con PKCE, `state` de un solo uso y `nonce`. Identificadores de 128 bits. La invitación nunca muestra correo, móvil ni notas privadas. Token del calendario cifrado con libsodium. Reservas: casilla de privacidad, campo trampa contra robots y máximo 4 reservas por hora e IP.

### Microsoft 365: registrar la aplicación en Microsoft Entra ID

1. https://entra.microsoft.com (con isabel@odlc.es como administradora) → **Identity → Applications → App registrations → New registration**. Nombre `OdlC Citas`; *Accounts in this organizational directory only (Single tenant)*; Redirect URI **Web** `https://citas.odlc.es/api/ms/callback`.
2. Copiar *Application (client) ID* → `ms_client_id` y *Directory (tenant) ID* → `ms_tenant_id`.
3. **Certificates & secrets → New client secret** (24 meses) → copiar el **Value** → `ms_client_secret`. Apuntar su renovación.
4. **API permissions → Microsoft Graph → Delegated**: `openid`, `profile`, `email`, `offline_access`, `User.Read`, `Calendars.ReadWrite`, `BookingsAppointment.ReadWrite.All` → **Grant admin consent for OdlC**.
5. Entrar en `citas.odlc.es/panel` → **Iniciar sesión con Microsoft** → **Conectar con Microsoft 365** (calendario). Se comprueba que el calendario admite Teams y solo se acepta isabel@odlc.es.
6. Para dar acceso a alguien de fuera: en Microsoft 365, invitarle como invitado (Entra ID → Users → Invite external user) y añadir su correo en **Personas autorizadas**.

### Microsoft Bookings

La API de Microsoft solo permite crear citas en **páginas de reservas compartidas** de Bookings, no en la página personal «Bookings with me». Para que la página de citas.odlc.es reserve a través de Bookings:

1. https://outlook.office.com/bookings → **Nueva página de reservas** → **Compartida**. Nombre `OdlC`, zona horaria Madrid, horario de atención.
2. **Servicios**: crear «Sesión gratuita OdlC», 30 min, **Reunión de Teams** activado (y, si se quiere, «Arranque del Diagnóstico OdlC», 60 min). Isabel como miembro del personal.
3. En el panel → **Página de reservas · Microsoft Bookings** → elegir `OdlC`. Desde ese momento, citas.odlc.es muestra esos servicios, cada reserva se crea en Bookings (que envía al cliente su confirmación con Teams y la pone en el calendario de Isabel) y se abre la invitación de OdlC.
4. Los botones «Reservar sesión» de odlc.es pueden apuntar a `https://citas.odlc.es/` en lugar del enlace de Bookings (variable `CALENDAR_URL` de `assets/js/main.js`).

### Qué hace la conexión

- **Modo automático:** crea el evento en Outlook con Teams; el evento lleva el enlace de la invitación y de la tarjeta.
- **Citas de Outlook y Bookings:** el panel lista las reuniones de Teams de los próximos 60 días. Las reservas de Bookings (detectadas por el texto «Bookings»/«reserva») se convierten solas al abrir el panel o con la tarea programada; las demás, con «Crear invitación». El enlace se añade al principio del cuerpo del evento sin tocar el bloque de Teams (si el evento tiene invitados, Outlook puede enviarles la actualización).
- **Página de reservas `citas.odlc.es`:** muestra los huecos libres de tu calendario (L–J 9:30–14:00 y 16:00–18:30, V 9:30–14:00, 24 h de antelación, 21 días, 15 min de margen; se cambia en `_datos/reservas.json`). Al confirmar, crea el evento con Teams en tu Outlook, Outlook envía la convocatoria al cliente y se abre su invitación. Microsoft no permite crear citas por programa en la página personal «Bookings with me»; por eso la reserva va directa a tu calendario.

### Pruebas

`citas-app/pruebas/`: servidor PHP local, Microsoft simulado y comprobaciones de extremo a extremo (25 comprobaciones, todas superadas el 05/11/2026, incluido el rechazo de cuentas no autorizadas).
