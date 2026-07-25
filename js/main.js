// MEZI/SMĚRY 2 — interakce a animace (podle šablony itconf.framer.website)

// Zarovnání prvního "okna": hero + dlaždice končí přesně tam,
// kde končí sticky sloupec (spodní hrany KDY/KDE/PRO KOHO a REGISTRACE lícují)
function syncHeroHeight() {
  const hero = document.querySelector('.hero-card');
  const sticky = document.querySelector('.side-sticky');
  const tile = document.querySelector('.tile');
  if (!hero || !sticky || !tile) return;

  if (window.innerWidth > 1100) {
    const gap = 10;
    hero.style.minHeight = (sticky.offsetHeight - tile.offsetHeight - gap) + 'px';
  } else {
    hero.style.minHeight = '';
  }
}

window.addEventListener('resize', syncHeroHeight);
window.addEventListener('load', syncHeroHeight);
syncHeroHeight();

// Scroll-reveal: prvky s .reveal se objeví při vstupu do viewportu
const observer = new IntersectionObserver(
  (entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        entry.target.classList.add('in');
        observer.unobserve(entry.target);
      }
    });
  },
  { threshold: 0.12, rootMargin: '0px 0px -40px 0px' }
);

document.querySelectorAll('.reveal').forEach((el) => observer.observe(el));

// Přepínání dnů programu
const dayButtons = document.querySelectorAll('.day-btn');
const agendas = document.querySelectorAll('.agenda');

dayButtons.forEach((btn) => {
  btn.addEventListener('click', () => {
    dayButtons.forEach((b) => b.classList.remove('active'));
    btn.classList.add('active');

    agendas.forEach((a) => a.classList.remove('active'));
    const target = document.getElementById('day-' + btn.dataset.day);
    target.classList.add('active');

    // řádky nově zobrazeného dne rovnou odhalit
    target.querySelectorAll('.reveal').forEach((el) => el.classList.add('in'));
  });
});

// FAQ: nechat otevřenou vždy jen jednu položku
document.querySelectorAll('.faq-item').forEach((item) => {
  item.addEventListener('toggle', () => {
    if (item.open) {
      document.querySelectorAll('.faq-item[open]').forEach((other) => {
        if (other !== item) other.open = false;
      });
    }
  });
});
