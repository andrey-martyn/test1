(function ($) {
    'use strict';
    $(document).on('change.lavkaTags', '.lavka-tag-controls input[data-tag]', function () {
        var input = this, widget = input.closest('.lavka-tag-controls');
        var prior = !input.checked, desired = input.checked, code = input.dataset.tag;
        var status = widget.querySelector('.lavka-tag-status');
        if (input.dataset.pending === '1') { input.checked = prior; return; }
        input.dataset.pending = '1'; input.disabled = true;
        status.textContent = widget.dataset.saving; status.classList.remove('text-danger');
        $.ajax({url: widget.dataset.url, type: 'POST', dataType: 'json', data: {
            product_id: widget.dataset.productId, code: code, enabled: desired ? 1 : 0, revision: input.dataset.revision
        }}).done(function (json) {
            if (!json.success || !json.state || !json.state[code]) { failed(json.error); return; }
            input.checked = !!json.state[code].enabled;
            input.dataset.revision = json.state[code].revision;
            status.textContent = widget.dataset.saved;
            // If the same product widget occurs twice, keep both copies synchronized.
            $('.lavka-tag-controls').filter(function () { return this.dataset.productId === widget.dataset.productId; }).find('input[data-tag="' + code + '"]').each(function () {
                this.checked = input.checked; this.dataset.revision = input.dataset.revision;
                var label=this.closest('label');
                if (label && !label.classList.contains('lavka-tag-muted')) label.removeAttribute('title');
            });
        }).fail(function (xhr) {
            failed(xhr.responseJSON && xhr.responseJSON.error);
        }).always(function () { input.disabled = false; delete input.dataset.pending; });
        function failed(error) {
            input.checked = prior;
            status.textContent = error === 'TAG_CONFLICT' ? widget.dataset.conflict : widget.dataset.error;
            status.classList.add('text-danger');
        }
    });
})(jQuery);
