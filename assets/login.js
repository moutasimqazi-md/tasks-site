(() => {
  'use strict';
  const pw = document.getElementById('pw');
  const btn = document.getElementById('pw-toggle');
  if (!pw || !btn) return;
  btn.addEventListener('click', () => {
    const show = pw.type === 'password';
    pw.type = show ? 'text' : 'password';
    btn.textContent = show ? 'Hide' : 'Show';
    btn.setAttribute('aria-pressed', String(show));
    btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    pw.focus();
  });
})();
