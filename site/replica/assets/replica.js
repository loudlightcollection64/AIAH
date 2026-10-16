/* Stands in for Elementor Pro scripts: the phone-width menu toggle and a no-send note on the contact form. */
(function () {
  'use strict';

  document.querySelectorAll('.elementor-menu-toggle').forEach(function (toggle) {
    var widget = toggle.closest('.elementor-widget-nav-menu');
    var dropdown = widget ? widget.querySelector('.elementor-nav-menu--dropdown') : null;
    if (!dropdown) { return; }
    function set(open) {
      toggle.classList.toggle('elementor-active', open);
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      dropdown.classList.toggle('sma-replica-open', open);
      dropdown.setAttribute('aria-hidden', open ? 'false' : 'true');
      dropdown.querySelectorAll('a').forEach(function (a) { a.tabIndex = open ? 0 : -1; });
    }
    toggle.addEventListener('click', function () { set(!toggle.classList.contains('elementor-active')); });
    toggle.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle.click(); }
    });
    set(false);
  });

  document.querySelectorAll('form[data-sma-replica-form]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var note = form.querySelector('.sma-replica-form-note');
      if (!note) {
        note = document.createElement('p');
        note.className = 'sma-replica-form-note';
        note.setAttribute('role', 'status');
        form.appendChild(note);
      }
      note.textContent = 'This is the local demo copy: the contact form sends nothing here. On somanyangels.org it emails the team.';
    });
  });
})();
