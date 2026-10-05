/*
 * The public manuals' script (templates/client), no dependency:
 *
 *  - the search palette: Ctrl K / Cmd K or "/" opens it, it asks the server
 *    as one types (the manual being read first, every manual on demand),
 *    arrows and Enter move and open, Escape closes. Without this script the
 *    search field is a plain form that goes to the results page;
 *  - a "copy" button on each code block;
 *  - the heading the reader is at, marked in "on this page";
 *  - the contents folded behind a button on a narrow screen.
 *
 * A page may come in whole or be swapped in by transparent.js: everything is
 * delegated from the document, and what belongs to one page is set up again
 * on "transparent:load". Loading this file twice does nothing.
 */
(function () {
    'use strict';
    if (window.WikidocManual) { window.WikidocManual.setup(); return; }

    var palette = null, input, results, engineLine, scopeBar, field = null;
    var hits = [], active = 0, timer = null, request = 0, everywhere = false;

    function escapeHtml(text) {
        return String(text == null ? '' : text).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    function marked(text, terms) {
        var out = escapeHtml(text);
        terms.forEach(function (term) {
            if (!term) return;
            var safe = escapeHtml(term).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            out = out.replace(new RegExp('(' + safe + ')', 'ig'), '<mark>$1</mark>');
        });
        return out;
    }

    function build() {
        palette = document.createElement('div');
        palette.className = 'manual-palette';
        palette.hidden = true;
        palette.innerHTML =
            '<div class="manual-palette-backdrop" data-manual-palette-close></div>' +
            '<div class="manual-palette-panel" role="dialog" aria-modal="true">' +
                '<div class="manual-palette-field"><input type="search" autocomplete="off" spellcheck="false"><kbd>esc</kbd></div>' +
                '<div class="manual-palette-scope" hidden></div>' +
                '<div class="manual-palette-results" role="listbox"></div>' +
                '<div class="manual-palette-foot"><span><kbd>↑</kbd> <kbd>↓</kbd></span><span><kbd>↵</kbd></span><span class="manual-palette-engine"></span></div>' +
            '</div>';
        document.body.appendChild(palette);
        input = palette.querySelector('input');
        results = palette.querySelector('.manual-palette-results');
        engineLine = palette.querySelector('.manual-palette-engine');
        scopeBar = palette.querySelector('.manual-palette-scope');

        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(search, 140);
        });
        input.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') { event.preventDefault(); move(1); }
            else if (event.key === 'ArrowUp') { event.preventDefault(); move(-1); }
            else if (event.key === 'Enter') {
                event.preventDefault();
                var hit = results.querySelectorAll('.manual-hit')[active];
                if (hit) { close(); hit.click(); }
                else if (field && input.value.trim()) { location.href = url(false); }
            }
        });
    }

    function url(json) {
        var params = new URLSearchParams();
        params.set('q', input.value.trim());
        if (!everywhere && field.dataset.manual) {
            params.set('manual', field.dataset.manual);
            params.set('version', field.dataset.version || '');
        }
        if (json) params.set('format', 'json');
        return field.dataset.manualSearch + '?' + params.toString();
    }

    function scope() {
        if (!field.dataset.manual) { scopeBar.hidden = true; return; }
        scopeBar.hidden = false;
        scopeBar.innerHTML =
            '<button type="button" data-manual-scope="here" aria-pressed="' + (!everywhere) + '">' + escapeHtml(field.dataset.label) + ' ' + escapeHtml(field.dataset.version) + '</button>' +
            '<button type="button" data-manual-scope="all" aria-pressed="' + everywhere + '">' + escapeHtml(field.dataset.textEverywhere) + '</button>';
    }

    function search() {
        var query = input.value.trim();
        if (!query) {
            hits = [];
            results.innerHTML = '<p class="manual-palette-empty">' + escapeHtml(field.dataset.textHint) + '</p>';
            engineLine.textContent = '';
            return;
        }
        var mine = ++request;
        fetch(url(true), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (response) { return response.ok ? response.json() : { hits: [], engine: '' }; })
            .then(function (data) { if (mine === request) render(data, query); })
            .catch(function () { if (mine === request) render({ hits: [], engine: '' }, query); });
    }

    function render(data, query) {
        var terms = query.toLowerCase().split(/\s+/).filter(Boolean);
        hits = data.hits || [];
        active = 0;
        engineLine.textContent = data.engine === 'typesense' ? field.dataset.textTypesense : (data.engine === 'local' ? field.dataset.textLocal : '');
        if (!hits.length) {
            results.innerHTML = '<p class="manual-palette-empty">' + escapeHtml(field.dataset.textNone.replace('%q%', query)) + '</p>';
            return;
        }
        results.innerHTML = hits.map(function (hit, i) {
            return '<a class="manual-hit' + (i === 0 ? ' is-active' : '') + '" role="option" href="' + escapeHtml(hit.url) + '">' +
                '<span class="manual-hit-where">' + escapeHtml(hit.label) + ' <i>' + escapeHtml(hit.version) + '</i></span>' +
                '<span class="manual-hit-title">' + marked(hit.section || hit.title, terms) + '</span>' +
                (hit.section ? '<span class="manual-hit-page">' + escapeHtml(hit.title) + '</span>' : '') +
                (hit.snippet ? '<span class="manual-hit-text">' + marked(hit.snippet, terms) + '</span>' : '') +
                '</a>';
        }).join('');
    }

    function move(delta) {
        var links = results.querySelectorAll('.manual-hit');
        if (!links.length) return;
        if (links[active]) links[active].classList.remove('is-active');
        active = (active + delta + links.length) % links.length;
        links[active].classList.add('is-active');
        links[active].scrollIntoView({ block: 'nearest' });
    }

    function open(from) {
        field = from || document.querySelector('[data-manual-search]');
        if (!field) return;
        if (!palette || !palette.isConnected) build();
        everywhere = false;
        palette.querySelector('.manual-palette-panel').setAttribute('aria-label', field.querySelector('label') ? field.querySelector('label').textContent : 'Search');
        input.placeholder = field.querySelector('input[type="search"]').placeholder;
        input.value = field.querySelector('input[type="search"]').value;
        scope();
        palette.hidden = false;
        input.focus();
        input.select();
        search();
    }

    function close() {
        if (palette) palette.hidden = true;
    }

    // ---- per page: copy buttons, the current heading -----------------------
    var spy = null;

    function setup() {
        document.querySelectorAll('.manual-prose .doc-code').forEach(function (block) {
            if (block.querySelector('.doc-code-copy')) return;
            var prose = block.closest('.manual-prose');
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'doc-code-copy';
            button.textContent = prose.dataset.textCopy || 'Copy';
            block.appendChild(button);
        });

        if (spy) { spy.disconnect(); spy = null; }
        var links = {};
        document.querySelectorAll('.manual-toc a[href^="#"]').forEach(function (a) { links[decodeURIComponent(a.getAttribute('href').slice(1))] = a; });
        var ids = Object.keys(links);
        if (!ids.length || !('IntersectionObserver' in window)) return;
        var visible = {};
        spy = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) { visible[entry.target.dataset.spy] = entry.isIntersecting; });
            var current = ids.filter(function (id) { return visible[id]; })[0];
            if (!current) return;
            ids.forEach(function (id) { links[id].classList.toggle('is-current', id === current); });
        }, { rootMargin: '-10% 0px -70% 0px' });
        ids.forEach(function (id) {
            var anchor = document.getElementById(id);
            var heading = anchor && (anchor.closest('h2, h3, h4') || anchor);
            if (heading) { heading.dataset.spy = id; spy.observe(heading); }
        });
    }

    // ---- delegated ----------------------------------------------------------
    document.addEventListener('keydown', function (event) {
        var typing = /^(INPUT|TEXTAREA|SELECT)$/.test(event.target.tagName) || event.target.isContentEditable;
        if ((event.key === 'k' || event.key === 'K') && (event.metaKey || event.ctrlKey)) {
            if (!document.querySelector('[data-manual-search]')) return;
            event.preventDefault();
            palette && !palette.hidden ? close() : open();
        } else if (event.key === '/' && !typing && document.querySelector('[data-manual-search]')) {
            event.preventDefault();
            open();
        } else if (event.key === 'Escape' && palette && !palette.hidden) {
            close();
        }
    });

    document.addEventListener('focusin', function (event) {
        // The field in the page is the palette's door: typing happens in the palette.
        var form = event.target.closest && event.target.closest('[data-manual-search]');
        if (form && event.target.type === 'search' && (!palette || palette.hidden) && !form.dataset.opening) {
            form.dataset.opening = '1';
            event.target.blur();
            open(form);
            setTimeout(function () { delete form.dataset.opening; }, 300);
        }
    });

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (target.closest('[data-manual-palette-close]')) { close(); return; }
        if (target.closest('.manual-hit')) { close(); return; }

        var scopeButton = target.closest('[data-manual-scope]');
        if (scopeButton) {
            everywhere = scopeButton.dataset.manualScope === 'all';
            scope();
            input.focus();
            search();
            return;
        }

        var copy = target.closest('.doc-code-copy');
        if (copy) {
            var code = copy.parentNode.querySelector('pre');
            var prose = copy.closest('.manual-prose');
            var done = function () {
                copy.textContent = prose.dataset.textCopied || 'Copied';
                setTimeout(function () { copy.textContent = prose.dataset.textCopy || 'Copy'; }, 1600);
            };
            if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(code.textContent).then(done, function () {});
            return;
        }

        var menu = target.closest('[data-manual-menu]');
        if (menu) {
            var manual = menu.closest('.manual');
            var opened = manual.classList.toggle('is-menu-open');
            menu.setAttribute('aria-expanded', opened ? 'true' : 'false');
            return;
        }

        // A version menu closes when one clicks elsewhere.
        document.querySelectorAll('.manual-versions[open]').forEach(function (details) {
            if (!details.contains(target)) details.removeAttribute('open');
        });
    });

    window.WikidocManual = { setup: setup, open: open, close: close };
    window.addEventListener('transparent:load', function () { close(); setup(); });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setup);
    else setup();
})();
