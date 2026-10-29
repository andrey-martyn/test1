/* Enhance the installed UniShop product page; keep native options, gallery and cart. */
(function ($) {
    'use strict';
    var media=matchMedia('(max-width:767px)'), root, page, block, state;
    var lang=(document.documentElement.lang || 'uk').toLowerCase();
    var text=lang.indexOf('ru')===0 ? {about:'О товаре',quantity:'Количество упаковок',plus:'Увеличить количество',minus:'Уменьшить количество',added:'Добавлено в корзину',failed:'Не удалось подтвердить добавление. Проверьте корзину перед повторной попыткой.',pack:'за ',pending:'Добавляем…'} : lang.indexOf('en')===0 ? {about:'About',quantity:'Number of packs',plus:'Increase quantity',minus:'Decrease quantity',added:'Added to cart',failed:'Could not confirm adding. Check your cart before trying again.',pack:'for ',pending:'Adding…'} : {about:'Про товар',quantity:'Кількість упаковок',plus:'Збільшити кількість',minus:'Зменшити кількість',added:'Додано до кошика',failed:'Не вдалося підтвердити додавання. Перевірте кошик перед повторною спробою.',pack:'за ',pending:'Додаємо…'};
    var sections=lang.indexOf('ru')===0 ? {reviews:'Отзывы',delivery:'Доставка и оплата',close:'Закрыть',loading:'Загружаем условия магазина…',failed:'Не удалось загрузить условия магазина.',retry:'Попробовать ещё раз'} : lang.indexOf('en')===0 ? {reviews:'Reviews',delivery:'Delivery & payment',close:'Close',loading:'Loading store terms…',failed:'Could not load store terms.',retry:'Try again'} : {reviews:'Відгуки',delivery:'Доставка й оплата',close:'Закрити',loading:'Завантажуємо умови магазину…',failed:'Не вдалося завантажити умови магазину.',retry:'Спробувати ще раз'};
    sections.writeReview=lang.indexOf('ru')===0 ? 'Написать отзыв' : lang.indexOf('en')===0 ? 'Write a review' : 'Написати відгук';
    sections.extra=lang.indexOf('ru')===0 ? 'Информация магазина' : lang.indexOf('en')===0 ? 'Store information' : 'Інформація магазину';
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
        var data=$(state.price), special=Number(data.data('special'))>0, total=Number(data.data(special ? 'old-special' : 'old-price'));
        if (!Number.isFinite(total)) total=Number(data.data(special ? 'special' : 'price'));
        var rate=Number(data.data('eurcurr')), euro=state.eurNative && rate>0 && Number.isFinite(total) ? (total/rate).toFixed(1)+' €' : '';
        state.captionText.textContent=caption;state.captionEur.textContent=(caption && euro ? ' / ' : '')+euro;state.caption.hidden=!caption && !euro;
        state.dockPrice.textContent=(state.price.querySelector('.price-new') || state.price).textContent.trim();
        state.dockPrice.setAttribute('aria-label',state.dockPrice.textContent+(caption ? ', '+caption : ''));
        state.buttonLabel=(state.buy.querySelector('span') || state.buy).textContent.trim() || state.buttonLabel;
        state.dockButton.textContent=state.pending ? text.pending : state.buttonLabel;
        state.dockButton.disabled=state.pending || state.buy.disabled || state.buy.classList.contains('disabled');
        state.dockActions.forEach(function (item) {
            item.button.setAttribute('aria-pressed',String(item.source.classList.contains('active')));
            item.button.setAttribute('aria-label',item.source.title);item.button.title=item.source.title;item.button.disabled=item.source.disabled;
        });
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
        if (!state || !state.mounted) return;
        var label=state.ratingLink ? state.ratingLink.textContent.trim() : '', numbers=label.match(/\d+/g), total=numbers ? Number(numbers[numbers.length-1]) : 0;
        if (state.ratingLink) {
            state.ratingLink.setAttribute('data-lavka-review-count',total ? String(total) : '');
            state.ratingLink.setAttribute('aria-label',label);
        }
        if (state.rating) state.rating.classList.toggle('lavka-product-rating-empty',!total);
        if (state.emptyReview) state.emptyReview.hidden=state.reviewEnabled===false || total>0;
    }
    function topOffset() {
        var fly=document.querySelector('#fly-menu'), box=fly && fly.getBoundingClientRect();
        return box ? Math.max(0,Math.min(box.height,box.bottom)) : 0;
    }
    function followChrome(event) {
        if (!state || !state.mounted || event.target.id!=='fly-menu') return;
        var current=state;current.chromeUntil=performance.now()+550;
        if (current.chromeFrame) return;
        function frame() {
            current.chromeFrame=0;if (!current.mounted) return;
            dockVisibility();if (performance.now()<current.chromeUntil) current.chromeFrame=requestAnimationFrame(frame);
        }
        current.chromeFrame=requestAnimationFrame(frame);
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
            var next=parent, boldInline=/^(SPAN|FONT)$/.test(node.tagName) && (node.style.fontWeight==='bold' || Number(node.style.fontWeight)>=600) && !node.querySelector('p,div,ul,ol,table,h2,h3,h4');
            if (allowed.test(node.tagName) || boldInline) {
                next=document.createElement(boldInline ? 'strong' : node.tagName.toLowerCase());
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
        return formatTerms(fragment);
    }
    function formatTerms(fragment) {
        var host=document.createElement('div');host.appendChild(fragment);
        var block=/^(P|UL|OL|LI|H2|H3|H4|H5|H6|TABLE|THEAD|TBODY|TR|TD|TH|CAPTION|HR)$/;
        var wrappers=/^(DIV|SECTION|ARTICLE)$/;
        function empty(node) { return !node.textContent.replace(/[\s\u00a0\u200b]/g,'') && !node.querySelector('table,hr'); }
        function runs(container) {
            var paragraph=null;
            Array.from(container.childNodes).forEach(function (node) {
                if (node.nodeType===1 && block.test(node.tagName)) { paragraph=null;return; }
                if (node.nodeType===1 && node.tagName==='BR') { node.remove();paragraph=null;return; }
                if (node.nodeType===3 && !node.textContent.trim() && !paragraph) { node.remove();return; }
                if (!paragraph) { paragraph=document.createElement('p');container.insertBefore(paragraph,node); }
                paragraph.appendChild(node);
            });
        }
        function tidy(container) {
            Array.from(container.childNodes).forEach(function (node) {
                if (node.nodeType===3) { node.textContent=node.textContent.replace(/[\s\u00a0\u200b]+/g,' ');return; }
                if (node.nodeType!==1) { node.remove();return; }
                tidy(node);
                if (node.tagName==='SPAN') { node.replaceWith.apply(node,Array.from(node.childNodes));return; }
                if (wrappers.test(node.tagName)) {
                    if (empty(node)) { node.remove();return; }
                    runs(node);node.replaceWith.apply(node,Array.from(node.childNodes));return;
                }
                if (node.tagName!=='BR' && node.tagName!=='HR' && empty(node)) node.remove();
            });
            if (/^(P|LI|H2|H3|H4|H5|H6)$/.test(container.tagName)) {
                var last=null;
                Array.from(container.childNodes).forEach(function (node) {
                    if (node.nodeType===3 && !node.textContent.trim()) return;
                    if (node.nodeName==='BR' && (!last || last.nodeName==='BR')) node.remove();else last=node;
                });
                if (last && last.nodeName==='BR') last.remove();
            }
        }
        tidy(host);runs(host);
        // Rich-text editors often encode section headings as a bold paragraph.
        host.querySelectorAll('p').forEach(function (paragraph) {
            var label=paragraph.textContent.trim(), bold=paragraph.querySelector('strong,b');
            if (paragraph.parentElement===host && bold && label.length<90 && !/\d/.test(label) && bold.textContent.trim()===label && /^(?:оплата|доставка|способи оплати|способы оплаты|як .*замовлення|как .*заказ|ми .*зв.язку|мы .*связи|payment|delivery|shipping)/i.test(label)) {
                var heading=document.createElement('h3');heading.textContent=label;paragraph.replaceWith(heading);
            }
        });
        var bullets=null;
        Array.from(host.children).forEach(function (node) {
            if (node.tagName!=='P' || !/^[\s\u00a0]*[•●▪]/.test(node.textContent)) { bullets=null;return; }
            var walker=document.createTreeWalker(node,NodeFilter.SHOW_TEXT), first;
            while ((first=walker.nextNode())) { if (first.textContent.trim()) { first.textContent=first.textContent.replace(/^[\s\u00a0]*[•●▪]\s*/,'');break; } }
            if (!bullets) { bullets=document.createElement('ul');host.insertBefore(bullets,node); }
            var li=document.createElement('li');while (node.firstChild) li.appendChild(node.firstChild);bullets.appendChild(li);node.remove();
        });
        host.className='lavka-product-terms';return host;
    }
    function deliverySections(terms) {
        var groups=[], current=null, intro=document.createElement('div');
        Array.from(terms.children).forEach(function (node) {
            var label=node.textContent.trim(), kind=null;
            if (/^H[2-6]$/.test(node.tagName)) {
                if (/^(доставка(?:\s+(та|й|і|и|&)\s+оплата)?|умови доставки|условия доставки|способи доставки|способы доставки|delivery|shipping)\s*[:?]?$/i.test(label)) kind='delivery';
                else if (/^(оплата|умови оплати|условия оплаты|способи оплати|способы оплаты|payment)\s*[:?]?$/i.test(label)) kind='payment';
                else if (/^(як .*замов|как .*заказ|оформлення замовлення|оформление заказа|how to order)/i.test(label)) kind='order';
                else if (/^(ми .*зв.язку|мы .*связи|контакт|contact)/i.test(label)) kind='contacts';
            }
            if (kind) { current={label:label,kind:kind,body:document.createElement('div'),index:groups.length};groups.push(current); }
            else (current ? current.body : intro).appendChild(node);
        });
        if (!groups.length) { while (intro.firstChild) terms.appendChild(intro.firstChild);return terms; }
        if (intro.textContent.trim()) groups.unshift({label:sections.extra,kind:'extra',body:intro,index:-1});
        groups.sort(function (a,b) { var rank={delivery:0,payment:1};return (rank[a.kind]===undefined ? 2 : rank[a.kind])-(rank[b.kind]===undefined ? 2 : rank[b.kind]) || a.index-b.index; });
        terms.textContent='';
        groups.forEach(function (group) {
            var details=document.createElement('details'), summary=document.createElement('summary');details.className='terms-section';details.dataset.kind=group.kind;
            details.open=group.kind==='delivery' || group.kind==='payment';summary.textContent=group.label;group.body.className='terms-section__body';details.appendChild(summary);details.appendChild(group.body);terms.appendChild(details);
        });
        return terms;
    }
    function loadDelivery(current) {
        if (current.deliveryLoaded || current.deliveryLoading) return;
        var sources=deliverySources(), body=current.deliveryBody;
        current.deliveryLoading=true;body.textContent=sections.loading;body.setAttribute('aria-busy','true');
        function failed() {
            if (!current.mounted) return;
            current.deliveryLoading=false;body.removeAttribute('aria-busy');body.textContent='';
            var message=document.createElement('div'), paragraph=document.createElement('p');message.className='lavka-product-terms';paragraph.textContent=sections.failed;message.appendChild(paragraph);body.appendChild(message);
            var retry=document.createElement('button');retry.type='button';retry.className='lavka-product-delivery__retry';retry.textContent=sections.retry;
            retry.addEventListener('click',function () { loadDelivery(current); });message.appendChild(retry);
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
                content.appendChild(deliverySections(safeTerms(article)));
            });
            body.textContent='';body.appendChild(content);body.removeAttribute('aria-busy');current.deliveryLoaded=true;current.deliveryLoading=false;
        }).catch(failed);
    }
    function restoreDelivery(current) {
        if (current.rootOverflow===undefined) return;
        document.documentElement.style.overflow=current.rootOverflow;current.rootOverflow=undefined;
        if (current.mounted) {
            document.body.classList.toggle('lavka-product-dock-visible',current.dockBeforeDelivery);
            window.scrollTo(current.deliveryScroll.x,current.deliveryScroll.y);dockVisibility();
        }
    }
    function openDelivery() {
        if (!state || !state.mounted) return;
        var current=state;
        if (!current.delivery) {
            var dialog=document.createElement('dialog'), shell=document.createElement('div'), header=document.createElement('div'), heading=document.createElement('h2'), close=document.createElement('button'), body=document.createElement('div');
            dialog.className='lavka-product-delivery';dialog.dataset.lavkaProductVersion='6';dialog.setAttribute('aria-label',sections.delivery);
            shell.className='lavka-product-delivery-shell';var shadow=shell.attachShadow({mode:'open'}), style=document.createElement('style');
            style.textContent=[
                ":host{display:block;color:#252a2c;font:400 14px/1.6 Rubik,Tahoma,sans-serif;text-align:left;-webkit-text-size-adjust:100%}",
                "*{box-sizing:border-box}h2,h3,h4,p,ul,ol{padding:0}button{font-family:inherit;cursor:pointer}",
                ".lavka-product-delivery__header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 20px;border-bottom:1px solid #e3e6df;background:#fff}",
                "h2{margin:0;font-size:18px;line-height:24px;font-weight:500}",
                ".lavka-product-delivery__close{flex:none;width:44px;height:44px;margin:0;padding:0;border:1px solid #e1e8da;border-radius:50%;background:#f2f5ee;color:#63745e;font-size:28px;line-height:40px}",
                ".lavka-product-delivery__body{max-height:calc(85vh - 69px);max-height:calc(85dvh - 69px);overflow:auto;overscroll-behavior:contain;padding:16px 16px calc(20px + env(safe-area-inset-bottom));background:#f5f7f2}",
                ".lavka-product-terms{margin:0;padding:0;font-size:14px;line-height:1.6;overflow-wrap:anywhere}",
                ".terms-section{margin:0 0 12px;border:1px solid #e0e6d9;border-radius:8px;background:#fff;overflow:hidden}",
                ".terms-section:last-child{margin-bottom:0}summary{display:flex;align-items:center;justify-content:space-between;gap:12px;min-height:48px;padding:12px 16px;color:#354b2a;font-size:16px;line-height:22px;font-weight:500;list-style:none;cursor:pointer}",
                "summary::-webkit-details-marker{display:none}summary::after{content:'';flex:none;width:8px;height:8px;margin:0 2px 4px 0;border-right:1.5px solid #63745e;border-bottom:1.5px solid #63745e;transform:rotate(45deg)}details[open]>summary::after{margin-top:6px;transform:rotate(225deg)}",
                ".terms-section__body{padding:0 16px 16px;color:#3d4047}",
                "p{margin:0 0 10px;font-size:14px;line-height:1.6}p:last-child{margin-bottom:0}strong,b{font-weight:500}",
                "ul,ol{margin:4px 0 12px;padding-left:20px;list-style-position:outside}ul{list-style-type:disc}ol{list-style-type:decimal}li{margin:0 0 8px;padding:0 0 0 2px}li:last-child{margin-bottom:0}li>p{margin-bottom:6px}",
                "h3,h4,h5,h6{margin:16px 0 8px;font-size:15px;line-height:22px;font-weight:500}h3:first-child,h4:first-child{margin-top:0}",
                "table{display:block;max-width:100%;overflow:auto;margin:0 0 12px;border-collapse:collapse}td,th{padding:6px;text-align:left;border-bottom:1px solid #edf0e9}",
                "a{color:#4f7f28;text-decoration:underline;text-underline-offset:2px}.lavka-product-delivery__retry{display:block;min-height:44px;margin:12px 0 0;padding:8px 14px;border:0;border-radius:8px;background:#4f7f28;color:#fff;font-size:14px}",
                "button:focus-visible,summary:focus-visible,a:focus-visible{outline:2px solid #4f7f28;outline-offset:-2px}"
            ].join('\n');
            header.className='lavka-product-delivery__header';heading.textContent=sections.delivery;
            close.type='button';close.className='lavka-product-delivery__close';close.textContent='×';close.setAttribute('aria-label',sections.close);
            body.className='lavka-product-delivery__body';body.setAttribute('aria-live','polite');
            header.appendChild(heading);header.appendChild(close);shadow.appendChild(style);shadow.appendChild(header);shadow.appendChild(body);dialog.appendChild(shell);document.body.appendChild(dialog);
            close.addEventListener('click',function () { dialog.close(); });
            dialog.addEventListener('click',function (event) { if (event.target===dialog) { var box=dialog.getBoundingClientRect();if (event.clientX<box.left || event.clientX>box.right || event.clientY<box.top || event.clientY>box.bottom) dialog.close(); } });
            dialog.addEventListener('close',function () { restoreDelivery(current); });
            current.delivery=dialog;current.deliveryBody=body;current.generated.push(dialog);
        }
        if (current.delivery.open) return;
        current.rootOverflow=document.documentElement.style.overflow;current.deliveryScroll={x:scrollX,y:scrollY};current.dockBeforeDelivery=document.body.classList.contains('lavka-product-dock-visible');
        document.documentElement.style.overflow='hidden';
        current.delivery.showModal();current.delivery.querySelector('.lavka-product-delivery-shell').shadowRoot.querySelector('button').focus({preventScroll:true});loadDelivery(current);dockVisibility();
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
        var slot=document.createElement('div');slot.className='lavka-product-nav-slot';heading.before(slot);slot.appendChild(nav);
        state.nav=nav;state.navSlot=slot;state.generated.push(slot);
        var active=tabs.querySelector('li.active>a');markSection(active && active.getAttribute('href')==='#tab-review' ? 'reviews' : 'about');
        $(tabs).on('click.lavkaMobileProductNav','a[data-toggle="tab"]',nativeSection);
    }
    function dockVisibility() {
        if (!state || !state.mounted) return;
        var offset=topOffset();
        root.style.setProperty('--lavka-product-top',offset+'px');
        if (state.nav) state.nav.classList.toggle('lavka-product-nav--fixed',state.navSlot.getBoundingClientRect().top<=offset);
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
        var meta=document.createElement('div'), facts=document.createElement('div');meta.className='lavka-product-meta';facts.className='lavka-product-facts';state.generated.push(meta);
        move(block.querySelector('.product-page__rating'),meta);meta.appendChild(facts);
        move(block.querySelector('.product-data .stock'),facts);move(block.querySelector('.product-data .manufacturer'),facts);
        state.emptyData=Array.from(block.querySelectorAll('.product-data')).filter(function (data) { return !data.querySelector('.product-data__item'); });
        state.emptyData.forEach(function (data) { data.classList.add('lavka-product-empty-data'); });
        var panel=document.createElement('div');panel.className='lavka-product-buy';block.prepend(panel);state.panel=panel;state.generated.push(panel);
        block.insertBefore(meta,panel);
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
        var caption=document.createElement('div'), captionText=document.createElement('span'), captionEur=document.createElement('span');caption.className='lavka-product-pack-caption';captionEur.className='lavka-product-caption-eur';caption.hidden=true;caption.appendChild(captionText);caption.appendChild(captionEur);totals.appendChild(caption);state.caption=caption;state.captionText=captionText;state.captionEur=captionEur;
        state.eurNative=block.querySelector('.product-page__eur-price');move(state.eurNative,panel);if (state.eurNative) state.eurNative.classList.add('lavka-product-native-eur');
        move(block.querySelector('.product-page__discount'),panel);move(block.querySelector('.price-tax'),panel);
        move(cart,line);move(block.querySelector('.product-page__minimum'),panel);
        setupNavigation();
        state.topBefore=root.style.getPropertyValue('--lavka-product-top');
        state.image=page.querySelector('.product-page__image-main');
        state.reviewEnabled=state.image && state.image.lavkaReviewSummary ? state.image.lavkaReviewSummary.enabled : null;
        state.rating=meta.querySelector('.product-page__rating');
        state.ratingLink=meta.querySelector('.product-page__rating-a');
        var reviewTab=state.tabs && state.tabs.querySelector('a[href="#tab-review"]');
        if (reviewTab) {
            var emptyReview=document.createElement('button');emptyReview.type='button';emptyReview.className='lavka-product-review-empty';emptyReview.textContent=sections.writeReview;emptyReview.setAttribute('aria-controls','tab-review');
            emptyReview.addEventListener('click',function () { $(reviewTab).trigger('click'); });meta.appendChild(emptyReview);state.emptyReview=emptyReview;
        }
        if (state.ratingLink) {
            attribute(state.ratingLink,'data-lavka-review-count','');attribute(state.ratingLink,'aria-label',state.ratingLink.textContent.trim());
            state.reviewObserver=new MutationObserver(reviewCount);state.reviewObserver.observe(state.rating,{subtree:true,childList:true,characterData:true,attributes:true,attributeFilter:['hidden']});
        }
        reviewCount();
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
        var totals=document.createElement('div'), dockPrice=document.createElement('div'), actions=document.createElement('div'), dockButton=document.createElement('button');
        dockPrice.className='lavka-product-dock__price';dockButton.type='button';actions.className='lavka-product-dock__actions';state.dockActions=[];
        [['.product-page__wishlist-btn','heart','M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z'],['.product-page__compare-btn','compare','M6 4v16M18 4v16M3 9l3-5 3 5H3ZM15 15l3 5 3-5h-6Z']].forEach(function (item) {
            var source=page.querySelector(item[0]);if (!source || source.classList.contains('hidden')) return;
            var button=document.createElement('button');button.type='button';button.className='lavka-product-dock__action';button.dataset.action=item[1];
            button.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="'+item[2]+'"/></svg>';
            button.addEventListener('click',function () { source.click(); });actions.appendChild(button);state.dockActions.push({button:button,source:source});
        });
        dockButton.className='lavka-product-dock__buy';totals.className='lavka-product-dock__totals';
        totals.appendChild(dockPrice);dock.appendChild(totals);dock.appendChild(actions);dock.appendChild(dockButton);document.body.appendChild(dock);
        state.dock=dock;state.dockPrice=dockPrice;state.dockButton=dockButton;state.generated.push(dock);
        dockButton.addEventListener('click',function () { if (!state.pending && !buy.disabled && !buy.classList.contains('disabled')) buy.click(); });
        state.observer=new MutationObserver(sync);state.observer.observe(price,{subtree:true,childList:true,characterData:true});state.observer.observe(buy,{subtree:true,childList:true,characterData:true,attributes:true,attributeFilter:['disabled','class']});
        state.dockActions.forEach(function (item) { state.observer.observe(item.source,{attributes:true,attributeFilter:['class','title','disabled']}); });
        root.classList.add('lavka-product-mobile');setupPacks();sync();dockVisibility();
        if (window.IntersectionObserver) { state.intersection=new IntersectionObserver(dockVisibility);state.intersection.observe(buy); }
    }
    function unmount() {
        if (!state || !state.mounted) return;
        state.mounted=false;state.observer.disconnect();if (state.intersection) state.intersection.disconnect();
        if (state.reviewObserver) state.reviewObserver.disconnect();
        if (state.rating) state.rating.classList.remove('lavka-product-rating-empty');
        if (state.eurNative) state.eurNative.classList.remove('lavka-product-native-eur');
        if (state.chromeFrame) cancelAnimationFrame(state.chromeFrame);
        if (state.tabs) { $(state.tabs).off('.lavkaMobileProductNav');state.tabs.classList.remove('lavka-product-only-primary'); }
        (state.sourceItems || []).forEach(function (item) { item.node.classList.remove('lavka-product-primary-source');if (!item.hadClass && !item.node.className) item.node.removeAttribute('class'); });
        if (state.delivery && state.delivery.open) { state.delivery.close();restoreDelivery(state); }
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
    document.addEventListener('lavka:review-summary',function (event) {
        if (!state || !state.mounted || event.target!==state.image) return;
        state.reviewEnabled=event.detail && event.detail.enabled;
        setTimeout(reviewCount,0);
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
        document.addEventListener('transitionrun',followChrome);document.addEventListener('transitionend',followChrome);
        document.addEventListener('focusin',dockVisibility);document.addEventListener('focusout',function () { setTimeout(dockVisibility,0); });
    });
})(jQuery);
