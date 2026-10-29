/* Enhance the installed UniShop product page; keep native options, gallery and cart. */
(function ($) {
    'use strict';
    var media=matchMedia('(max-width:767px)'), root, page, block, state;
    var lang=(document.documentElement.lang || 'uk').toLowerCase();
    var text=lang.indexOf('ru')===0 ? {about:'О товаре',quantity:'Количество упаковок',plus:'Увеличить количество',minus:'Уменьшить количество',added:'Добавлено в корзину',failed:'Не удалось подтвердить добавление. Проверьте корзину перед повторной попыткой.',pack:'за ',pending:'Добавляем…'} : lang.indexOf('en')===0 ? {about:'About',quantity:'Number of packs',plus:'Increase quantity',minus:'Decrease quantity',added:'Added to cart',failed:'Could not confirm adding. Check your cart before trying again.',pack:'for ',pending:'Adding…'} : {about:'Про товар',quantity:'Кількість упаковок',plus:'Збільшити кількість',minus:'Зменшити кількість',added:'Додано до кошика',failed:'Не вдалося підтвердити додавання. Перевірте кошик перед повторною спробою.',pack:'за ',pending:'Додаємо…'};
    var sections=lang.indexOf('ru')===0 ? {reviews:'Отзывы',delivery:'Доставка и оплата',close:'Закрыть',loading:'Загружаем условия магазина…',failed:'Не удалось загрузить условия магазина.',retry:'Попробовать ещё раз'} : lang.indexOf('en')===0 ? {reviews:'Reviews',delivery:'Delivery & payment',close:'Close',loading:'Loading store terms…',failed:'Could not load store terms.',retry:'Try again'} : {reviews:'Відгуки',delivery:'Доставка й оплата',close:'Закрити',loading:'Завантажуємо умови магазину…',failed:'Не вдалося завантажити умови магазину.',retry:'Спробувати ще раз'};
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
        if (state.dockQuantity) state.dockQuantity.textContent=String(quantity);
        (state.dockQtyButtons || []).forEach(function (button) { button.disabled=state.pending || !!(state.quantity && state.quantity.disabled); });
        state.dockPrice.textContent=(state.price.querySelector('.price-new') || state.price).textContent.trim();
        state.buttonLabel=(state.buy.querySelector('span') || state.buy).textContent.trim() || state.buttonLabel;
        state.dockButton.textContent=state.pending ? text.pending : state.buttonLabel;
        state.dockButton.disabled=state.pending || state.buy.disabled || state.buy.classList.contains('disabled');
        state.proxies.forEach(function (item) {
            item.button.setAttribute('aria-pressed',String(state.select ? item.option.selected : item.option.checked));
            item.button.disabled=state.pending || item.option.disabled || !!(state.select && state.select.disabled);
        });
    }
    function setupPacks() {
        var groups=block.querySelectorAll('.product-page__option .option__group');
        var image=page.querySelector('.product-page__image-main');
        if (groups.length!==1 || !image) return;
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
        // Only visual buttons live on the photo. Native fields stay inside product-block:
        // the installed live-price handler scopes its price/options lookup to that block.
        group.classList.add('lavka-product-pack-native');
        var choices=document.createElement('div'); choices.className='lavka-product-packs';
        choices.setAttribute('role','group'); choices.setAttribute('aria-label',group.querySelector('.option__group-name').textContent);
        state.generated.push(choices); image.appendChild(choices);
        state.values.forEach(function (value,option) {
            var button=document.createElement('button'), amount=document.createElement('span'), unit=document.createElement('span'), parts=value.split(' ');
            button.type='button'; button.className='lavka-product-choice'; button.setAttribute('aria-label',value);
            amount.textContent=parts[0]; unit.textContent=parts[1]; button.appendChild(amount); button.appendChild(unit);
            button.addEventListener('click',function () {
                if (select) { select.value=option.value; $(select).trigger('change'); }
                else { option.checked=true; $(option).trigger('change'); }
            });
            choices.appendChild(button); state.proxies.push({button:button,option:option});
        });
        if (select) {
            if (!select.value) {
                var first=fields.find(function (option) { return !option.disabled && !select.disabled; });
                if (first) { select.value=first.value; $(select).trigger('change'); }
            }
        } else {
            if (!group.querySelector('input:checked')) {
                var firstRadio=fields.find(function (input) { return !input.disabled; });
                if (firstRadio) { firstRadio.checked=true; $(firstRadio).trigger('change'); }
            }
        }
    }
    function reviewCount() {
        if (!state || !state.mounted || !state.ratingLink) return;
        var label=state.ratingLink.textContent.trim(), numbers=label.match(/\d+/g);
        state.ratingLink.setAttribute('data-lavka-review-count',numbers ? numbers[numbers.length-1] : '');
        state.ratingLink.setAttribute('aria-label',label);
    }
    function topOffset() {
        var fly=document.querySelector('#fly-menu.show');
        return fly ? Math.ceil(fly.getBoundingClientRect().height) : 0;
    }
    function scrollToSection(target) {
        if (!state || !state.mounted || !target) return;
        var fly=document.querySelector('#fly-menu'), offset=Math.max(topOffset(),fly ? fly.getBoundingClientRect().height : 0);
        $('html, body').stop(true);
        window.scrollTo({top:Math.max(0,target.getBoundingClientRect().top+scrollY-offset-(state.nav ? state.nav.offsetHeight : 0)-12),behavior:'smooth'});
    }
    function markSection(name) {
        if (!state || !state.nav) return;
        state.nav.querySelectorAll('button').forEach(function (button) {
            if (button.dataset.section===name) button.setAttribute('aria-current','true'); else button.removeAttribute('aria-current');
        });
    }
    function nativeSection(event) {
        if (!state || !state.mounted) return;
        var link=event.target.closest('a[data-toggle="tab"]');
        if (!link || !state.tabs || !state.tabs.contains(link)) return;
        markSection(link.getAttribute('href')==='#tab-review' ? 'reviews' : 'about');
        setTimeout(function () { scrollToSection(document.getElementById((link.getAttribute('href') || '').slice(1))); },0);
    }
    function deliverySources() {
        var found=[], shipping=/(достав|shipping|delivery)/i, payment=/(оплат|payment)/i;
        document.querySelectorAll('header a[href],footer a[href],header [data-href],footer [data-href]').forEach(function (link) {
            var label=(link.textContent+' '+(link.title || '')).replace(/\s+/g,' ').trim(), url;
            if (!shipping.test(label) && !payment.test(label)) return;
            try { url=new URL(link.getAttribute('href') || link.dataset.href,document.baseURI); } catch (_) { return; }
            // Menu settings in a test clone can retain the main shop host; read its test copy.
            if (location.hostname==='test.lavka-speciy.com' && /^(www\.)?lavka-speciy\.com$/.test(url.hostname)) url=new URL(url.pathname+url.search,location.origin);
            if (url.origin!==location.origin || !/^https?:$/.test(url.protocol) || found.some(function (item) { return item.url===url.href; })) return;
            found.push({url:url.href,label:label,shipping:shipping.test(label),payment:payment.test(label)});
        });
        var combined=found.find(function (item) { return item.shipping && item.payment; });
        if (combined) return [combined];
        var first=found.find(function (item) { return item.shipping; }), second=found.find(function (item) { return item.payment; });
        return first && second ? [first,second] : [];
    }
    function safeTerms(source) {
        var fragment=document.createDocumentFragment();
        var allowed=/^(P|DIV|SECTION|ARTICLE|SPAN|STRONG|B|EM|I|U|S|BR|HR|UL|OL|LI|H2|H3|H4|H5|H6|TABLE|THEAD|TBODY|TR|TD|TH|CAPTION|A)$/;
        var omit=/^(SCRIPT|STYLE|IFRAME|FORM|INPUT|BUTTON|SELECT|TEXTAREA|NOSCRIPT|SVG|OBJECT|EMBED|TEMPLATE)$/;
        function copy(node,parent) {
            if (node.nodeType===3) { parent.appendChild(document.createTextNode(node.textContent)); return; }
            if (node.nodeType!==1 || omit.test(node.tagName)) return;
            var next=parent;
            if (allowed.test(node.tagName)) {
                next=document.createElement(node.tagName.toLowerCase());
                if (node.tagName==='A') {
                    try {
                        var url=new URL(node.getAttribute('href'),document.baseURI);
                        if (/^(https?:|mailto:|tel:)$/.test(url.protocol)) { next.href=url.href;next.target='_blank';next.rel='noopener noreferrer'; }
                    } catch (_) { /* Keep the public link text if its URL is invalid. */ }
                }
                parent.appendChild(next);
            }
            Array.from(node.childNodes).forEach(function (child) { copy(child,next); });
        }
        Array.from(source.childNodes).forEach(function (node) { copy(node,fragment); });
        return fragment;
    }
    function loadDelivery(current) {
        if (current.deliveryLoaded || current.deliveryLoading) return;
        var sources=deliverySources(), body=current.deliveryBody;
        current.deliveryLoading=true;body.textContent=sections.loading;body.setAttribute('aria-busy','true');
        function failed() {
            if (!current.mounted) return;
            current.deliveryLoading=false;body.removeAttribute('aria-busy');body.textContent=sections.failed;
            var retry=document.createElement('button');retry.type='button';retry.className='lavka-product-delivery__retry';retry.textContent=sections.retry;
            retry.addEventListener('click',function () { loadDelivery(current); });body.appendChild(retry);
        }
        if (!sources.length) { failed(); return; }
        current.deliveryRequests=sources.map(function (item) { return $.ajax({url:item.url,type:'GET',dataType:'html',timeout:12000}); });
        Promise.all(current.deliveryRequests.map(function (xhr) { return new Promise(function (resolve,reject) { xhr.done(resolve).fail(reject); }); })).then(function (pages) {
            if (!current.mounted) return;
            var content=document.createDocumentFragment();
            pages.forEach(function (html,index) {
                var parsed=new DOMParser().parseFromString(html,'text/html'), article=parsed.querySelector('#content .article_description,#content .information-description,#content');
                if (!article || !article.textContent.trim()) throw new Error('Missing public terms');
                article.querySelectorAll('.breadcrumb,.heading-h1,.buttons,.nav,.product-thumb').forEach(function (node) { node.remove(); });
                if (pages.length>1) { var heading=document.createElement('h3');heading.textContent=sources[index].label;content.appendChild(heading); }
                content.appendChild(safeTerms(article));
            });
            body.textContent='';body.appendChild(content);body.removeAttribute('aria-busy');current.deliveryLoaded=true;current.deliveryLoading=false;
        }).catch(failed);
    }
    function openDelivery() {
        if (!state || !state.mounted) return;
        var current=state;
        if (!current.delivery) {
            var dialog=document.createElement('dialog'), header=document.createElement('div'), heading=document.createElement('h2'), close=document.createElement('button'), body=document.createElement('div');
            dialog.className='lavka-product-delivery';dialog.setAttribute('aria-label',sections.delivery);
            header.className='lavka-product-delivery__header';heading.textContent=sections.delivery;
            close.type='button';close.className='lavka-product-delivery__close';close.textContent='×';close.setAttribute('aria-label',sections.close);
            body.className='lavka-product-delivery__body';body.setAttribute('aria-live','polite');
            header.appendChild(heading);header.appendChild(close);dialog.appendChild(header);dialog.appendChild(body);document.body.appendChild(dialog);
            close.addEventListener('click',function () { dialog.close(); });
            dialog.addEventListener('click',function (event) { if (event.target===dialog) { var box=dialog.getBoundingClientRect();if (event.clientX<box.left || event.clientX>box.right || event.clientY<box.top || event.clientY>box.bottom) dialog.close(); } });
            dialog.addEventListener('close',function () { document.body.style.overflow=current.bodyOverflow;dockVisibility(); });
            current.delivery=dialog;current.deliveryBody=body;current.generated.push(dialog);
        }
        if (current.delivery.open) return;
        current.bodyOverflow=document.body.style.overflow;document.body.style.overflow='hidden';
        current.delivery.showModal();loadDelivery(current);dockVisibility();
    }
    function setupNavigation() {
        var tabs=root.querySelector('#content>.nav-tabs'), heading=root.querySelector('.heading-h1');
        if (!tabs || !heading) return;
        state.tabs=tabs;state.sourceItems=[];
        var nav=document.createElement('nav');nav.className='lavka-product-nav';nav.setAttribute('aria-label',text.about);
        [['about',text.about,'#tab-description'],['reviews',sections.reviews,'#tab-review'],['delivery',sections.delivery,null]].forEach(function (item) {
            var native=item[2] && tabs.querySelector('a[href="'+item[2]+'"]');
            if (item[0]!=='delivery' && !native) return;
            if (native) { var li=native.closest('li');state.sourceItems.push({node:li,hadClass:li.hasAttribute('class')});li.classList.add('lavka-product-primary-source'); }
            var button=document.createElement('button');button.type='button';button.textContent=item[1];button.dataset.section=item[0];nav.appendChild(button);
            if (item[0]==='delivery') { button.setAttribute('aria-haspopup','dialog');button.addEventListener('click',openDelivery); }
            else button.addEventListener('click',function () {
                $(native).trigger('click');
                if (item[0]==='about') setTimeout(function () { scrollToSection(heading); },0);
            });
        });
        if (!tabs.querySelector('li:not(.lavka-product-primary-source)')) tabs.classList.add('lavka-product-only-primary');
        heading.before(nav);state.nav=nav;state.generated.push(nav);
        var active=tabs.querySelector('li.active>a');markSection(active && active.getAttribute('href')==='#tab-review' ? 'reviews' : 'about');
        $(tabs).on('click.lavkaMobileProductNav','a[data-toggle="tab"]',nativeSection);
    }
    function dockVisibility() {
        if (!state || !state.mounted) return;
        var offset=topOffset();
        root.style.setProperty('--lavka-product-top',offset+'px');
        var visible=state.buy.getBoundingClientRect().bottom<offset;
        var focused=document.activeElement;
        if (focused && (focused.matches('textarea,select,[contenteditable=true]') || (focused.tagName==='INPUT' && !/^(radio|checkbox|button|submit|hidden)$/.test(focused.type)))) visible=false;
        if (document.querySelector('.modal.in,.mfp-wrap,.magnific-open,.lavka-product-delivery[open]')) visible=false;
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
        var meta=document.createElement('div'), facts=document.createElement('div');meta.className='lavka-product-meta';facts.className='lavka-product-facts';page.prepend(meta);state.generated.push(meta);
        move(block.querySelector('.product-page__rating'),meta);meta.appendChild(facts);
        move(block.querySelector('.product-data .stock'),facts);move(block.querySelector('.product-data .manufacturer'),facts);
        state.emptyData=Array.from(block.querySelectorAll('.product-data')).filter(function (data) { return !data.querySelector('.product-data__item'); });
        state.emptyData.forEach(function (data) { data.classList.add('lavka-product-empty-data'); });
        var panel=document.createElement('div');panel.className='lavka-product-buy';block.prepend(panel);state.panel=panel;state.generated.push(panel);
        move(block.querySelector('.product-page__option'),panel);
        var recurring=block.querySelector('select[name=recurring_id]');
        if (recurring) {
            var group=recurring.closest('.form-group'), heading=group && group.previousElementSibling;
            if (heading && heading.tagName==='H3') move(heading,panel);
            move(group,panel);
        }
        var line=document.createElement('div'), totals=document.createElement('div');
        line.className='lavka-product-buy-line';totals.className='lavka-product-totals';panel.appendChild(line);line.appendChild(totals);state.generated.push(line,totals);
        move(price,totals);
        var caption=document.createElement('div');caption.className='lavka-product-pack-caption';caption.hidden=true;totals.appendChild(caption);state.caption=caption;
        move(block.querySelector('.product-page__eur-price'),panel);
        move(block.querySelector('.product-page__discount'),panel);move(block.querySelector('.price-tax'),panel);
        move(cart,line);move(block.querySelector('.product-page__minimum'),panel);
        setupNavigation();
        state.topBefore=root.style.getPropertyValue('--lavka-product-top');
        state.ratingLink=meta.querySelector('.product-page__rating-a');
        if (state.ratingLink) {
            attribute(state.ratingLink,'data-lavka-review-count','');attribute(state.ratingLink,'aria-label',state.ratingLink.textContent.trim());
            reviewCount();state.reviewObserver=new MutationObserver(reviewCount);state.reviewObserver.observe(state.ratingLink,{subtree:true,childList:true,characterData:true});
        }
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
        var dockQty=document.createElement('div'), dockValue=document.createElement('span');dockQty.className='lavka-product-dock__quantity';dockValue.className='lavka-product-dock__value';state.dockQuantity=dockValue;state.dockQtyButtons=[];
        dockQty.setAttribute('role','group');dockQty.setAttribute('aria-label',text.quantity);dockValue.setAttribute('aria-live','polite');
        ['minus','plus'].forEach(function (direction) {
            var button=document.createElement('button');button.type='button';button.dataset.direction=direction;button.textContent=direction==='plus' ? '+' : '−';button.setAttribute('aria-label',direction==='plus' ? text.plus : text.minus);
            button.addEventListener('click',function () { var original=cart.querySelector('.lavka-product-qty-button[data-direction="'+direction+'"]');if (original && !original.disabled) original.click(); });
            if (direction==='plus') dockQty.appendChild(dockValue);dockQty.appendChild(button);state.dockQtyButtons.push(button);
        });
        dockButton.className='lavka-product-dock__buy';totals.className='lavka-product-dock__totals';
        totals.appendChild(dockPrice);totals.appendChild(dockPack);dock.appendChild(totals);if (state.quantity) dock.appendChild(dockQty);else dock.classList.add('lavka-product-dock--no-quantity');dock.appendChild(dockButton);document.body.appendChild(dock);
        state.dock=dock;state.dockPrice=dockPrice;state.dockPack=dockPack;state.dockButton=dockButton;state.generated.push(dock);
        dockButton.addEventListener('click',function () { if (!state.pending && !buy.disabled && !buy.classList.contains('disabled')) buy.click(); });
        state.observer=new MutationObserver(sync);state.observer.observe(price,{subtree:true,childList:true,characterData:true});state.observer.observe(buy,{subtree:true,childList:true,characterData:true,attributes:true,attributeFilter:['disabled','class']});
        root.classList.add('lavka-product-mobile');setupPacks();sync();dockVisibility();
        if (window.IntersectionObserver) { state.intersection=new IntersectionObserver(dockVisibility);state.intersection.observe(buy); }
    }
    function unmount() {
        if (!state || !state.mounted) return;
        state.mounted=false;state.observer.disconnect();if (state.intersection) state.intersection.disconnect();
        if (state.reviewObserver) state.reviewObserver.disconnect();
        if (state.tabs) { $(state.tabs).off('.lavkaMobileProductNav');state.tabs.classList.remove('lavka-product-only-primary'); }
        (state.sourceItems || []).forEach(function (item) { item.node.classList.remove('lavka-product-primary-source');if (!item.hadClass && !item.node.className) item.node.removeAttribute('class'); });
        if (state.delivery && state.delivery.open) { state.delivery.close();document.body.style.overflow=state.bodyOverflow; }
        (state.deliveryRequests || []).forEach(function (xhr) { if (xhr.readyState!==4) xhr.abort(); });
        state.emptyData.forEach(function (data) { data.classList.remove('lavka-product-empty-data'); });
        state.moves.slice().reverse().forEach(function (item) { item.marker.replaceWith(item.node); });
        state.attributes.forEach(function (item) { if (item.value===null) item.node.removeAttribute(item.name); else item.node.setAttribute(item.name,item.value); });
        if (state.group) state.group.classList.remove('lavka-product-pack-native');
        if (state.topBefore) root.style.setProperty('--lavka-product-top',state.topBefore); else root.style.removeProperty('--lavka-product-top');
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
