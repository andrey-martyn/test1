/* Published review summaries share the existing product metadata request. */
(function ($) {
    'use strict';
    var states=new WeakMap(), active=new Set(), mobile=matchMedia('(max-width:519px)');
    var language=(document.documentElement.lang || 'uk').toLowerCase();
    var text=language.indexOf('ru')===0 ? {
        reviews:'Отзывы',score:'Оценка',of:'из',average:'Средняя оценка'
    } : language.indexOf('en')===0 ? {
        reviews:'Reviews',score:'Rating',of:'of',average:'Average rating'
    } : {reviews:'Відгуки',score:'Оцінка',of:'з',average:'Середня оцінка'};
    var resize=window.ResizeObserver ? new ResizeObserver(function (entries) {
        entries.forEach(function (entry) {
            var state=states.get(entry.target);
            if (state && state.width!==entry.contentRect.width) { state.width=entry.contentRect.width; schedule(state); }
        });
    }) : null;

    function score(value) { return value.toFixed(1).replace('.',language.indexOf('en')===0 ? '.' : ','); }
    function chat() {
        var node=document.createElementNS('http://www.w3.org/2000/svg','svg');
        node.setAttribute('viewBox','0 0 24 24'); node.setAttribute('aria-hidden','true'); node.setAttribute('focusable','false');
        node.classList.add('lavka-review-chat');
        node.innerHTML='<path d="M5 4h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-8l-5 4v-4H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z"/><path d="M7 9h10M7 13h7"/>';
        return node;
    }
    function stars(value) {
        var node=document.createElement('span'), fill=document.createElement('span'), paths='';
        node.className='lavka-review-stars'; node.setAttribute('role','img');
        node.setAttribute('aria-label',text.score+' '+score(value)+' '+text.of+' 5');
        for (var i=0;i<5;i++) paths+='<path transform="translate('+i*20+' 0)" d="m10 1.5 2.6 5.3 5.9.9-4.3 4.1 1 5.9-5.2-2.8-5.2 2.8 1-5.9-4.3-4.1 5.9-.9Z"/>';
        var svg='<svg viewBox="0 0 100 20" aria-hidden="true" focusable="false">'+paths+'</svg>';
        node.innerHTML=svg; fill.className='lavka-review-stars__fill'; fill.style.width=(value*20)+'%'; fill.innerHTML=svg;
        node.appendChild(fill); return node;
    }
    function summary(detail) {
        var data=detail && detail.summary;
        if (!detail || !detail.enabled || !data || !Number.isFinite(Number(data.total)) || !Number.isFinite(Number(data.rating))) return null;
        return {total:Math.max(0,Math.floor(Number(data.total))),rating:Math.max(0,Math.min(5,Number(data.rating)))};
    }
    function productLink(card) {
        var link=card.querySelector('.product-thumb__name[href],.product-thumb__image>a[href]'), url;
        try {
            url=new URL(link ? link.href : '',document.baseURI);
            if (!link || !/^https?:$/.test(url.protocol) || url.origin!==new URL(document.baseURI).origin) {
                var field=card.querySelector('.product-thumb__add-to-cart[data-pid]');
                if (!field || !/^[1-9][0-9]*$/.test(field.dataset.pid || '')) return null;
                url=new URL('index.php?route=product/product&product_id='+field.dataset.pid,document.baseURI);
            }
            url.hash='tab-review'; return url.href;
        } catch (_) { return null; }
    }
    function measure(element) {
        if (!element || element.hidden) return 0;
        var clone=element.cloneNode(true);
        clone.removeAttribute('id'); clone.setAttribute('aria-hidden','true');
        clone.style.cssText='position:fixed!important;left:-10000px!important;top:0!important;width:max-content!important;max-width:none!important;min-width:0!important;white-space:nowrap!important;display:block!important;visibility:hidden!important;margin:0!important;padding:0!important';
        clone.querySelectorAll('.price-old,.price-new').forEach(function (part) { part.style.display='block'; });
        element.closest('.product-thumb').appendChild(clone);
        var width=clone.getBoundingClientRect().width;
        clone.remove(); return Math.ceil(width);
    }
    function layout(state) {
        if (!state.card.isConnected) { destroy(state); return; }
        if (!mobile.matches) { state.card.classList.remove('lavka-reviews-inline','lavka-reviews-below'); return; }
        var style=getComputedStyle(state.card), width=state.card.clientWidth-parseFloat(style.paddingLeft)-parseFloat(style.paddingRight);
        var price=Math.max(measure(state.price),measure(state.caption));
        var inline=width>=price+36+Math.ceil(state.link.getBoundingClientRect().width)+8;
        state.card.classList.toggle('lavka-reviews-inline',inline);
        state.card.classList.toggle('lavka-reviews-below',!inline);
    }
    function schedule(state) {
        if (state.frame) return;
        state.frame=requestAnimationFrame(function () { state.frame=0; layout(state); });
    }
    function destroy(state) {
        state.observer.disconnect(); if (resize) resize.unobserve(state.card);
        if (state.frame) cancelAnimationFrame(state.frame);
        state.link.remove(); state.card.classList.remove('lavka-has-reviews','lavka-reviews-inline','lavka-reviews-below');
        active.delete(state); states.delete(state.card);
    }
    function cardSummary(card,data) {
        var state=states.get(card);
        var nativeRating=card.querySelector('.product-thumb__rating');
        if (nativeRating) nativeRating.hidden=!data || !data.total;
        if (!data || !data.total) { if (state) destroy(state); return; }
        if (!card.classList.contains('lavka-mobile-card')) return;
        var href=productLink(card);
        if (!href) return;
        if (!state) {
            var link=document.createElement('a'); link.className='lavka-card-reviews';
            state={card:card,link:link,price:card.querySelector('.product-thumb__price'),caption:card.querySelector('.lavka-pack-caption')};
            if (!state.price) return;
            state.observer=new MutationObserver(function () { schedule(state); });
            [state.price,state.caption].forEach(function (element) {
                if (element) state.observer.observe(element,{subtree:true,childList:true,characterData:true,attributes:true,attributeFilter:['hidden']});
            });
            card.appendChild(link); card.classList.add('lavka-has-reviews'); states.set(card,state); active.add(state);
            if (resize) resize.observe(card);
        }
        var anchor=state.link, line=document.createElement('span');
        while (anchor.firstChild) anchor.removeChild(anchor.firstChild);
        anchor.href=href;
        anchor.appendChild(stars(data.rating)); line.className='lavka-card-reviews__count'; line.appendChild(chat());
        var count=document.createElement('span'); count.textContent=String(data.total); line.appendChild(count); anchor.appendChild(line);
        anchor.setAttribute('aria-label',text.score+' '+score(data.rating)+' '+text.of+' 5; '+text.reviews+': '+data.total);
        anchor.title=anchor.getAttribute('aria-label'); schedule(state);
    }
    function replaceStars(block,data) {
        Array.from(block.children).forEach(function (child) {
            if (child.classList.contains('fa-star') || child.classList.contains('lavka-review-stars')) child.remove();
        });
        block.prepend(stars(data.total ? data.rating : 0));
    }
    function mainSummary(image,data) {
        var page=image.closest('.product-page'); if (!page) return;
        page.querySelectorAll('.product-page__rating,.review-info__rating').forEach(function (block) {
            block.hidden=!data || !data.total; if (data && data.total) replaceStars(block,data);
        });
        if (!data) return;
        var anchor=page.querySelector('.product-page__rating-a');
        if (anchor) anchor.textContent=text.reviews+': '+data.total;
        var scoreBlock=page.querySelector('.review-info__score'), total=page.querySelector('.review-info__total');
        if (scoreBlock) scoreBlock.textContent=text.average+': '+score(data.rating);
        if (total) total.textContent=text.reviews+': '+data.total;
    }
    function receive(image,detail) {
        var data=summary(detail), card=image.closest('.product-thumb');
        if (card) cardSummary(card,data);
        else if (image.matches('.product-page__image-main')) mainSummary(image,data);
    }
    function openTarget() {
        if (location.hash!=='#form-review' && location.hash!=='#tab-review') return;
        var pane=document.getElementById('tab-review'), tab=document.querySelector('.nav-tabs a[href="#tab-review"]');
        if (!pane || !tab) return;
        if (!pane.classList.contains('active')) $(tab).trigger('click');
        if (location.hash==='#form-review') {
            var form=document.getElementById('form-review'); if (!form) return;
            var scroll=function () {
                if (typeof window.uniScrollTo==='function') window.uniScrollTo('#form-review');
                else form.scrollIntoView({block:'start'});
            };
            if ($.fn.collapse && !form.classList.contains('in')) { $(form).one('shown.bs.collapse',scroll).collapse('show'); }
            else { form.classList.add('in'); form.setAttribute('aria-expanded','true'); scroll(); }
        }
    }
    document.addEventListener('lavka:review-summary',function (event) { receive(event.target,event.detail); });
    document.addEventListener('click',function (event) {
        var anchor=event.target.closest && event.target.closest('.lavka-card-reviews');
        if (!anchor || event.button!==0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        var url=new URL(anchor.href);
        if (url.origin===location.origin && url.pathname===location.pathname && url.search===location.search) {
            event.preventDefault(); if (url.hash===location.hash) openTarget(); else location.hash=url.hash;
        }
    });
    window.addEventListener('hashchange',openTarget);
    window.addEventListener('resize',function () { active.forEach(schedule); });
    $(function () {
        document.querySelectorAll('.product-thumb__image,.product-page__image-main').forEach(function (image) {
            if (image.lavkaReviewSummary) receive(image,image.lavkaReviewSummary);
        });
        if (document.fonts) document.fonts.ready.then(function () { active.forEach(schedule); });
        new MutationObserver(function (records) {
            records.forEach(function (record) { record.removedNodes.forEach(function (node) {
                if (node.nodeType!==1 || node.isConnected) return;
                var cards=node.matches('.product-thumb') ? [node] : node.querySelectorAll('.product-thumb');
                cards.forEach(function (card) { var state=states.get(card); if (state) destroy(state); });
            }); });
        }).observe(document.body,{subtree:true,childList:true});
        setTimeout(openTarget,0);
    });
})(jQuery);
