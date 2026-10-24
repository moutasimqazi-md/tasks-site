(() => {
  'use strict';
  const KEY = 'tl-theme';
  const root = document.documentElement;
  const read = () => { try { return localStorage.getItem(KEY); } catch (e) { return null; } };
  const write = (v) => { try { localStorage.setItem(KEY, v); } catch (e) { /* private mode: still works for this page */ } };
  const system = () => (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  const current = () => root.getAttribute('data-theme') || system();

  const saved = read();
  if (saved === 'dark' || saved === 'light') root.setAttribute('data-theme', saved);

  function paint(btn) {
    const dark = current() === 'dark';
    btn.textContent = dark ? '\u2600\uFE0F' : '\uD83C\uDF19';
    btn.title = dark ? 'Switch to light mode' : 'Switch to dark mode';
    btn.setAttribute('aria-label', btn.title);
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-role="theme-toggle"]').forEach((btn) => {
      paint(btn);
      btn.addEventListener('click', () => {
        const next = current() === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        write(next);
        document.querySelectorAll('[data-role="theme-toggle"]').forEach(paint);
        window.dispatchEvent(new Event('themechange'));   // charts redraw in the new colours
      });
    });
  });
})();
