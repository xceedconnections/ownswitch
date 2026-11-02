/* Ownswitch CRM UI helpers. No inline JS; no innerHTML with untrusted data. */
(function () {
    'use strict';

    document.addEventListener('submit', function (ev) {
        var msg = ev.target && ev.target.getAttribute ? ev.target.getAttribute('data-confirm') : null;
        if (msg && !window.confirm(msg)) {
            ev.preventDefault();
        }
    });

    document.addEventListener('submit', function (ev) {
        if (ev.defaultPrevented) { return; }
        var btns = ev.target.querySelectorAll('button[type="submit"][data-once]');
        btns.forEach(function (b) {
            window.setTimeout(function () {
                b.disabled = true;
                b.classList.add('disabled');
            }, 0);
        });
    });

    document.querySelectorAll('select[data-autosubmit]').forEach(function (sel) {
        sel.addEventListener('change', function () { if (sel.form) { sel.form.submit(); } });
    });

    // Close mobile offcanvas after nav click
    var sidebar = document.getElementById('osSidebar');
    if (sidebar && window.bootstrap) {
        sidebar.querySelectorAll('a.os-nav-link').forEach(function (link) {
            link.addEventListener('click', function () {
                var inst = bootstrap.Offcanvas.getInstance(sidebar);
                if (inst && window.matchMedia('(max-width: 991.98px)').matches) {
                    inst.hide();
                }
            });
        });
    }

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

    /* Magnus-style multi-select chips (codecs, etc.) — textContent only, no innerHTML */
    document.querySelectorAll('[data-os-multiselect]').forEach(function (wrap) {
        if (wrap.getAttribute('data-readonly') === '1') { return; }
        var hidden = wrap.querySelector('input[type="hidden"]');
        var chips = wrap.querySelector('[data-chips]');
        var add = wrap.querySelector('[data-add]');
        var tpl = wrap.querySelector('[data-chip-tpl]');
        if (!hidden || !chips || !add || !tpl) { return; }

        var sync = function () {
            var vals = [];
            chips.querySelectorAll('.os-ms-chip').forEach(function (chip) {
                var v = chip.getAttribute('data-value');
                if (v) { vals.push(v); }
            });
            hidden.value = vals.join(',');
            Array.prototype.forEach.call(add.options, function (opt) {
                if (!opt.value) { return; }
                opt.disabled = vals.indexOf(opt.value) !== -1;
            });
            add.value = '';
        };

        add.addEventListener('change', function () {
            var v = add.value;
            if (!v) { return; }
            var already = false;
            chips.querySelectorAll('.os-ms-chip').forEach(function (chip) {
                if (chip.getAttribute('data-value') === v) { already = true; }
            });
            if (already) { sync(); return; }
            var label = add.options[add.selectedIndex] ? add.options[add.selectedIndex].textContent : v;
            var node = tpl.content.firstElementChild.cloneNode(true);
            node.setAttribute('data-value', v);
            node.querySelector('.os-ms-chip-label').textContent = label;
            var btn = node.querySelector('[data-remove]');
            if (btn) { btn.setAttribute('aria-label', 'Remove ' + v); }
            chips.appendChild(node);
            sync();
        });

        chips.addEventListener('click', function (ev) {
            var btn = ev.target.closest('[data-remove]');
            if (!btn || !chips.contains(btn)) { return; }
            var chip = btn.closest('.os-ms-chip');
            if (chip) { chip.remove(); }
            sync();
        });

        sync();
    });
})();
