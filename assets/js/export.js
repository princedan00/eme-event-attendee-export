/**
 * EME Event Attendee Export — list paging and selection.
 *
 * v1.0.0 — The export list shows events newest-first, 20 per page, with
 * a checkbox per event. This script pages the table rows, keeps the
 * select-all checkbox in step with the visible page, and updates the
 * download button with the number of events currently ticked.
 *
 * No event is ticked by default: the header checkbox starts unchecked
 * and the download button starts disabled at 0 workbooks (both are set
 * by updateSelectAll()/updateDownload() on load). "Select all events" /
 * "Deselect all events" tick or untick every row across all pages.
 *
 * Every row stays in the DOM inside the one download form, so ticking an
 * event on one page and turning to another never loses the selection.
 * Without JavaScript the pager stays hidden, all rows show, and the
 * download still works with whatever is ticked.
 */
(function ($) {
    'use strict';

    var $table = $('#emea-export-table');
    if (!$table.length) {
        return;
    }

    var perPage = parseInt($table.attr('data-per-page'), 10) || 20;
    var $rows = $table.find('tbody tr[data-emea-export-page]');
    var $pager = $('#emea-export-pager');
    var $pageNumbers = $('#emea-export-page-numbers');
    var $showing = $('#emea-export-showing');
    var $selectAll = $('#emea-export-select-all');
    var $download = $('#emea-export-download');
    var labelTemplate = $download.attr('data-label-template') || 'Download ZIP (%d workbooks)';
    var totalPages = Math.max(1, Math.ceil($rows.length / perPage));
    var currentPage = 1;

    function visibleRows() {
        return $rows.filter('[data-emea-export-page="' + currentPage + '"]');
    }

    function checkedCount() {
        return $rows.find('input[name="emea_event_ids[]"]:checked').length;
    }

    function updateDownload() {
        var count = checkedCount();
        $download.text(labelTemplate.replace('%d', String(count)));
        $download.prop('disabled', 0 === count);
    }

    function updateSelectAll() {
        var $visible = visibleRows().find('input[name="emea_event_ids[]"]');
        var checked = $visible.filter(':checked').length;
        $selectAll.prop('checked', checked > 0 && checked === $visible.length);
        $selectAll.prop('indeterminate', checked > 0 && checked < $visible.length);
    }

    function updateShowing() {
        var first = ($rows.length ? (currentPage - 1) * perPage + 1 : 0);
        var last = Math.min(currentPage * perPage, $rows.length);
        $showing.text('Showing ' + first + '–' + last + ' of ' + $rows.length + ' events');
    }

    function showPage(page) {
        currentPage = Math.min(Math.max(1, page), totalPages);
        $rows.each(function () {
            var rowPage = parseInt($(this).attr('data-emea-export-page'), 10);
            $(this).prop('hidden', rowPage !== currentPage);
        });
        $pageNumbers.find('button').each(function () {
            var buttonPage = parseInt($(this).attr('data-emea-export-page'), 10);
            $(this).toggleClass('button-primary', buttonPage === currentPage);
            $(this).attr('aria-current', buttonPage === currentPage ? 'page' : 'false');
        });
        $pager.find('[data-emea-export-nav="prev"]').prop('disabled', 1 === currentPage);
        $pager.find('[data-emea-export-nav="next"]').prop('disabled', currentPage === totalPages);
        updateShowing();
        updateSelectAll();
    }

    function buildPager() {
        var i;
        for (i = 1; i <= totalPages; i++) {
            $pageNumbers.append(
                $('<button>', { type: 'button', 'class': 'button', 'data-emea-export-page': i, text: String(i) })
            );
        }
        $pageNumbers.on('click', 'button', function () {
            showPage(parseInt($(this).attr('data-emea-export-page'), 10));
        });
        $pager.find('[data-emea-export-nav="prev"]').on('click', function () {
            showPage(currentPage - 1);
        });
        $pager.find('[data-emea-export-nav="next"]').on('click', function () {
            showPage(currentPage + 1);
        });
    }

    $selectAll.on('change', function () {
        visibleRows().find('input[name="emea_event_ids[]"]').prop('checked', $(this).prop('checked'));
        updateSelectAll();
        updateDownload();
    });

    $('#emea-export-select-page').on('click', function () {
        visibleRows().find('input[name="emea_event_ids[]"]').prop('checked', true);
        updateSelectAll();
        updateDownload();
    });

    $('#emea-export-deselect-page').on('click', function () {
        visibleRows().find('input[name="emea_event_ids[]"]').prop('checked', false);
        updateSelectAll();
        updateDownload();
    });

    // All pages, not just the visible one: every row stays in the DOM,
    // so ticks on hidden pages count and survive turning the page.
    $('#emea-export-select-all-events').on('click', function () {
        $rows.find('input[name="emea_event_ids[]"]').prop('checked', true);
        updateSelectAll();
        updateDownload();
    });

    $('#emea-export-deselect-all-events').on('click', function () {
        $rows.find('input[name="emea_event_ids[]"]').prop('checked', false);
        updateSelectAll();
        updateDownload();
    });

    $rows.on('change', 'input[name="emea_event_ids[]"]', function () {
        updateSelectAll();
        updateDownload();
    });

    if (totalPages > 1) {
        buildPager();
        $pager.prop('hidden', false);
    }
    showPage(1);
    updateDownload();
})(jQuery);
