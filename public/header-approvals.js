/*
 * Header "Approvals" entries (tmonier/sylius-purchase-approval-plugin): without JavaScript they stay
 * links to the list. With it, each becomes a button (aria-expanded) that opens a panel fetched at
 * the first click. Escape closes it and gives the focus back to the button.
 */
(function () {
    'use strict';

    function enhance(entry) {
        if (entry.hasAttribute('data-tmonier-preview-ready')) {
            return;
        }
        var link = entry.querySelector('a[href]');
        if (!link) {
            return;
        }
        entry.setAttribute('data-tmonier-preview-ready', '');
        var layout = entry.getAttribute('data-tmonier-preview-layout');
        var panelId = 'tmonier-approvals-panel-' + layout;
        var button = document.createElement('button');
        button.type = 'button';
        button.className = link.className + ' bg-transparent border-0';
        button.style.font = 'inherit';
        button.style.color = 'inherit';
        button.setAttribute('aria-expanded', 'false');
        button.setAttribute('aria-controls', panelId);
        Array.prototype.forEach.call(link.childNodes, function (node) {
            button.appendChild(node.cloneNode(true));
        });
        var panel = document.createElement('div');
        panel.id = panelId;
        panel.hidden = true;
        panel.tabIndex = -1;
        panel.setAttribute('role', 'region');
        panel.setAttribute('aria-label', entry.getAttribute('data-tmonier-preview-label'));
        panel.className = layout === 'desktop'
            ? 'position-absolute end-0 top-100 bg-white border rounded shadow'
            : 'bg-white border-top';
        panel.style.zIndex = '1050';
        if (layout === 'desktop') {
            panel.style.minWidth = '20rem';
            panel.style.maxWidth = '90vw';
        }
        var loaded = false;
        var loading = false;

        function setOpen(open) {
            panel.hidden = !open;
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        function focusPanel() {
            var first = panel.querySelector('a[href]');
            (first || panel).focus();
        }

        function load() {
            if (loaded || loading) {
                return;
            }
            loading = true;
            panel.setAttribute('aria-busy', 'true');
            panel.textContent = entry.getAttribute('data-tmonier-preview-loading');
            panel.classList.add('px-3', 'py-3');
            fetch(entry.getAttribute('data-tmonier-preview-url'), {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('preview');
                    }
                    return response.text();
                })
                .then(function (html) {
                    panel.classList.remove('px-3', 'py-3');
                    panel.innerHTML = html;
                    loaded = true;
                    panel.removeAttribute('aria-busy');
                    if (!panel.hidden) {
                        focusPanel();
                    }
                })
                .catch(function () {
                    // The link of the entry still leads to the list.
                    window.location.assign(link.getAttribute('href'));
                })
                .then(function () {
                    loading = false;
                });
        }

        button.addEventListener('click', function () {
            var open = panel.hidden;
            setOpen(open);
            if (open) {
                load();
            }
        });
        entry.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !panel.hidden) {
                setOpen(false);
                button.focus();
            }
        });
        entry.addEventListener('focusout', function (event) {
            if (event.relatedTarget && !entry.contains(event.relatedTarget)) {
                setOpen(false);
            }
        });
        document.addEventListener('click', function (event) {
            if (!panel.hidden && !entry.contains(event.target)) {
                setOpen(false);
            }
        });

        link.replaceWith(button);
        button.insertAdjacentElement('afterend', panel);
    }

    function init() {
        document.querySelectorAll('[data-tmonier-preview-url]').forEach(enhance);
    }

    // The script is loaded once (deferred): later page visits of Turbo only need a new scan.
    init();
    document.addEventListener('turbo:load', init);
})();
