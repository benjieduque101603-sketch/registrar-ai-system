// ============================================================
//  JS/ST-PROGRAM-LISTBOX.JS
//  A custom listbox for the Status Tracker program filter.
//
//  Why this exists: a native <select> can only be styled at the trigger.
//  Everything after that - the panel, the rows, the counts - belongs to the
//  browser, and the browser's panel is the "plain dropdown" this replaces.
//
//  The native <select> is NOT replaced. It keeps name="program", it posts the
//  value, and it remains the whole control when JS is off. This hides it and
//  mirrors it, so the form keeps exactly one source of truth.
//
//  Follows the WAI-ARIA listbox pattern: the trigger owns role=combobox with
//  aria-expanded/aria-controls, the panel is role=listbox, each row is
//  role=option with aria-selected. Keyboard support is not optional here -
//  a custom listbox that only answers to a mouse is worse than the native
//  control it replaced.
// ============================================================

(function () {
    'use strict';

    var MAX_H = 300;   // panel max height before it scrolls

    function escHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // Option text arrives as "BS Computer Science (12)". Split the trailing
    // count off so a row can typeset the name and the number separately -
    // that separation is the reason for a custom listbox at all. A label with
    // no trailing count is kept whole rather than mangled.
    function splitCount(text) {
        var m = /^(.*?)\s*\((\d[\d,]*)\)\s*$/.exec(text);
        return m ? { label: m[1], count: m[2] } : { label: text, count: null };
    }

    // This roster stores the full degree title, which routinely runs past 300px
    // on its own: "Bachelor of Science in Information Technology (BSIT)". A
    // parenthesised ALL-CAPS token at the end is the school's own short form
    // for it, so the row leads with the acronym and keeps the full title
    // beside it. That is the registrar's own vocabulary, not a truncation
    // imposed by the control - and it means the count column stays readable
    // without a horizontal scrollbar.
    function splitAcronym(label) {
        var m = /^(.*\S)\s*\(([A-Z][A-Z0-9&/.\-]{1,11})\)\s*$/.exec(label);
        return m ? { short: m[2], full: m[1] } : { short: null, full: label };
    }

    function enhance(sel) {
        if (sel.dataset.stLbInit) return;
        sel.dataset.stLbInit = '1';

        var wrap = sel.parentNode;

        // Snapshot options once. The first empty-value option is the
        // "everything" row, kept out of the list rather than mixed in with
        // the programs - it is a different kind of choice.
        var all = {
            value: '',
            label: sel.options[0] ? sel.options[0].textContent.trim() : 'All programs',
            count: null
        };
        var opts = [];
        for (var i = 0; i < sel.options.length; i++) {
            var o = sel.options[i];
            if (o.value === '' && i === 0) continue;
            var p = splitCount(o.textContent.replace(/\s+/g, ' ').trim());
            var a = splitAcronym(p.label);
            opts.push({
                value: o.value,
                label: p.label,
                short: a.short,
                full: a.full,
                count: p.count
            });
        }

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'st-lb-btn';
        btn.setAttribute('role', 'combobox');
        btn.setAttribute('aria-haspopup', 'listbox');
        btn.setAttribute('aria-expanded', 'false');
        btn.innerHTML =
            '<span class="st-lb-val"></span>'
            + '<i class="fas fa-chevron-down st-lb-chev" aria-hidden="true"></i>';

        var panel = document.createElement('div');
        panel.className = 'st-lb-panel';
        panel.setAttribute('role', 'listbox');
        panel.id = panel.id || 'stProgPanel';
        panel.hidden = true;
        panel.style.maxHeight = MAX_H + 'px';

        wrap.appendChild(btn);
        wrap.appendChild(panel);

        // The native select still exists and still posts, but it is no longer
        // what a person sees or operates. aria-hidden keeps it out of the
        // accessibility tree so the two are not announced as one control.
        sel.classList.add('st-lb-native');
        sel.setAttribute('aria-hidden', 'true');
        sel.tabIndex = -1;
        btn.id = btn.id || 'stProgBtn';
        btn.setAttribute('aria-controls', panel.id);

        var rows = [];
        var active = -1;          // keyboard-highlighted row
        var lastValue = sel.value;

        function currentLabel() {
            if (sel.value === '') return all.label;
            for (var i = 0; i < opts.length; i++) {
                if (opts[i].value === sel.value) return opts[i].short || opts[i].label;
            }
            return all.label;
        }

        function renderTrigger() {
            btn.querySelector('.st-lb-val').textContent = currentLabel();
            // A chosen program is a filter in force. Saying so on the trigger
            // means that state survives collapsing the panel.
            btn.classList.toggle('has-val', sel.value !== '');
        }

        // "All programs" is a first-class row at the top, not an absence. It
        // is the only way back once a program is chosen, so hiding it would
        // strand the user on a filter they cannot undo from the panel; the
        // Clear link in the toolbar is not a substitute. It carries no count
        // of its own, because the number of programs is not what a registrar
        // is choosing between.
        function allRowHtml() {
            var chosen = sel.value === '';
            return '<div class="st-lb-row st-lb-all' + (chosen ? ' is-sel' : '') + '" role="option"'
                 + ' aria-selected="' + (chosen ? 'true' : 'false') + '" data-v="" data-i="-1">'
                 + '<span class="st-lb-check" aria-hidden="true"><i class="fas fa-check"></i></span>'
                 + '<span class="st-lb-lbl">' + escHtml(all.label) + '</span>'
                 + '</div>';
        }

        function rowHtml(o, i) {
            var chosen = o.value === sel.value;
            // The row leads with the acronym alone:
            //     ✓  [BSIT]                                    2
            //     ✓  [BSCS]                                    7
            // The full degree title is what the acronym MEANS, so it is
            // revealed on hover rather than printed beside it. Printing it
            // forced the panel out to ~560px to fit 50-character titles and
            // still left two of them truncated; the acronyms are what a
            // registrar scans, and the panel now fits the toolbar.
            //
            // A row with no acronym in the data has nothing to abbreviate, so
            // it shows its label in full and gets no tooltip - there would be
            // no second thing to say.
            var name = o.short
                ? '<span class="st-lb-tag">' + escHtml(o.short) + '</span>'
                : '<span class="st-lb-lbl">' + escHtml(o.label) + '</span>';
            return '<div class="st-lb-row' + (chosen ? ' is-sel' : '') + '" role="option"'
                 + ' aria-selected="' + (chosen ? 'true' : 'false') + '" data-v="'
                 + escHtml(o.value) + '" data-i="' + i + '"'
                 // The full meaning travels in data-tip and is shown by the
                 // shared tooltip. aria-label is the authoritative accessible
                 // name: a screen reader user must hear the full title, never
                 // a bare acronym they have no way to expand.
                 + (o.short ? ' data-tip="' + escHtml(o.full) + '"' : '')
                 + ' aria-label="' + escHtml(o.label) + '">'
                 + '<span class="st-lb-check" aria-hidden="true"><i class="fas fa-check"></i></span>'
                 + name
                 + (o.count !== null ? '<span class="st-lb-n">' + escHtml(o.count) + '</span>' : '')
                 + '</div>';
        }

        function renderPanel() {
            var html = allRowHtml();
            for (var i = 0; i < opts.length; i++) html += rowHtml(opts[i], i);
            if (!opts.length) {
                // The select renders even with an empty roster, so the panel
                // can open holding nothing but the "all" row. Say what that
                // means rather than showing a bare box and leaving the cause
                // to be guessed.
                html += '<div class="st-lb-empty">No programs on the roster yet</div>';
            }
            panel.innerHTML = html;
            rows = Array.prototype.slice.call(panel.querySelectorAll('.st-lb-row'));
        }

        // ── Tooltip ──────────────────────────────────────────
        // One shared element on the body, not one per row. The panel scrolls
        // (overflow-y:auto), so a tooltip inside it would be clipped by the
        // very container it sits in; fixed positioning on a body-level node
        // escapes that. One node also means one thing to reposition and one
        // to tear down.
        var tip = document.createElement('div');
        tip.className = 'st-lb-tip';
        tip.setAttribute('role', 'tooltip');
        tip.hidden = true;
        document.body.appendChild(tip);

        function showTip(row) {
            var text = row.getAttribute('data-tip');
            if (!text) return;               // no acronym => nothing to expand
            tip.textContent = text;
            tip.hidden = false;

            // Measure, then place. Prefer the left of the row so the tooltip
            // does not sit under the pointer and flicker as it is read.
            var r = row.getBoundingClientRect();
            var t = tip.getBoundingClientRect();
            var GAP = 10;
            var left = r.left - t.width - GAP;
            // If there is no room on the left, fall back to the right, then to
            // below. A tooltip that runs off the edge is worse than no tooltip.
            if (left < 8) left = r.right + GAP;
            if (left + t.width > window.innerWidth - 8) {
                left = Math.max(8, Math.min(r.left, window.innerWidth - t.width - 8));
            }
            var top = r.top + (r.height - t.height) / 2;
            // Keep it on screen vertically too, for short viewports.
            top = Math.max(8, Math.min(top, window.innerHeight - t.height - 8));
            tip.style.left = Math.round(left) + 'px';
            tip.style.top = Math.round(top) + 'px';

            // The fade-in needs the element to be laid out at opacity 0 first,
            // so the class goes on a frame later. Adding it in the same tick
            // would transition nothing and the tip would simply appear.
            requestAnimationFrame(function () {
                requestAnimationFrame(function () { tip.classList.add('on'); });
            });
        }

        function hideTip() {
            tip.classList.remove('on');
            tip.hidden = true;
        }

        // Delegated on the panel, so rows rebuilt by every renderPanel() need
        // no re-binding. pointerover/out fire for the child spans too, so the
        // handler resolves the owning row and ignores moves within it -
        // otherwise crossing from the tag to the count would flicker the tip.
        panel.addEventListener('pointerover', function (e) {
            var row = e.target.closest('.st-lb-row');
            if (row && row.getAttribute('data-tip')) showTip(row);
        });
        panel.addEventListener('pointerout', function (e) {
            var row = e.target.closest('.st-lb-row');
            var to = e.relatedTarget;
            if (row && (!to || !row.contains(to))) hideTip();
        });

        // Keyboard has no hover, so the tooltip follows the active row too.
        // Otherwise the full title would be unreachable without a mouse.
        function setActive(i) {
            if (!rows.length) { active = -1; return; }
            active = (i + rows.length) % rows.length;
            for (var k = 0; k < rows.length; k++) {
                rows[k].classList.toggle('is-active', k === active);
            }
            if (rows[active]) {
                rows[active].scrollIntoView({ block: 'nearest' });
                if (rows[active].getAttribute('data-tip')) showTip(rows[active]);
                else hideTip();
            }
        }

        // Keep the panel inside the viewport. It is wider than its trigger and
        // the trigger sits near the right edge of the toolbar, so a
        // left-anchored panel runs off-screen and the count column - the one
        // part that cannot be elided - is what gets cut. Flip to the trigger's
        // right edge when there is not enough room on the right.
        function positionPanel() {
            panel.style.left = '';
            panel.style.right = '';
            if (window.innerWidth < 600) return;   // mobile rule handles it
            var gap = parseFloat(getComputedStyle(panel).right) || 0;
            gap = 8;
            var over = panel.getBoundingClientRect().right - (window.innerWidth - gap);
            if (over > 0) panel.style.right = '0px';
        }

        function open() {
            renderPanel();
            panel.hidden = false;
            positionPanel();
            btn.setAttribute('aria-expanded', 'true');
            btn.classList.add('is-open');
            // Start the highlight on the current choice, so Enter re-picks what
            // is already selected instead of jumping to the top of the list.
            for (var i = 0; i < rows.length; i++) {
                if (rows[i].getAttribute('aria-selected') === 'true') { active = i; break; }
            }
            setActive(active);
        }

        function close() {
            panel.hidden = true;
            // The tooltip lives on the body, so it does not hide itself along
            // with the panel. Left up, it would sit over the page describing a
            // row that is no longer on screen.
            hideTip();
            btn.setAttribute('aria-expanded', 'false');
            btn.classList.remove('is-open');
            active = -1;
        }

        function pick(value) {
            if (sel.value === value) { close(); return; }
            sel.value = value;
            lastValue = value;
            // Fires the inline onchange handler, which submits the form - the
            // same path the native select took, so "program changed" stays one
            // code path rather than two.
            try { sel.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {}
            renderTrigger();
            close();
        }

        btn.addEventListener('click', function () {
            if (panel.hidden) open(); else close();
        });

        btn.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (panel.hidden) { open(); return; }
                setActive(active + (e.key === 'ArrowDown' ? 1 : -1));
            } else if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                if (panel.hidden) { open(); return; }
                if (rows[active]) pick(rows[active].getAttribute('data-v'));
            } else if (e.key === 'Home' || e.key === 'End') {
                if (!panel.hidden) {
                    e.preventDefault();
                    setActive(e.key === 'Home' ? 0 : rows.length - 1);
                }
            } else if (e.key === 'Escape') {
                if (!panel.hidden) { e.preventDefault(); close(); btn.focus(); }
            } else if (e.key === 'Tab' && !panel.hidden) {
                // Let focus leave. A listbox that traps Tab is a trap.
                close();
            }
        });

        // mousedown, not click: click lands after the button's blur, which
        // closes the panel and drops the click on nothing.
        panel.addEventListener('mousedown', function (e) {
            var row = e.target.closest('.st-lb-row');
            if (!row) return;
            e.preventDefault();
            pick(row.getAttribute('data-v'));
            btn.focus();
        });

        // Pointer-down outside closes. capture:true so it also beats a pointer
        // down on some other control that would otherwise open first.
        document.addEventListener('pointerdown', function (e) {
            if (panel.hidden) return;
            if (wrap.contains(e.target)) return;
            close();
        }, true);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !panel.hidden) { close(); btn.focus(); }
        });

        renderTrigger();

        // Keep the trigger truthful if anything else writes select.value.
        setInterval(function () {
            if (sel.value !== lastValue) { lastValue = sel.value; renderTrigger(); }
        }, 300);
    }

    function initAll() {
        var sels = document.querySelectorAll('select.st-progsel-in');
        for (var i = 0; i < sels.length; i++) enhance(sels[i]);
    }

    // No argument is passed to the listener: DOMContentLoaded hands the
    // callback an Event, and a listener that forwarded its argument would
    // pass that Event in as a search root. The earlier version took `root`
    // and every call site then failed with "querySelectorAll is not a
    // function" - which is what stopped the control appearing at all.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { initAll(); });
    } else {
        initAll();
    }
})();
