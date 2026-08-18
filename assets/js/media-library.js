/*
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * Media Library row action: offload one attachment without leaving the page.
 */
(function ($) {
    'use strict';

    const cfg = window.isxsMedia || {};

    // Delegated: the list table can be re-rendered (sorting, paging) and the
    // handler has to survive it.
    $(document).on('click', '.isxs-ml-offload', function (e) {
        e.preventDefault();

        const $link = $(this);
        if ($link.hasClass('isxs-ml-busy')) { return; }

        const id = $link.data('id');
        const $row = $link.closest('tr');
        const $status = $row.find('.column-isxs_storage');
        const originalLabel = $link.text();

        $link.addClass('isxs-ml-busy').text(cfg.i18n.working);
        $row.find('.isxs-ml-result').remove();

        $.post(cfg.ajaxUrl, {
            action: 'isxs_offload_single',
            nonce: cfg.nonce,
            attachment_id: id
        })
            .done(function (res) {
                if (res && res.success) {
                    // The column markup is rendered server-side, so rather
                    // than rebuilding it here, show the outcome and let the
                    // next page load give the authoritative state.
                    $status.append(
                        $('<span class="isxs-ml-result isxs-ml-result-ok">').text(res.data.message)
                    );
                    $link.remove();
                    return;
                }
                fail((res && res.data && res.data.message) || cfg.i18n.error);
            })
            .fail(function () {
                fail(cfg.i18n.error);
            });

        function fail(message) {
            $link.removeClass('isxs-ml-busy').text(originalLabel);
            $status.append(
                $('<span class="isxs-ml-result isxs-ml-result-error">').text(message)
            );
        }
    });
}(jQuery));
