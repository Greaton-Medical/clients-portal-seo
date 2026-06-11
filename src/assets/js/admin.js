(function () {
  'use strict';

  var adminCsrf = (document.querySelector('meta[name="admin-csrf"]') || {}).content || '';

  // ── Active/inactive toggle switches ─────────────────────────────────────────
  document.querySelectorAll('.js-toggle').forEach(function (input) {
    input.addEventListener('change', async function () {
      var id   = this.dataset.id;
      var type = this.dataset.type; // 'client' or 'user'
      var originalState = !this.checked; // what it was before

      try {
        var res = await fetch('/admin/api/' + type + '-toggle.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'id=' + encodeURIComponent(id) + '&csrf_token=' + encodeURIComponent(adminCsrf)
        });
        var json = await res.json();
        if (!json.ok) {
          this.checked = originalState; // revert
          alert(json.error || 'Toggle failed.');
        }
      } catch (err) {
        this.checked = originalState;
        alert('Network error — toggle reverted.');
      }
    });
  });

}());
