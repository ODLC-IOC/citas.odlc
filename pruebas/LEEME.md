# Pruebas locales de citas.odlc.es

1. `cd citas-app && npm install && npm run build`
2. Crear una carpeta de datos de prueba con `config.php` (ver LEEME principal) apuntando `graph_base` a `http://localhost:8901/v1.0` y `login_base` a `http://localhost:8901`.
3. `node pruebas/mockms.mjs` (Microsoft simulado) y `ODLC_DATA_DIR=/ruta/datos php -S localhost:8900 -t ../citas pruebas/router.php`
4. `node pruebas/e2e.cjs /ruta/con/orden.pdf` (Playwright): 25 comprobaciones de acceso, Microsoft, Bookings, materiales, invitación, .ics, reservas y cancelación.
