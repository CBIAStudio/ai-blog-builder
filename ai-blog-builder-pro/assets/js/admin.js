(function () {
    function addButton() {
        if (!window.ABB || !ABB.addPostButton || !ABB.addPostButton.enabled) return;
        var target = document.querySelector('.wrap .page-title-action');
        if (!target || document.querySelector('.cbia-add-ai')) return;

        var a = document.createElement('a');
        a.className = 'page-title-action cbia-add-ai';
        a.href = ABB.addPostButton.url;
        a.textContent = ABB.addPostButton.label || 'Anadir entrada con IA';
        target.insertAdjacentElement('afterend', a);
    }

    function initPromptEditor() {
        var modal = document.getElementById('cbia-prompt-modal');
        if (!modal || !window.ABB) return;
        // CAMBIO: sacar el modal del flujo de la tabla para que no parezca ligado a "destacada".
        if (modal.parentNode !== document.body) {
            document.body.appendChild(modal);
        }

        var textarea = document.getElementById('cbia-prompt-text');
        var status = document.getElementById('cbia-prompt-status');
        var title = document.getElementById('cbia-prompt-title');
        var btnSave = document.getElementById('cbia-prompt-save');
        var btnClose = modal.querySelector('.cbia-modal-close');

        var current = { postId: 0, type: '', idx: 0 };

        function setStatus(msg, isOk) {
            if (!status) return;
            status.textContent = msg || '';
            status.className = 'cbia-modal-status' + (isOk ? ' is-ok' : ' is-error');
        }

        function openModal(type, idx) {
            current.postId = 0;
            current.type = type;
            current.idx = parseInt(idx || '0', 10) || 0;
            if (title) {
                var label = type === 'featured' ? 'Destacada' : ('Interna ' + current.idx);
                title.textContent = 'Editar prompt base - ' + label;
            }
            if (textarea) textarea.value = '';
            setStatus('Cargando prompt...', true);
            modal.style.display = 'flex';

            var params = new URLSearchParams();
            params.append('action', 'cbia_get_img_prompt');
            params.append('_ajax_nonce', ABB.nonce);
            params.append('post_id', 0);
            params.append('type', type);
            params.append('idx', idx);

            fetch(ABB.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: params.toString()
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.success && data.data) {
                    if (textarea) textarea.value = data.data.prompt || '';
                    setStatus('Prompt base cargado.', true);
                } else {
                    setStatus('No se pudo cargar el prompt.', false);
                }
            })
            .catch(function () { setStatus('Error de red al cargar el prompt.', false); });
        }

        function savePrompt() {
            var prompt = textarea ? textarea.value : '';
            if (!current.type) return;

            setStatus('Guardando...', true);

            var params = new URLSearchParams();
            params.append('action', 'cbia_save_img_prompt_override');
            params.append('_ajax_nonce', ABB.nonce);
            params.append('post_id', 0);
            params.append('type', current.type);
            params.append('idx', current.idx);
            params.append('prompt', prompt);

            fetch(ABB.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: params.toString()
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    setStatus((data && data.data && data.data.message) ? data.data.message : 'No se pudo guardar el prompt.', false);
                    return;
                }
                setStatus('Prompt base guardado.', true);
            })
            .catch(function () { setStatus('Error de red al guardar.', false); });
        }

        document.querySelectorAll('.cbia-prompt-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openModal(btn.getAttribute('data-type'), btn.getAttribute('data-idx'));
            });
        });

        if (btnSave) btnSave.addEventListener('click', function () { savePrompt(); });
        if (btnClose) btnClose.addEventListener('click', function () { modal.style.display = 'none'; });

        modal.addEventListener('click', function (e) {
            if (e.target === modal) modal.style.display = 'none';
        });
    }

    // CAMBIO: soportar selectores de proveedor por scope (texto/imagen)
    function initProviderSelects() {
        var wrappers = document.querySelectorAll('.abb-provider-select');
        if (!wrappers.length) return;

        function getProvider(scope) {
            var select = document.querySelector('.abb-provider-select-input[data-scope="' + scope + '"]');
            return select ? (select.value || 'openai') : 'openai';
        }

        // CAMBIO: Google Imagen (Vertex AI) extra fields
        function updateGoogleImageExtras() {
            var imageProvider = getProvider('image');
            document.querySelectorAll('.abb-google-imagen-fields[data-scope="image"]').forEach(function (el) {
                el.style.display = (imageProvider === 'google') ? '' : 'none';
            });
            if (imageProvider !== 'google') return;
            var modelSelect = document.querySelector('.abb-provider-model[data-scope="image"][data-provider="google"] select');
            var model = modelSelect ? modelSelect.value : '';
            var isImagen = model === 'imagen-2';
            document.querySelectorAll('.abb-google-imagen-note-imagen').forEach(function (el) {
                el.style.display = isImagen ? '' : 'none';
            });
            document.querySelectorAll('.abb-google-imagen-note-gemini').forEach(function (el) {
                el.style.display = isImagen ? 'none' : '';
            });
        }

        function updateScope(scope) {
            var select = document.querySelector('.abb-provider-select-input[data-scope="' + scope + '"]');
            if (!select) return;
            var provider = select.value || 'openai';
            document.querySelectorAll('.abb-provider-model[data-scope="' + scope + '"]').forEach(function (el) {
                var p = el.getAttribute('data-provider') || 'openai';
                el.style.display = (p === provider) ? '' : 'none';
            });
        }

        function updateKeys() {
            var textProvider = getProvider('text');
            var imageProvider = getProvider('image');
            document.querySelectorAll('.abb-provider-key[data-scope="text"]').forEach(function (el) {
                var p = el.getAttribute('data-provider') || 'openai';
                el.style.display = (p === textProvider) ? '' : 'none';
            });
            document.querySelectorAll('.abb-provider-key[data-scope="image"]').forEach(function (el) {
                var p = el.getAttribute('data-provider') || 'openai';
                var show = (p === imageProvider) && (imageProvider !== textProvider);
                el.style.display = show ? '' : 'none';
            });
            updateGoogleImageExtras();
        }

        wrappers.forEach(function (wrapper) {
            var scope = wrapper.getAttribute('data-scope') || 'text';
            var select = wrapper.querySelector('.abb-provider-select-input');
            var logo = wrapper.querySelector('.abb-provider-logo');
            var label = wrapper.querySelector('.abb-provider-label');
            var trigger = wrapper.querySelector('.abb-provider-trigger');
            var menu = wrapper.querySelector('.abb-provider-menu');
            var options = wrapper.querySelectorAll('.abb-provider-option');

            function update() {
                var opt = select.options[select.selectedIndex];
                var optLogo = opt ? opt.getAttribute('data-logo') : '';
                if (logo && optLogo) {
                    logo.src = optLogo;
                }
                if (label && opt) {
                    label.textContent = opt.textContent;
                }
                options.forEach(function (btn) {
                    var val = btn.getAttribute('data-value');
                    btn.classList.toggle('is-active', val === select.value);
                });
                updateScope(scope);
                updateKeys();
            }

            function closeMenu() {
                if (menu) menu.classList.remove('is-open');
                if (trigger) trigger.setAttribute('aria-expanded', 'false');
            }

            if (trigger && menu) {
                trigger.addEventListener('click', function () {
                    var isOpen = menu.classList.contains('is-open');
                    if (isOpen) {
                        closeMenu();
                    } else {
                        menu.classList.add('is-open');
                        trigger.setAttribute('aria-expanded', 'true');
                    }
                });
            }

            options.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var val = btn.getAttribute('data-value') || 'openai';
                    select.value = val;
                    update();
                    closeMenu();
                });
            });

            document.addEventListener('click', function (e) {
                if (!menu || !trigger) return;
                if (menu.contains(e.target) || trigger.contains(e.target)) return;
                closeMenu();
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') closeMenu();
            });

            select.addEventListener('change', update);
            update();
        });

        document.querySelectorAll('.abb-provider-model[data-scope="image"] select').forEach(function (sel) {
            sel.addEventListener('change', updateGoogleImageExtras);
        });
        updateGoogleImageExtras();
    }

    function initAbbSelects() {
        var selects = document.querySelectorAll('select.abb-select');
        if (!selects.length) return;

        function closeAll() {
            document.querySelectorAll('.abb-select-menu.is-open').forEach(function (menu) {
                menu.classList.remove('is-open');
            });
            document.querySelectorAll('.abb-select-trigger[aria-expanded="true"]').forEach(function (btn) {
                btn.setAttribute('aria-expanded', 'false');
            });
        }

        selects.forEach(function (select) {
            if (select.closest('.abb-select-wrap')) return;

            var wrapper = document.createElement('div');
            wrapper.className = 'abb-select-wrap';
            if (select.style && select.style.width) {
                wrapper.style.width = select.style.width;
            }

            var trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.className = 'abb-select-trigger';
            trigger.setAttribute('aria-expanded', 'false');

            var label = document.createElement('span');
            label.className = 'abb-select-label';

            var caret = document.createElement('span');
            caret.className = 'abb-select-caret';
            caret.textContent = '▼';

            trigger.appendChild(label);
            trigger.appendChild(caret);

            var menu = document.createElement('div');
            menu.className = 'abb-select-menu';

            function rebuildOptions() {
                menu.innerHTML = '';
                Array.prototype.slice.call(select.options).forEach(function (opt) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'abb-select-option';
                    btn.setAttribute('data-value', opt.value);
                    btn.textContent = opt.textContent;
                    btn.addEventListener('click', function () {
                        select.value = opt.value;
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                        update();
                        closeAll();
                    });
                    menu.appendChild(btn);
                });
            }

            function update() {
                var opt = select.options[select.selectedIndex];
                if (opt) {
                    label.textContent = opt.textContent;
                }
                Array.prototype.slice.call(menu.querySelectorAll('.abb-select-option')).forEach(function (btn) {
                    btn.classList.toggle('is-active', btn.getAttribute('data-value') === select.value);
                });
                var isDisabled = !!select.disabled;
                wrapper.classList.toggle('is-disabled', isDisabled);
                trigger.disabled = isDisabled;
            }

            rebuildOptions();
            update();

            trigger.addEventListener('click', function (e) {
                e.preventDefault();
                if (trigger.disabled) return;
                var isOpen = menu.classList.contains('is-open');
                closeAll();
                if (!isOpen) {
                    menu.classList.add('is-open');
                    trigger.setAttribute('aria-expanded', 'true');
                }
            });

            select.addEventListener('change', update);

            select.parentNode.insertBefore(wrapper, select);
            wrapper.appendChild(trigger);
            wrapper.appendChild(menu);
            wrapper.appendChild(select);
        });

        document.addEventListener('click', function (e) {
            if (e.target.closest('.abb-select-wrap')) return;
            closeAll();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeAll();
        });
    }

    function initUsageModelSync() {
        var btn = document.getElementById('cbia-sync-models-btn');
        if (!btn || !window.ABB) return;

        btn.addEventListener('click', function () {
            var provider = btn.getAttribute('data-provider') || '';
            btn.disabled = true;
            var oldText = btn.textContent;
            btn.textContent = 'Sincronizando...';

            var params = new URLSearchParams();
            params.append('action', 'cbia_sync_models');
            params.append('_ajax_nonce', ABB.nonce);
            params.append('provider', provider);

            fetch(ABB.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: params.toString()
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.success) {
                    btn.textContent = 'Sync OK (' + (data.data.count || 0) + ')';
                    var status = document.getElementById('cbia-sync-models-status');
                    if (status && data.data.meta && data.data.meta.ts) {
                        status.textContent = 'Ultima sync: ' + data.data.meta.ts;
                    }
                } else {
                    btn.textContent = 'Sync fallo';
                    var status = document.getElementById('cbia-sync-models-status');
                    if (status && data && data.data && data.data.result && data.data.result.error) {
                        status.textContent = 'Ultima sync: error (' + data.data.result.error + ')';
                    }
                }
                setTimeout(function () {
                    btn.disabled = false;
                    btn.textContent = oldText;
                }, 2000);
            })
            .catch(function () {
                btn.disabled = false;
                btn.textContent = oldText;
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            addButton();
            initProviderSelects();
            initPromptEditor();
            initAbbSelects();
            initUsageModelSync();
        });
    } else {
        addButton();
        initProviderSelects();
        initPromptEditor();
        initAbbSelects();
        initUsageModelSync();
    }
})();


