const openButton = document.querySelector('[data-open-menu]');
const backdrop = document.querySelector('[data-close-menu]');
function closeMenu() {
  document.body.classList.remove('menu-open');
  openButton?.setAttribute('aria-expanded', 'false');
}
openButton?.addEventListener('click', () => {
  const open = document.body.classList.toggle('menu-open');
  openButton.setAttribute('aria-expanded', String(open));
});
backdrop?.addEventListener('click', closeMenu);
document.addEventListener('keydown', event => {
  if (event.key === 'Escape') closeMenu();
});
