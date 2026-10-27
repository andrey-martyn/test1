/* Mobile lists: keep the shop's original option fields, prices and cart handler. */
(function ($) {
    'use strict';
    var sequence = 0;
    var states = new WeakMap();
    var mobile = window.matchMedia('(max-width: 519px)');
    var packPattern = /^\s*(\d+(?:[.,]\d+)?)\s*(кг|kg|гр\.?|г|g|мл|ml|л|l|шт\.?|pcs?)\s*$/i;

    function pack(text) {
        var match = text.replace(/\s*\([^)]*\)\s*$/, '').match(packPattern);
        return match ? {number: match[1], unit: match[2], text: match[1] + ' ' + match[2]} : null;
    }

    function selected(state) {
        if (!state.group) return null;
        var field = state.select ? state.select.options[state.select.selectedIndex] : state.group.querySelector('input[type="radio"]:checked');
        return field ? state.values.get(field) : null;
    }

    function update(state) {
        var choice = selected(state);
        var quantity = Number($(state.card).find('.qty-switch__input').val()) || 1;
        state.caption.textContent = choice ? 'за ' + (quantity > 1 ? quantity + ' × ' : '') + choice.text : '';
        state.caption.hidden = !choice;
        if (state.select) {
            state.group.querySelectorAll('.lavka-select-weight').forEach(function (button) {
                button.setAttribute('aria-pressed', String(button.dataset.value === state.select.value));
            });
        }
    }

    function setup(card) {
        if (states.has(card) || card.closest('.modal, .product-block')) return;
        var image = card.querySelector('.product-thumb__image');
        var price = card.querySelector('.product-thumb__price');
        var buy = card.querySelector('.product-thumb__add-to-cart');
        if (!image || !price || !buy) return;
        var groups = card.querySelectorAll('.option .option__group');
        // Unknown or multiple options stay visible in the original, full-width card.
        var state = {card: card, group: null, select: null, values: new Map(), form: null};
        if (groups.length > 1) return fallback(card);
        if (groups.length) {
            state.group = groups[0];
            state.select = state.group.querySelector('select');
            var fields = state.select ? Array.from(state.select.options).filter(function (field) { return field.value !== ''; }) : Array.from(state.group.querySelectorAll('input[type="radio"]'));
            if (!fields.length || state.group.querySelector('input[type="checkbox"], input[type="text"], textarea')) return fallback(card);
            for (var field of fields) {
                var label = state.select ? field : field.closest('label').querySelector('.option__name');
                var value = label && pack(label.textContent);
                if (!value) return fallback(card);
                state.values.set(field, value);
            }
        }
        var caption = card.querySelector('.lavka-pack-caption');
        if (!caption) {
            caption = document.createElement('small');
            caption.className = 'lavka-pack-caption';
            price.insertAdjacentElement('afterend', caption);
        }
        state.caption = caption;
        states.set(card, state);
        $(card).on('change.lavkaCard', '.qty-switch__input', function () { update(state); });
        card.classList.add('lavka-mobile-card');
        var layout = card.closest('.product-layout');
        if (layout) layout.classList.add('lavka-card-layout');
        if (state.group) {
            state.group.classList.add('lavka-pack-group');
            if (state.select) {
                state.group.querySelectorAll('.lavka-select-weights').forEach(function (oldChoices) { oldChoices.remove(); });
                state.select.classList.add('lavka-native-select');
                var choices = document.createElement('div');
                choices.className = 'lavka-select-weights';
                choices.setAttribute('role', 'group');
                choices.setAttribute('aria-label', state.group.querySelector('.option__group-name').textContent);
                state.values.forEach(function (value, option) {
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'lavka-select-weight';
                    button.dataset.value = option.value;
                    button.dataset.lavkaNumber = value.number;
                    button.dataset.lavkaUnit = value.unit;
                    button.setAttribute('aria-label', value.text);
                    button.disabled = option.disabled || state.select.disabled;
                    button.addEventListener('click', function () {
                        state.select.value = option.value;
                        $(state.select).trigger('change');
                    });
                    choices.appendChild(button);
                });
                state.group.appendChild(choices);
                if (!state.select.value) {
                    var first = Array.from(state.values.keys()).find(function (option) { return !option.disabled; });
                    if (first) state.select.value = first.value;
                }
            } else {
                // A distinct form owner isolates repeated products without changing option[...] names.
                var owner = document.createElement('form');
                owner.id = 'lavka-option-owner-' + (++sequence);
                owner.className = 'lavka-option-owner';
                owner.hidden = true;
                document.body.appendChild(owner);
                state.form = owner;
                state.values.forEach(function (value, input) {
                    input.setAttribute('form', owner.id);
                    input.setAttribute('aria-label', value.text);
                    var label = input.closest('label').querySelector('.option__name');
                    label.dataset.lavkaNumber = value.number;
                    label.dataset.lavkaUnit = value.unit;
                });
                if (!state.group.querySelector('input:checked')) {
                    var firstRadio = Array.from(state.values.keys()).find(function (input) { return !input.disabled; });
                    if (firstRadio) firstRadio.checked = true;
                }
            }
            $(state.group).on('change.lavkaCard', function () {
                card.classList.remove('lavka-cart-added');
                update(state);
            });
            // Every script's ready handler has run before initialization below.
            var chosen = state.select || state.group.querySelector('input:checked');
            if (chosen) $(chosen).trigger('change');
        }
        buy.setAttribute('aria-label', buy.title || 'Додати до кошика');
        update(state);
        if (window.cart && cart._pending && cart._pending[$(buy).data('pid')]) cart._setPending($(buy).data('pid'), true);
    }

    function fallback(card) {
        var layout = card.closest('.product-layout');
        if (layout) layout.setAttribute('data-lavka-fallback', 'true');
    }

    function scan(node) {
        if (node.nodeType !== 1) return;
        if (node.matches('.product-thumb')) setup(node);
        node.querySelectorAll('.product-thumb').forEach(setup);
    }

    // Pending options remain enabled for native serialization, but cannot be changed by the user.
    ['click', 'keydown', 'change'].forEach(function (eventName) {
        document.addEventListener(eventName, function (event) {
            if (!mobile.matches || !event.target.closest) return;
            var card = event.target.closest('.lavka-mobile-card.lavka-cart-pending');
            if (card && event.target.closest('.option')) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        }, true);
    });

    $(function () {
        setTimeout(function () {
            scan(document.body);
            // Observe structural card insertion only; price text animation never rescans the catalog.
            var observer = new MutationObserver(function (records) {
                records.forEach(function (record) {
                    record.addedNodes.forEach(function (node) {
                        if (node.nodeType === 1 && (node.matches('.product-thumb') || node.querySelector('.product-thumb'))) scan(node);
                    });
                    record.removedNodes.forEach(function (node) {
                        if (node.nodeType !== 1 || node.isConnected) return;
                        var cards = node.matches('.product-thumb') ? [node] : node.querySelectorAll('.product-thumb');
                        cards.forEach(function (card) {
                            var state = states.get(card);
                            if (state && state.form) state.form.remove();
                        });
                    });
                });
            });
            observer.observe(document.querySelector('main') || document.body, {childList: true, subtree: true});
        }, 0);
    });
})(jQuery);
