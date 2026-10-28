/* Fresh label fragments keep page-cache HTML from retaining stale assignments. */
(function ($) {
    'use strict';
    var pictures = new Map(), waiting = new Set(), inFlight = new Set(), again = new Set();
    var observed = new WeakSet(), timer;
    var managed = ['new','bestseller','sku','upc','ean','jan','isbn','mpn','lavka-new','lavka-hit','lavka-fresh','lavka-brand','lavka-exclusive'];
    var resize = window.ResizeObserver ? new ResizeObserver(function (entries) {
        entries.forEach(function (entry) {
            var card = entry.target.closest('.lavka-mobile-card');
            if (card) fit(card);
        });
    }) : null;

    function fit(card) {
        var image = card.querySelector('.product-thumb__image');
        if (!image) return;
        if (!matchMedia('(max-width:519px)').matches) { image.style.minHeight = ''; image.style.height = ''; image.classList.remove('lavka-tags-expanded'); return; }
        var tags = image.querySelector('.sticker'), packs = card.querySelector('.product-thumb__option');
        var height = (tags ? tags.getBoundingClientRect().height : 0) + (packs ? packs.getBoundingClientRect().height : 0) + 22;
        var minimum = height > image.clientWidth ? Math.ceil(height) + 'px' : '';
        if (image.style.minHeight !== minimum) image.style.minHeight = minimum;
        if (image.style.height !== minimum) image.style.height = minimum;
        image.classList.toggle('lavka-tags-expanded',!!minimum);
        if (resize) [tags,packs].forEach(function (element) { if (element && !observed.has(element)) { observed.add(element); resize.observe(element); } });
    }

    function identify(image) {
        var card = image.closest('.product-thumb'), field, id;
        field = image.querySelector('.sticker[data-lavka-product-id]');
        id = field && field.dataset.lavkaProductId;
        if (/^[1-9][0-9]*$/.test(id || '')) return Number(id);
        if (card) {
            field = card.querySelector('.product-thumb__add-to-cart[data-pid]');
            id = field && field.getAttribute('data-pid');
        } else {
            field = (image.closest('.product-page') || document).querySelector('input[name="product_id"]');
            id = field && field.value;
        }
        return /^[1-9][0-9]*$/.test(id || '') ? Number(id) : null;
    }

    function scan(node) {
        var images = [];
        if (node.matches && node.matches('.product-thumb__image,.product-page__image-main')) images.push(node);
        if (node.querySelectorAll) images = images.concat(Array.from(node.querySelectorAll('.product-thumb__image,.product-page__image-main')));
        images.forEach(function (image) {
            var id = identify(image);
            if (!id) return;
            if (!pictures.has(id)) pictures.set(id,new Set());
            if (pictures.get(id).has(image)) return;
            pictures.get(id).add(image); waiting.add(id);
            var card = image.closest('.lavka-mobile-card');
            if (card) fit(card);
            if (resize && !observed.has(image)) { observed.add(image); resize.observe(image); }
        });
        schedule();
    }

    function render(image, stickers) {
        var box = image.querySelector('.sticker');
        if (!box && stickers.length) { box = document.createElement('div'); box.className='sticker'; image.prepend(box); }
        if (!box) return;
        Array.from(box.children).forEach(function (item) {
            if (managed.some(function (name) { return item.classList.contains(name); })) item.remove();
        });
        var fragment = document.createDocumentFragment();
        stickers.forEach(function (tag) {
            if (!managed.includes(tag.name)) return;
            var item = document.createElement('div');
            item.className='sticker__item '+tag.name;
            item.textContent=[tag.text,tag.value,tag.text_after].filter(function (value) { return value !== '' && value != null; }).join(' ');
            if (/^#[0-9a-f]{6}$/i.test(tag.background || '')) { item.style.backgroundColor=tag.background; item.style.setProperty('--lavka-sticker-bg',tag.background); }
            if (/^#[0-9a-f]{6}$/i.test(tag.color || '')) item.style.color=tag.color;
            fragment.appendChild(item);
        });
        box.prepend(fragment);
        box.hidden = !box.children.length;
        var card = image.closest('.lavka-mobile-card');
        if (card) fit(card);
    }

    function schedule() { clearTimeout(timer); timer=setTimeout(flush,40); }
    function flush() {
        var ids = Array.from(waiting).filter(function (id) {
            waiting.delete(id);
            if (inFlight.has(id)) { again.add(id); return false; }
            return true;
        });
        while (ids.length) {
            var chunk=ids.splice(0,60);
            fetchChunk(chunk);
        }
    }
    function fetchChunk(ids) {
        ids.forEach(function (id) { inFlight.add(id); });
        $.ajax({url:new URL('index.php?route=extension/module/lavka_product_tags/info',document.baseURI).href,
            type:'POST',dataType:'json',cache:false,data:{product_ids:ids}}).done(function (json) {
            if (!json.products) return;
            ids.forEach(function (id) {
                if (!Array.isArray(json.products[id])) return;
                pictures.get(id).forEach(function (image) {
                    if (!image.isConnected) { pictures.get(id).delete(image); return; }
                    render(image,json.products[id]);
                    if (typeof json.review_enabled==='boolean') {
                        image.lavkaReviewSummary={enabled:json.review_enabled,summary:json.reviews && json.reviews[id]};
                        image.dispatchEvent(new CustomEvent('lavka:review-summary',{bubbles:true,detail:image.lavkaReviewSummary}));
                    }
                });
            });
        }).always(function () {
            ids.forEach(function (id) { inFlight.delete(id); if (again.delete(id)) waiting.add(id); });
            if (waiting.size) schedule();
        });
    }
    $(function () {
        setTimeout(function () {
            scan(document);
            new MutationObserver(function (records) {
                records.forEach(function (record) { record.addedNodes.forEach(function (node) {
                    if (node.nodeType===1 && (node.matches('.product-thumb,.product-thumb__image,.product-page__image-main') || node.querySelector('.product-thumb__image,.product-page__image-main'))) scan(node);
                }); });
            }).observe(document.body,{subtree:true,childList:true});
        },0);
        window.addEventListener('focus',function () { pictures.forEach(function (_,id) { waiting.add(id); }); schedule(); });
        window.addEventListener('resize',function () { document.querySelectorAll('.lavka-mobile-card').forEach(fit); });
    });
})(jQuery);
