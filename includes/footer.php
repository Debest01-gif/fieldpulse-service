        </div> <!-- /.content-wrapper -->
    </main> <!-- /.app-main -->
</div> <!-- /.app-container -->

<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
<script src="<?= BASE_URL ?>/assets/js/signature.js"></script>
<script>
    // Initialize Lucide icons on any freshly rendered content
    if (window.lucide) {
        lucide.createIcons();
    }

    // ── Notification Bell Toggle ─────────────────────────────────
    function toggleNotif(e) {
        e.stopPropagation();
        var dd  = document.getElementById('notifDropdown');
        var dot = document.querySelector('#notifBellBtn .badge-dot');
        if (!dd) return;

        var isOpening = (dd.style.display === 'none' || dd.style.display === '');
        dd.style.display = isOpening ? 'block' : 'none';

        if (isOpening) {
            // Hide the red dot immediately
            if (dot) dot.style.display = 'none';

            // Mark all notifications as read in the database (fire-and-forget)
            fetch('<?= BASE_URL ?>/ajax/mark_notifications_read.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).catch(function(){});

            // Also clear the "X new" badge text in the dropdown header
            var badge = document.querySelector('#notifDropdown .badge-info');
            if (badge) badge.textContent = '0 new';
        }
    }

    // Close notification dropdown when clicking anywhere outside
    document.addEventListener('click', function(e) {
        var wrapper = document.getElementById('notifWrapper');
        var dd = document.getElementById('notifDropdown');
        if (dd && wrapper && !wrapper.contains(e.target)) {
            dd.style.display = 'none';
        }
    });

</script>
</body>
</html>
