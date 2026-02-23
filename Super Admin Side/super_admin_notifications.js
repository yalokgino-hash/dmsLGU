/**
 * Super Admin real-time notifications: poll api_notifications.php and update badge + dropdown.
 * Expects #notif-count and #notif-dropdown on the page.
 */
(function() {
    function escapeHtml(s) {
        var div = document.createElement('div');
        div.textContent = s == null ? '' : s;
        return div.innerHTML;
    }
    function updateNotifUI(data) {
        var count = parseInt(data.count, 10) || 0;
        var items = data.items || [];
        var notifCountEl = document.getElementById('notif-count');
        var notifDropdown = document.getElementById('notif-dropdown');
        if (notifCountEl) {
            notifCountEl.textContent = count;
            notifCountEl.style.display = count > 0 ? '' : 'none';
        }
        if (notifDropdown) {
            if (items.length === 0) {
                notifDropdown.innerHTML = '<div class="notif-item">No new notifications</div>';
            } else {
                var html = '';
                items.forEach(function(ni) {
                    var href = (ni.documentId && ni.documentId.length > 0) ? ('documents.php?highlight=' + encodeURIComponent(ni.documentId)) : 'documents.php';
                    html += '<a href="' + href + '" class="notif-item notif-item-link">' + escapeHtml(ni.documentTitle || 'Document') + ' — from ' + escapeHtml(ni.sentByUserName || '') + ' (' + escapeHtml(ni.sentAtFormatted || '') + ')</a>';
                });
                notifDropdown.innerHTML = html;
            }
        }
    }
    function fetchNotifications() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'api_notifications.php', true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) return;
            try {
                var data = JSON.parse(xhr.responseText || '{"count":0,"items":[]}');
                updateNotifUI(data);
            } catch (e) {}
        };
        xhr.send();
    }
    fetchNotifications();
    setInterval(fetchNotifications, 8000);
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'visible') fetchNotifications();
    });
})();
