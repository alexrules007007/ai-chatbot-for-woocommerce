/* AI Chatbot for WooCommerce - Admin JS */

(function($) {
    'use strict';

    if (typeof $ === 'undefined') return;
    if (typeof window.wcaiIndexData !== 'undefined') {
        var indexedEl = document.getElementById('wcai-indexed');
        var totalEl   = document.getElementById('wcai-total');
        var statusEl  = document.getElementById('wcai-status-text');
        var updating  = false;
        var interval  = null;

        function updateNumbers() {
            if (updating) return;
            updating = true;

            var xhr = new XMLHttpRequest();
            xhr.open('GET',
                window.wcaiIndexData.ajaxUrl + '?action=wcai_index_progress&_ajax_nonce=' +
                window.wcaiIndexData.nonce, true);

            xhr.onload = function() {
                updating = false;
                if (xhr.status !== 200) return;
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (!res.success) return;
                    var d = res.data;

                    if (indexedEl && indexedEl.textContent !== String(d.indexed)) indexedEl.textContent = d.indexed;
                    if (totalEl && totalEl.textContent !== String(d.total)) totalEl.textContent = d.total;

                    var newStatus = '';
                    if (d.status === 'running') newStatus = '⏳ Running... (' + d.percent + '%)';
                    else if (d.status === 'done') {
                        newStatus = '✅ Done!';
                        if (interval) { clearInterval(interval); interval = null; }
                    } else newStatus = '⚪ Idle';

                    if (statusEl && statusEl.textContent !== newStatus) statusEl.textContent = newStatus;
                } catch(e) {}
            };
            xhr.onerror = function() { updating = false; };
            xhr.send();
        }

        if (window.wcaiIndexData.initialStatus === 'running') {
            updateNumbers();
            interval = setInterval(updateNumbers, 1000);
        }

        document.querySelectorAll('form').forEach(function(form) {
            form.addEventListener('submit', function() {
                setTimeout(function() {
                    updateNumbers();
                    if (interval) clearInterval(interval);
                    interval = setInterval(updateNumbers, 1000);
                }, 500);
            });
        });
    }

    window.wcaiCopyReport = function() {
        var textarea = document.getElementById('diagnostic-report');
        if (!textarea) return;
        textarea.style.display = 'block';
        textarea.select();
        document.execCommand('copy');
        textarea.style.display = 'none';
        var msg = document.getElementById('copy-msg');
        if (msg) {
            msg.style.display = 'inline';
            setTimeout(function() { msg.style.display = 'none'; }, 2000);
        }
    };

    if (typeof window.wcaiSessionData !== 'undefined') {
        function addSessionColumn() {
            var table = document.querySelector('.wp-list-table.widefat.fixed.striped');
            if (!table || table.dataset.sessionAdded === '1') return;
            table.dataset.sessionAdded = '1';

            var thead = table.querySelector('thead tr');
            if (thead) {
                var headerCells = thead.querySelectorAll('th');
                if (headerCells.length >= 2) {
                    var newTh = document.createElement('th');
                    newTh.textContent = 'Session';
                    newTh.width = '90';
                    headerCells[1].insertAdjacentElement('afterend', newTh);
                }
            }

            var rows = table.querySelectorAll('tbody tr');
            rows.forEach(function(row) {
                var cells = row.querySelectorAll('td');
                if (cells.length < 2) return;

                var idCell = cells[0];
                var logId = parseInt(idCell.textContent.trim());
                var sessions = window.wcaiSessionData.sessions;
                var logsUrl = window.wcaiSessionData.logsUrl;

                if (!logId || !sessions[logId]) {
                    var emptyTd = document.createElement('td');
                    emptyTd.innerHTML = '<span style="color:#9ca3af;font-size:11px;">—</span>';
                    cells[1].insertAdjacentElement('afterend', emptyTd);
                    return;
                }

                var sid = sessions[logId];
                var td = document.createElement('td');
                td.innerHTML = '<a href="' + logsUrl + '&session=' + encodeURIComponent(sid) + '" ' +
                               'style="font-family:monospace;font-size:11px;color:#4F46E5;text-decoration:none;" ' +
                               'title="View all queries from this user">' + sid.substring(0, 8) + '…</a>';
                cells[1].insertAdjacentElement('afterend', td);
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', addSessionColumn);
        } else {
            addSessionColumn();
        }
        setTimeout(addSessionColumn, 500);
    }

})(jQuery);