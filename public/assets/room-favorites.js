document.querySelectorAll('[data-room-favorite]').forEach(button => {
  const key = 'mo-favorite-' + button.dataset.roomFavorite;
  try { button.setAttribute('aria-pressed', String(localStorage.getItem(key) === 'true')); } catch {}
  button.addEventListener('click', () => {
    const selected = button.getAttribute('aria-pressed') !== 'true';
    button.setAttribute('aria-pressed', String(selected));
    try { localStorage.setItem(key, String(selected)); } catch {}
  });
});
