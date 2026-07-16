const menuButton = document.querySelector('[data-menu]');
const backdrop = document.querySelector('[data-backdrop]');

function closeMenu() {
  document.body.classList.remove('menu-open');
}

menuButton?.addEventListener('click', () => document.body.classList.toggle('menu-open'));
backdrop?.addEventListener('click', closeMenu);

document.querySelectorAll('[data-confirm]').forEach((form) => {
  form.addEventListener('submit', (event) => {
    if (!window.confirm(form.dataset.confirm || 'Confermare?')) {
      event.preventDefault();
    }
  });
});
