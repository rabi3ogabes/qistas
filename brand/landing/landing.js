/* Landing + 404: inline logos, icons and the scroll reveal. No network calls. */
(function () {
  'use strict';
  var Logo = window.QistasLogo, Icon = window.QIcon;
  function $(s) { return document.querySelector(s); }
  var brand = $('#brand'); if (brand && Logo) brand.innerHTML = Logo.svg('wordmark', { title: 'Qistas' });
  var foot = $('#footLogo'); if (foot && Logo) foot.innerHTML = Logo.svg('wordmark', { title: 'Qistas' });
  document.querySelectorAll('[data-ic]').forEach(function (el) { el.innerHTML = Icon.svg(el.getAttribute('data-ic'), { size: 18 }); el.style.display = 'inline-flex'; });

  var items = Array.prototype.slice.call(document.querySelectorAll('.rv'));
  if (!('IntersectionObserver' in window)) { items.forEach(function (e) { e.classList.add('in'); }); return; }
  items.forEach(function (el, i) { el.style.setProperty('--d', Math.min(i % 3, 3) * 80 + 'ms'); });
  var io = new IntersectionObserver(function (es) {
    es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
  }, { threshold: 0.08, rootMargin: '0px 0px -4% 0px' });
  items.forEach(function (el) { io.observe(el); });
})();
