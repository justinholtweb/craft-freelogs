/**
 * Freelog - Log Viewer JavaScript
 */
(function () {
    'use strict';

    // Expand/collapse log entry rows
    document.querySelectorAll('.freelog-entries tr[data-expandable]').forEach(function (row) {
        row.addEventListener('click', function () {
            this.classList.toggle('expanded');
        });
    });

    // Tail mode polling
    var tailContainer = document.getElementById('freelog-tail-content');
    var tailToggle = document.getElementById('freelog-tail-toggle');
    var tailStatus = document.getElementById('freelog-tail-status');
    var tailInterval = null;

    if (tailToggle && tailContainer) {
        tailToggle.addEventListener('click', function () {
            if (tailInterval) {
                stopTail();
            } else {
                startTail();
            }
        });
    }

    function startTail() {
        var filename = tailContainer.dataset.file;
        if (!filename) return;

        tailInterval = setInterval(function () {
            fetchTail(filename);
        }, 2000);

        tailToggle.textContent = 'Stop';
        tailToggle.classList.remove('submit');
        tailToggle.classList.add('secondary');
        if (tailStatus) {
            tailStatus.classList.add('active');
        }

        // Fetch immediately
        fetchTail(filename);
    }

    function stopTail() {
        clearInterval(tailInterval);
        tailInterval = null;

        tailToggle.textContent = 'Start Auto-Refresh';
        tailToggle.classList.remove('secondary');
        tailToggle.classList.add('submit');
        if (tailStatus) {
            tailStatus.classList.remove('active');
        }
    }

    function fetchTail(filename) {
        var url = Craft.getCpUrl('freelog/tail', { file: filename });

        fetch(url, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data.content) {
                    tailContainer.textContent = data.content;
                    tailContainer.scrollTop = tailContainer.scrollHeight;
                }
            })
            .catch(function () {
                // Silently fail on network errors during polling
            });
    }

    // Clear log confirmation
    document.querySelectorAll('.freelog-clear-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!confirm('Are you sure you want to clear this log file? This cannot be undone.')) {
                e.preventDefault();
            }
        });
    });
})();
