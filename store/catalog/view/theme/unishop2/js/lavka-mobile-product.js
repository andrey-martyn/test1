/* Enhance the installed UniShop product page; keep native options, gallery and cart. */
(function ($) {
    'use strict';
    var media=matchMedia('(max-width:767px)'), root, page, block, state;
    var lang=(document.documentElement.lang || 'uk').toLowerCase();
    var text=lang.indexOf('ru')===0 ? {quantity:'Количество упаковок',plus:'Увеличить количество',minus:'Уменьшить количество',added:'Добавлено в корзину',failed:'Не удалось подтвердить добавление. Проверьте корзину перед повторной попыткой.',pack:'за ',pending:'Добавляем…'} : lang.indexOf('en')===0 ? {quantity:'Number of packs',plus:'Increase quantity',minus:'Decrease quantity',added:'Added to cart',failed:'Could not confirm adding. Check your cart before trying again.',pack:'for ',pending:'Adding…'} : {quantity:'Кількість упаковок',plus:'Збільшити кількість',minus:'Зменшити кількість',added:'Додано до кошика',failed:'Не вдалося підтвердити додавання. Перевірте кошик перед повторною спробою.',pack:'за ',pending:'Додаємо…'};
    var packPattern=/^\s*(\d+(?:[.,]\d+)?)\s*(кг|kg|гр\.?|г|g|мл|ml|л|l|шт\.?|pcs?)\s*$/i;

    function move(node,parent) {
        if (!node) return;
        var marker=document.createComment('lavka-product-position'); node.before(marker);
        state.moves.push({node:node,marker:marker}); parent.appendChild(node);
    }
    function attribute(node,name,value) {
        state.attributes.push({node:node,name:name,value:node.getAttribute(name)});
        node.setAttribute(name,value);
    }
    function pack(value) {
        var match=value.replace(/\s*\([^)]*\)\s*$/,'').match(packPattern);
        return match ? match[1]+' '+match[2] : null;
    }
    function readPack() {
        if (!state.group) return '';
        var field=state.select ? state.select.options[state.select.selectedIndex] : state.group.querySelector('input:checked');
        return field && state.values.get(field) || '';
    }
    function sync() {
        if (!state || !state.mounted) return;
        var selected=readPack(), quantity=Number(state.quantity && state.quantity.value) || 1;
        var caption=selected ? text.pack+(quantity>1 ? quantity+' × ' : '')+selected : '';
        state.caption.textContent=caption; state.caption.hidden=!caption;
        state.dockPack.textContent=caption;
        state.dockPrice.textContent=(state.price.querySelector('.price-new') || state.price).textContent.trim();
        state.buttonLabel=(state.buy.querySelector('span') || state.buy).textContent.trim() || state.buttonLabel;
        state.dockButton.textContent=state.pending ? text.pending : state.buttonLabel;
        state.dockButton.disabled=state.pending || state.buy.disabled || state.buy.classList.contains('disabled');
        state.proxies.forEach(function (item) {
            item.button.setAttribute('aria-pressed',String(item.option.selected));
            item.button.disabled=item.option.disabled || state.select.disabled;
        });
    }
    function setupPacks() {
        var groups=block.querySelectorAll('.product-page__option .option__group');
        if (groups.length!==1) return;
        var group=groups[0], select=group.querySelector('select');
        var fields=select ? Array.from(select.options).filter(function (field) { return field.value!==''; }) : Array.from(group.querySelectorAll('input[type=radio]'));
        if (!fields.length || group.querySelector('input[type=checkbox],input[type=text],textarea,.option__img')) return;
        for (var field of fields) {
            var label=select ? field : field.closest('label').querySelector('.option__name');
            var value=label && pack(label.textContent);
            if (!value) return;
            state.values.set(field,value);
        }
        state.group=group; state.select=select;
        if (select) {
            select.classList.add('lavka-product-select-native');
            var choices=document.createElement('div'); choices.className='lavka-product-pack-grid';
            choices.setAttribute('role','group'); choices.setAttribute('aria-label',group.querySelector('.option__group-name').textContent);
            state.generated.push(choices); group.appendChild(choices);
            state.values.forEach(function (value,option) {
                var button=document.createElement('button'); button.type='button'; button.className='lavka-product-choice'; button.textContent=value;
                button.addEventListener('click',function () { select.value=option.value; $(select).trigger('change'); });
                choices.appendChild(button); state.proxies.push({button:button,option:option});
            });
            if (!select.value) {
                var first=fields.find(function (option) { return !option.disabled && !select.disabled; });
                if (first) { select.value=first.value; $(select).trigger('change'); }
            }
        } else {
            var grid=fields[0].closest('label').parentElement;
            grid.classList.add('lavka-product-pack-grid'); state.packGrid=grid;
            if (!group.querySelector('input:checked')) {
                var firstRadio=fields.find(function (input) { return !input.disabled; });
                if (firstRadio) { firstRadio.checked=true; $(firstRadio).trigger('change'); }
            }
        }
    }
    function dockVisibility() {
        if (!state || !state.mounted) return;
        var visible=state.cart.getBoundingClientRect().bottom<0;
        var focused=document.activeElement;
        if (focused && (focused.matches('textarea,select,[contenteditable=true]') || (focused.tagName==='INPUT' && !/^(radio|checkbox|button|submit|hidden)$/.test(focused.type)))) visible=false;
        if (document.querySelector('.modal.in,.mfp-wrap,.magnific-open')) visible=false;
        state.dock.hidden=!visible;
        document.body.classList.toggle('lavka-product-dock-visible',visible);
    }
    function settle(current,xhr,json,failed) {
        if (current.request!==xhr) return;
        current.pending=false; current.request=null;
        current.buy.disabled=current.wasDisabled;
        (current.locked || []).forEach(function (item) { item.node.disabled=item.disabled; });
        current.locked=[];
        if (current.busyBefore===null) current.buy.removeAttribute('aria-busy'); else current.buy.setAttribute('aria-busy',current.busyBefore);
        current.panel.classList.remove('lavka-product-pending');
        if (current.mounted) {
            current.status.textContent=failed ? text.failed : json && json.success ? text.added : '';
            current.status.dataset.error=String(failed);
            sync();
            if (json && json.error) {
                var invalid=current.panel.querySelector('.text-danger');
                if (invalid) invalid.scrollIntoView({block:'center',behavior:'smooth'});
            }
        }
        if (!current.mounted && media.matches) mount();
    }
    function mount() {
        if (state && state.mounted) return;
        // Resizing during an existing cart request must not create a second pending guard.
        if (state && state.pending) return;
        var buy=block.querySelector('#button-cart'), price=block.querySelector('.product-page__price'), cart=block.querySelector('.product-page__cart');
        if (!buy || !price || !cart) return;
        state={mounted:true,moves:[],attributes:[],generated:[],values:new Map(),proxies:[],buy:buy,price:price,cart:cart,pending:false,request:null};
        var meta=document.createElement('div');meta.className='lavka-product-meta';page.prepend(meta);state.generated.push(meta);
        move(block.querySelector('.product-page__rating'),meta);move(block.querySelector('.product-data .stock'),meta);
        var panel=document.createElement('div');panel.className='lavka-product-buy';block.prepend(panel);state.panel=panel;state.generated.push(panel);
        move(block.querySelector('.product-page__option'),panel);
        var recurring=block.querySelector('select[name=recurring_id]');
        if (recurring) {
            var group=recurring.closest('.form-group'), heading=group && group.previousElementSibling;
            if (heading && heading.tagName==='H3') move(heading,panel);
            move(group,panel);
        }
        move(price,panel);
        var caption=document.createElement('div');caption.className='lavka-product-pack-caption';caption.hidden=true;panel.appendChild(caption);state.caption=caption;
        move(block.querySelector('.product-page__eur-price'),panel);
        move(block.querySelector('.product-page__discount'),panel);move(block.querySelector('.price-tax'),panel);
        move(cart,panel);move(block.querySelector('.product-page__minimum'),panel);
        var status=document.createElement('div');status.className='lavka-product-status';status.setAttribute('role','status');status.setAttribute('aria-live','polite');panel.appendChild(status);state.status=status;
        state.quantity=cart.querySelector('.qty-switch__input');
        if (state.quantity) { attribute(state.quantity,'inputmode','numeric'); attribute(state.quantity,'aria-label',text.quantity); }
        state.qtyIcons=[];
        cart.querySelectorAll('.qty-switch__btn').forEach(function (icon) {
            var plus=icon.classList.contains('fa-plus'), button=document.createElement('button');
            button.type='button';button.className='lavka-product-qty-button';button.dataset.direction=plus ? 'plus' : 'minus';button.textContent=plus ? '+' : '−';
            button.setAttribute('aria-label',plus ? text.plus : text.minus);
            button.addEventListener('click',function () { icon.click(); });
            icon.classList.add('lavka-native-qty-icon');attribute(icon,'aria-hidden','true');state.qtyIcons.push(icon);
            icon.parentElement.appendChild(button);state.generated.push(button);
        });
        var thumbs=page.querySelectorAll('.product-page__image-addit-img');
        thumbs.forEach(function (thumb,index) {
            attribute(thumb,'role','button');attribute(thumb,'tabindex','0');
            attribute(thumb,'aria-label',(thumb.alt || '')+' ('+(index+1)+' / '+thumbs.length+')');
        });
        state.buttonLabel=(buy.querySelector('span') || buy).textContent.trim() || buy.title;
        var dock=document.createElement('div');dock.className='lavka-product-dock';dock.hidden=true;
        var totals=document.createElement('div'), dockPrice=document.createElement('div'), dockPack=document.createElement('div'), dockButton=document.createElement('button');
        dockPrice.className='lavka-product-dock__price';dockPack.className='lavka-product-dock__pack';dockButton.type='button';
        totals.appendChild(dockPrice);totals.appendChild(dockPack);dock.appendChild(totals);dock.appendChild(dockButton);document.body.appendChild(dock);
        state.dock=dock;state.dockPrice=dockPrice;state.dockPack=dockPack;state.dockButton=dockButton;state.generated.push(dock);
        dockButton.addEventListener('click',function () { if (!state.pending && !buy.disabled && !buy.classList.contains('disabled')) buy.click(); });
        state.observer=new MutationObserver(sync);state.observer.observe(price,{subtree:true,childList:true,characterData:true});state.observer.observe(buy,{subtree:true,childList:true,characterData:true,attributes:true,attributeFilter:['disabled','class']});
        root.classList.add('lavka-product-mobile');setupPacks();sync();dockVisibility();
        if (window.IntersectionObserver) { state.intersection=new IntersectionObserver(dockVisibility);state.intersection.observe(cart); }
    }
    function unmount() {
        if (!state || !state.mounted) return;
        state.mounted=false;state.observer.disconnect();if (state.intersection) state.intersection.disconnect();
        state.moves.slice().reverse().forEach(function (item) { item.marker.replaceWith(item.node); });
        state.attributes.forEach(function (item) { if (item.value===null) item.node.removeAttribute(item.name); else item.node.setAttribute(item.name,item.value); });
        if (state.select) state.select.classList.remove('lavka-product-select-native');
        if (state.packGrid) state.packGrid.classList.remove('lavka-product-pack-grid');
        state.qtyIcons.forEach(function (icon) { icon.classList.remove('lavka-native-qty-icon'); });
        state.generated.forEach(function (node) { node.remove(); });
        root.classList.remove('lavka-product-mobile');document.body.classList.remove('lavka-product-dock-visible');
    }
    function responsive() { if (media.matches) mount(); else unmount(); }
    // The original delegated cart handler still performs serialization, validation and analytics.
    document.addEventListener('click',function (event) {
        if (!state || !state.mounted) return;
        var buy=event.target.closest && event.target.closest('#button-cart');
        if (!buy || !page.contains(buy) || buy.disabled || buy.classList.contains('disabled')) return;
        if (state.pending) { event.preventDefault();event.stopImmediatePropagation();return; }
        state.pending=true;state.wasDisabled=buy.disabled;state.busyBefore=buy.getAttribute('aria-busy');buy.setAttribute('aria-busy','true');
        state.status.textContent=text.pending;state.panel.classList.add('lavka-product-pending');sync();
        var current=state;
        setTimeout(function () {
            if (!current.request && current.pending) { current.pending=false;current.buy.removeAttribute('aria-busy');current.panel.classList.remove('lavka-product-pending');sync(); }
        },0);
    },true);
    document.addEventListener('keydown',function (event) {
        if (!state || !state.mounted || !event.target.matches('.product-page__image-addit-img')) return;
        if (event.key==='Enter' || event.key===' ') { event.preventDefault();event.target.click(); }
    });
    $(document).on('ajaxSend.lavkaMobileProduct',function (_,xhr,settings) {
        if (!state || !state.pending || state.request || !/route=checkout(?:\/|%2f)cart(?:\/|%2f)add(?:&|$)/i.test(settings.url || '')) return;
        var current=state;current.request=xhr;
        setTimeout(function () {
            if (!current.pending) return;
            current.buy.disabled=true;
            current.locked=Array.from(current.panel.querySelectorAll('.option input,.option select,.option textarea,.qty-switch input,.lavka-product-qty-button,.lavka-product-choice')).map(function (node) {
                var item={node:node,disabled:node.disabled};node.disabled=true;return item;
            });
        },0);
        xhr.done(function (json) { settle(current,xhr,json,false); }).fail(function () { settle(current,xhr,null,true); });
    });
    $(function () {
        root=document.getElementById('product');if (!root) return;
        page=root.querySelector('.product-page');block=page && page.querySelector('.product-block');if (!block) return;
        setTimeout(responsive,0);
        if (media.addEventListener) media.addEventListener('change',responsive); else media.addListener(responsive);
        $(block).on('change.lavkaMobileProduct','.option input,.option select,.qty-switch__input',function () { sync(); });
        window.addEventListener('scroll',dockVisibility,{passive:true});window.addEventListener('resize',dockVisibility);
        document.addEventListener('focusin',dockVisibility);document.addEventListener('focusout',function () { setTimeout(dockVisibility,0); });
    });
})(jQuery);
