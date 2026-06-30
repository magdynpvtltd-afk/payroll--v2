/* MagDyn — Combobox enhancement
 *
 * Replaces every <select> on the page with a search-and-select
 * combobox: a text input that filters a dropdown of options. The
 * original <select> stays in the DOM as a hidden form control so
 * server-side form submission works unchanged.
 *
 * Opt-out:
 *   - <select> with class `no-combobox` (page author choice)
 *   - <select> with class `dt-col-filter-select` (data-table filter
 *     pills — already pill-shaped, don't want a second skin)
 *   - <select multiple> — different UX, not supported here
 *   - <select> with < 3 options — search is overkill, keep native
 *
 * Keyboard:
 *   - Focus opens the menu
 *   - Type to filter (case-insensitive substring on labels)
 *   - ArrowUp / ArrowDown move highlight
 *   - Enter picks the highlight
 *   - Escape closes the menu (without changing selection)
 *   - Tab moves on as expected
 *
 * Initialisation:
 *   - Auto on DOMContentLoaded
 *   - Re-runnable via window.MagDynCombobox.initAll() — used by the
 *     SPA shell after a content swap.
 */
(function () {
    'use strict';

    // Sentinel class on the original <select> so we don't double-bind
    // it after SPA re-init.
    var BOUND_CLASS = 'cb-bound';

    // Normalise a string for matching: lower-case and strip everything
    // that isn't a letter or digit. This makes search separator-agnostic
    // so typing "qc hold" matches the option labelled "QC-Hold", and
    // "main store" matches "Main Store (FFStore)". Used for both the live
    // filter and the commit-on-blur/submit resolver.
    function cbNorm(s) {
        return (s || '').toLowerCase().replace(/[^a-z0-9]+/g, '');
    }

    function shouldEnhance(sel) {
        if (sel.multiple) return false;
        if (sel.classList.contains('no-combobox')) return false;
        if (sel.classList.contains('dt-col-filter-select')) return false;
        if (sel.classList.contains(BOUND_CLASS)) return false;
        // Search-and-select for anything with at least one real choice
        // (placeholder + 1 option). Selects with exactly one option
        // are effectively a label, not a choice, and stay native.
        if (sel.options.length < 2) return false;
        // Hidden by display:none / not connected? skip.
        if (!sel.offsetParent && sel.type !== 'select-one') return false;
        return true;
    }

    function build(sel) {
        sel.classList.add(BOUND_CLASS);

        // Container
        var wrap = document.createElement('div');
        wrap.className = 'cb-wrap';
        sel.parentNode.insertBefore(wrap, sel);
        wrap.appendChild(sel);
        sel.classList.add('cb-native');

        // The text input that the user types into
        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'cb-input';
        input.autocomplete = 'off';
        input.spellcheck = false;
        if (sel.disabled) input.disabled = true;
        if (sel.required) input.required = true;
        // Move the id from the <select> to the input so that any
        // <label for="..."> still focuses the user-visible control.
        // The select keeps its name (form submission) but loses its id.
        if (sel.id) {
            input.id = sel.id;
            sel.removeAttribute('id');
        }
        // aria-label / aria-labelledby fallback
        if (sel.getAttribute('aria-label')) {
            input.setAttribute('aria-label', sel.getAttribute('aria-label'));
        }
        if (sel.getAttribute('aria-labelledby')) {
            input.setAttribute('aria-labelledby', sel.getAttribute('aria-labelledby'));
        }
        // Preserve tabindex
        var ti = sel.getAttribute('tabindex');
        if (ti !== null) {
            input.setAttribute('tabindex', ti);
            sel.removeAttribute('tabindex');
        }
        wrap.appendChild(input);

        // Chevron
        var chev = document.createElement('span');
        chev.className = 'cb-chev';
        chev.setAttribute('aria-hidden', 'true');
        chev.textContent = '▾';
        wrap.appendChild(chev);

        // Menu panel
        var menu = document.createElement('div');
        menu.className = 'cb-menu';
        menu.setAttribute('role', 'listbox');
        menu.hidden = true;
        wrap.appendChild(menu);

        // Build option items from the native <select>'s options.
        //
        // Placeholder convention: a leading <option value=""> (with
        // empty value) is treated as the input's PLACEHOLDER text —
        // it doesn't appear in the dropdown list as a pickable row.
        // This matches how a native select shows it (greyed-out
        // default) without forcing the user to scroll past "— pick
        // an X —" when they're searching.
        //
        // The placeholder option stays in the <select> DOM so form
        // submission with no value still posts the empty string and
        // required-validation still works.
        var items = [];
        var placeholderText = '';
        for (var i = 0; i < sel.options.length; i++) {
            var opt = sel.options[i];
            // Trim whitespace — <option> elements rendered via PHP often
            // include leading/trailing newlines + indentation in their
            // textContent (because the option tags span multiple lines
            // in the source HTML). Without trimming, the combobox input's
            // value contains that whitespace, which looks like the value
            // is mysteriously indented or centered.
            var optText = (opt.textContent || '').replace(/\s+/g, ' ').trim();
            if (opt.value === '' && i === 0 && optText) {
                // Treat the leading empty-value option as a placeholder
                // hint, not a list row.
                placeholderText = optText;
                continue;
            }
            items.push({
                value: opt.value,
                label: optText,
                disabled: opt.disabled,
                element: null
            });
        }
        if (placeholderText) {
            input.placeholder = placeholderText;
        }

        function renderMenu(query) {
            menu.innerHTML = '';
            var q = cbNorm(query);
            var any = false;
            items.forEach(function (it, idx) {
                if (q && cbNorm(it.label).indexOf(q) === -1) {
                    it.element = null;
                    return;
                }
                var row = document.createElement('div');
                row.className = 'cb-item';
                row.setAttribute('role', 'option');
                row.setAttribute('data-cb-idx', idx);
                row.textContent = it.label;
                if (it.disabled) row.classList.add('cb-disabled');
                if (sel.value === it.value) row.classList.add('cb-selected');
                it.element = row;
                menu.appendChild(row);
                any = true;
            });
            if (!any) {
                var empty = document.createElement('div');
                empty.className = 'cb-empty';
                empty.textContent = 'No matches';
                menu.appendChild(empty);
            }
        }

        function setValue(val) {
            sel.value = val;
            var found = items.find(function (it) { return it.value === val; });
            input.value = found ? found.label : '';
            // Fire change so any listeners on the <select> see the update
            sel.dispatchEvent(new Event('change', { bubbles: true }));
        }

        // Resolve the user's typed text to an option value when it is
        // unambiguous, and commit it to the underlying <select>. This is
        // what makes "type the name, then click Save" work — without it,
        // a typed-but-not-clicked entry leaves the <select> empty and the
        // form posts no value (e.g. "Pick a destination location.").
        //
        // Resolution order:
        //   1. already in sync (input matches current selection) → no-op
        //   2. exact normalised label match → commit it
        //   3. exactly one option whose label contains the typed text → commit
        //   4. ambiguous (0 or >1 matches) → leave as-is; user must pick
        function commitTyped() {
            var cur = items.find(function (it) { return it.value === sel.value; });
            if (cur && input.value === cur.label) return;     // (1)
            var typed = input.value.trim();
            if (!typed) return;
            var norm = cbNorm(typed);
            if (!norm) return;
            var exact = items.filter(function (it) {
                return !it.disabled && cbNorm(it.label) === norm;
            });
            if (exact.length === 1) { setValue(exact[0].value); return; }   // (2)
            var partial = items.filter(function (it) {
                return !it.disabled && cbNorm(it.label).indexOf(norm) !== -1;
            });
            if (partial.length === 1) { setValue(partial[0].value); }       // (3)
        }

        // Initial value
        var initial = items.find(function (it) { return it.value === sel.value; });
        input.value = initial ? initial.label : '';

        var hiIdx = -1;       // highlighted item index within currently visible rows
        function moveHighlight(dir) {
            var rows = menu.querySelectorAll('.cb-item:not(.cb-disabled)');
            if (!rows.length) return;
            hiIdx = (hiIdx + dir + rows.length) % rows.length;
            rows.forEach(function (r, i) {
                r.classList.toggle('cb-highlight', i === hiIdx);
            });
            var hi = rows[hiIdx];
            if (hi && hi.scrollIntoView) hi.scrollIntoView({ block: 'nearest' });
        }

        function positionMenu() {
            // Compute viewport-relative coords for the menu. Because
            // .cb-menu is position:fixed, top/left are relative to
            // viewport, NOT the wrap — so no ancestor overflow can
            // clip the dropdown. Width tracks the wrap's width so
            // the menu visually anchors to the input.
            //
            // Flip-up: if there isn't enough room below, render the
            // menu above the wrap instead. 8px breathing room.
            var rect = wrap.getBoundingClientRect();
            var spaceBelow = window.innerHeight - rect.bottom;
            var spaceAbove = rect.top;
            var menuMax    = 240;  // matches max-height in CSS
            var openUp     = spaceBelow < 120 && spaceAbove > spaceBelow;
            menu.style.left  = rect.left + 'px';
            menu.style.width = rect.width + 'px';
            if (openUp) {
                // Use max-height capped to available space - 8px gap
                var maxUp = Math.max(80, spaceAbove - 8);
                menu.style.top    = '';
                menu.style.bottom = (window.innerHeight - rect.top + 2) + 'px';
                menu.style.maxHeight = Math.min(menuMax, maxUp) + 'px';
            } else {
                var maxDown = Math.max(80, spaceBelow - 8);
                menu.style.bottom = '';
                menu.style.top    = (rect.bottom + 2) + 'px';
                menu.style.maxHeight = Math.min(menuMax, maxDown) + 'px';
            }
        }

        // Track scrolls + resizes while the menu is open and reposition
        // so the dropdown follows the input. The listeners are added on
        // open and removed on close so we don't keep working when the
        // menu is hidden.
        function onScrollOrResize() { if (isOpen()) positionMenu(); }

        function open() {
            renderMenu(''); // show full list on open
            menu.hidden = false;
            wrap.classList.add('cb-open');
            hiIdx = -1;
            positionMenu();
            window.addEventListener('scroll', onScrollOrResize, true);
            window.addEventListener('resize', onScrollOrResize);
        }
        function close() {
            menu.hidden = true;
            wrap.classList.remove('cb-open');
            window.removeEventListener('scroll', onScrollOrResize, true);
            window.removeEventListener('resize', onScrollOrResize);
            // Commit an unambiguous typed entry the user never explicitly
            // clicked, then restore the label of the current value (user may
            // have typed garbage or an ambiguous fragment).
            commitTyped();
            var found = items.find(function (it) { return it.value === sel.value; });
            input.value = found ? found.label : '';
        }
        function isOpen() { return !menu.hidden; }

        input.addEventListener('focus', open);
        input.addEventListener('click', function () { if (!isOpen()) open(); });
        // Defer the blur close so menu clicks still register
        input.addEventListener('blur', function () {
            setTimeout(function () {
                if (!wrap.contains(document.activeElement)) close();
            }, 100);
        });
        input.addEventListener('input', function () {
            renderMenu(input.value);
            hiIdx = -1;
            if (!isOpen()) {
                menu.hidden = false;
                wrap.classList.add('cb-open');
            }
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') {
                if (!isOpen()) open();
                else moveHighlight(1);
                e.preventDefault();
            } else if (e.key === 'ArrowUp') {
                if (!isOpen()) open();
                else moveHighlight(-1);
                e.preventDefault();
            } else if (e.key === 'Enter') {
                if (isOpen()) {
                    var rows = menu.querySelectorAll('.cb-item:not(.cb-disabled)');
                    var pick = rows[hiIdx] || rows[0];
                    if (pick) {
                        var idx = parseInt(pick.getAttribute('data-cb-idx'), 10);
                        setValue(items[idx].value);
                        close();
                    }
                    e.preventDefault();
                }
            } else if (e.key === 'Escape') {
                if (isOpen()) { close(); e.preventDefault(); }
            } else if (e.key === 'Tab') {
                close();
                // let Tab move on naturally
            }
        });

        // Click on a menu row
        menu.addEventListener('mousedown', function (e) {
            // mousedown not click — click would fire after blur which
            // would have already closed the menu.
            var row = e.target.closest && e.target.closest('.cb-item');
            if (!row || row.classList.contains('cb-disabled')) return;
            var idx = parseInt(row.getAttribute('data-cb-idx'), 10);
            setValue(items[idx].value);
            close();
            // Move focus back to input so subsequent Tab works
            input.focus();
        });

        chev.addEventListener('mousedown', function (e) {
            e.preventDefault();
            if (isOpen()) close();
            else { input.focus(); open(); }
        });

        // Re-sync the visible input to match the underlying select's current
        // value. Called when something OTHER than the user typing changes
        // the value (programmatic setRowField, rebuild of options, etc).
        // We expose this as a method on the wrap element so callers can
        // invoke it after twiddling the underlying select directly.
        wrap._cbResync = function () {
            // Rebuild items array in case the select's options have changed
            // (e.g. source-location dropdown got rebuilt with stock-filtered options).
            var newItems = [];
            for (var i = 0; i < sel.options.length; i++) {
                var opt = sel.options[i];
                var optText = (opt.textContent || '').replace(/\s+/g, ' ').trim();
                if (opt.value === '' && i === 0 && optText) {
                    input.placeholder = optText;
                    continue;
                }
                newItems.push({
                    value: opt.value,
                    label: optText,
                    disabled: opt.disabled,
                    element: null
                });
            }
            items.length = 0;
            Array.prototype.push.apply(items, newItems);
            // Now update visible input value to match current select value
            var found = items.find(function (it) { return it.value === sel.value; });
            input.value = found ? found.label : '';
        };

        // Auto-resync on the standard `change` event. Programmatic value
        // changes done via `el.value = X` don't fire change automatically,
        // but if the caller does `sel.dispatchEvent(new Event('change'))`,
        // this listener will catch it. setRowField in inventory_shiprcpt.php
        // doesn't fire change, so callers that programmatically set a
        // combobox-enhanced select's value should ALSO call
        // window.MagDynCombobox.resync(sel) (see API below).
        sel.addEventListener('change', function () {
            wrap._cbResync();
        });

        // Commit a typed-but-unclicked entry when the owning form submits.
        // Clicking a Save button submits immediately — before the input's
        // deferred blur/close fires — so without this the <select> would
        // still be empty at POST time. Capture phase runs before the
        // browser gathers form values. No-op when already in sync.
        if (sel.form) {
            sel.form.addEventListener('submit', commitTyped, true);
        }
    }

    function initAll(root) {
        root = root || document;
        root.querySelectorAll('select').forEach(function (sel) {
            if (shouldEnhance(sel)) build(sel);
        });
    }

    // resync(elementOrSelector) — force the combobox visible input to
    // re-read the underlying select's value + options. Accepts a select
    // element, a cb-wrap element, or anything containing them. No-op for
    // non-combobox elements.
    function resync(target) {
        if (!target) return;
        // If target is a select, find its wrap
        var wrap = null;
        if (target.classList && target.classList.contains('cb-wrap')) {
            wrap = target;
        } else if (target.tagName === 'SELECT') {
            wrap = target.closest('.cb-wrap');
        }
        if (wrap && typeof wrap._cbResync === 'function') {
            wrap._cbResync();
            return;
        }
        // Container with multiple — resync all inside
        if (target.querySelectorAll) {
            target.querySelectorAll('.cb-wrap').forEach(function (w) {
                if (typeof w._cbResync === 'function') w._cbResync();
            });
        }
    }

    window.MagDynCombobox = window.MagDynCombobox || {};
    window.MagDynCombobox.initAll = initAll;
    window.MagDynCombobox.resync = resync;

    if (document.readyState !== 'loading') initAll();
    else document.addEventListener('DOMContentLoaded', function () { initAll(); });
})();
