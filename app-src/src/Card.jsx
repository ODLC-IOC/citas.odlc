import logo from '../estilos/img/logo-odlc-claro.svg';
export const LOGO_CLARO = logo;
export default function Card({ kicker = 'Invitación a reunión', children, wide }) {
  return (
    <div className="t-page">
      <main className={'t-card' + (wide ? ' t-wide' : '')}>
        <header className="t-head">
          <img src={LOGO_CLARO} alt="OdlC" width="230" height="75" />
          {kicker && <p className="t-kicker">{kicker}</p>}
        </header>
        <section className="t-body">
          {children}
          <p className="t-foot">OdlC · <a href="https://odlc.es">odlc.es</a></p>
        </section>
      </main>
    </div>
  );
}
export const Ico = {
  teams: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" aria-hidden="true"><rect x="3" y="6" width="13" height="12" rx="2"/><path d="m16 10.5 5-3v9l-5-3"/></svg>,
  cal: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15.5" rx="1.5"/><path d="M3.5 9.5h17M8 3v4M16 3v4M12 12.5v5M9.5 15h5"/></svg>,
};
