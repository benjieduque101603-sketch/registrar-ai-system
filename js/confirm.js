/* ============================================================
   CONFIRM.JS - a styled replacement for native confirm()

   Why this exists: native confirm() is drawn by the OS, not by
   the page. On Chrome it arrives as an unstyled "localhost says"
   box that blocks the whole tab, cannot be themed, and — because
   it looks nothing like the rest of the UI — reads to users as an
   error state rather than a question. The document desk hit this
   worst: the Collect confirmation looked like a broken button.

   Usage (note: the calling function must be async):

       if (!await confirmAction({
           title: 'Collect document?',
           body: 'Hand <b>Certificate of Enrollment</b> to the student?',
           confirmLabel: 'Collect',
           tone: 'primary' | 'danger'   // default 'primary'
       })) return;

   Resolves true when confirmed, false when dismissed. The modal is
   created once and reused, so repeated calls cost nothing.
   ============================================================ */
(function () {
    'use strict';

    if (window.confirmAction) return;   // already loaded

    var el = null;
    var resolver = null;   // the pending promise's resolver
    var lastFocus = null;

    function build() {
        var wrap = document.createElement('div');
        wrap.className = 'modal-overlay';
        wrap.id = 'confirmActionModal';
        wrap.setAttribute('role', 'dialog');
        wrap.setAttribute('aria-modal', 'true');
        wrap.setAttribute('aria-labelledby', 'confirmActionTitle');
        wrap.innerHTML =
            '<div class="modal-content ca-shell">' +
                '<div class="modal-header">' +
                    '<h3 id="confirmActionTitle"><i class="fa-solid fa-circle-question"></i> <span id="confirmActionHeading"></span></h3>' +
                    '<button type="button" class="modal-close" data-ca="cancel" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>' +
                '</div>' +
                '<div class="modal-body">' +
                    '<p class="ca-body" id="confirmActionBody"></p>' +
                '</div>' +
                '<div class="modal-footer ca-foot" style="border-top:1px solid #e2e8f0;">' +
                    '<button type="button" class="btn btn-secondary" data-ca="cancel">Cancel</button>' +
                    '<button type="button" class="btn btn-primary" data-ca="ok" id="confirmActionOk">Confirm</button>' +
                '</div>' +
            '</div>';

        document.body.appendChild(wrap);
        el = wrap;

        // Overlay click: only when the backdrop itself is the target, so a
        // stray click inside the dialog cannot dismiss it.
        wrap.addEventListener('click', function (e) {
            if (e.target === wrap) settle(false);
        });
        wrap.addEventListener('click', function (e) {
            var act = e.target.closest('[data-ca]');
            if (act) settle(act.getAttribute('data-ca') === 'ok');
        });
    }

    function settle(value) {
        if (!resolver) return;
        var done = resolver;
        resolver = null;
        if (el) {
            el.classList.remove('active');
            document.body.style.overflow = '';
        }
        // Return focus where it came from, so keyboard users are not
        // dumped back at the top of the document.
        if (lastFocus && document.contains(lastFocus)) lastFocus.focus();
        lastFocus = null;
        done(value);
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && resolver) settle(false);
    });

    // Escaping helper, exported because the confirm body is set as HTML:
    // student names, file names and section codes are interpolated into
    // it, and any of them containing a quote or a tag would otherwise
    // become markup. Several pages already had a private esc(); this is
    // the shared one, and pages that need it can use either.
    window.escText = window.escText || function (s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    };

    window.confirmAction = function (opts) {
        opts = opts || {};
        if (!el) build();

        // A second call while one is open resolves the first as declined,
        // rather than stranding its promise forever.
        if (resolver) settle(false);

        document.getElementById('confirmActionHeading').textContent = opts.title || 'Are you sure?';
        // body may contain markup (document names, counts), so it is set
        // as HTML — callers pass server-escaped strings, same as elsewhere.
        document.getElementById('confirmActionBody').innerHTML = opts.body || '';

        var ok = document.getElementById('confirmActionOk');
        // textContent, not innerHTML: a button label is text, and callers
        // pass plain strings here. The BODY is the markup surface (it may
        // contain <strong> for a document or student name) — that is why
        // escText exists and why the body must be escaped by the caller.
        ok.textContent = opts.confirmLabel || 'Confirm';
        ok.className = 'btn ' + (opts.tone === 'danger' ? 'btn-danger' : 'btn-primary');

        lastFocus = document.activeElement;
        el.classList.add('active');
        document.body.style.overflow = 'hidden';
        ok.focus();

        return new Promise(function (res) { resolver = res; });
    };
})();
