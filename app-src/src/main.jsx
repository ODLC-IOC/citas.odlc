import React from 'react';
import { createRoot } from 'react-dom/client';
import '../estilos/styles.css';
import '../estilos/tarjeta.css';
import './app.css';
import Invitation from './Invitation.jsx';
import Booking from './Booking.jsx';
import Panel from './Panel.jsx';

function App() {
  const path = window.location.pathname;
  const inv = path.match(/^\/r\/([A-Za-z0-9_-]{22})\/?$/);
  if (inv) return <Invitation id={inv[1]} />;
  // citas.odlc.es abre el gestor de citas (privado); la página pública de reservas está en /reservar
  if (path.startsWith('/reservar') || path.startsWith('/cita')) return <Booking />;
  return <Panel />;
}
createRoot(document.getElementById('root')).render(<App />);
