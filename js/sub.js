// Unterseiten: Jahreszahl und mobiles Menü
const year = document.getElementById('year');
if (year) year.textContent = new Date().getFullYear();

const navToggle = document.getElementById('nav-toggle');
const mainNav = document.getElementById('main-nav');

if (navToggle && mainNav) {
  const setNavOpen = (open) => {
    mainNav.classList.toggle('open', open);
    navToggle.setAttribute('aria-expanded', String(open));
    navToggle.setAttribute('aria-label', open ? 'Menü schließen' : 'Menü öffnen');
  };
  navToggle.addEventListener('click', () => setNavOpen(!mainNav.classList.contains('open')));
  mainNav.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => setNavOpen(false)));
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && mainNav.classList.contains('open')) {
      setNavOpen(false);
      navToggle.focus();
    }
  });
}
