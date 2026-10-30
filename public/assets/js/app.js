/* Ownswitch UI helpers. No inline JS; no innerHTML with data. */
(function () {
    'use strict';

    // Confirm destructive actions: <form data-confirm="Delete this?">
    document.addEventListener('submit', function (ev) {
        var msg = ev.target && ev.target.getAttribute ? ev.target.getAttribute('data-confirm') : null;
        if (msg && !window.confirm(msg)) {
            ev.preventDefault();
        }
    });

    // Prevent double submits: disable submit buttons once the form is submitted.
    document.addEventListener('submit', function (ev) {
        if (ev.defaultPrevented) { return; }
        var btns = ev.target.querySelectorAll('button[type="submit"][data-once]');
        btns.forEach(function (b) { window.setTimeout(function () { b.disabled = true; }, 0); });
    });

    // Auto-submit selects marked data-autosubmit (list filters).
    document.querySelectorAll('select[data-autosubmit]').forEach(function (sel) {
        sel.addEventListener('change', function () { if (sel.form) { sel.form.submit(); } });
    });

    // Billing preview: UX-only mirror of the server formula (server always recalculates and is authoritative).
    var form = document.getElementById('billing-preview-form');
    var hint = document.getElementById('billing-live-hint');
    if (form && hint) {
        var calc = function () {
            var I = parseInt(form.elements.initial.value, 10);
            var S = parseInt(form.elements.subsequent.value, 10);
            var D = parseInt(form.elements.duration.value, 10);
            if (!(I > 0) || !(S > 0) || !(D >= 0)) { hint.textContent = ''; return; }
            var b = D === 0 ? 0 : (D <= I ? I : I + Math.ceil((D - I) / S) * S);
            hint.textContent = 'Estimated billable seconds: ' + b + ' (server result below is authoritative)';
        };
        form.addEventListener('input', calc);
        calc();
    }
})();
