(function () {
  'use strict';

  // ── Conditional fields ──────────────────────────────────────────────────────
  const categorySelect = document.getElementById('contentCategory');
  const socialSelect   = document.getElementById('socialType');
  const conditionals   = document.querySelectorAll('.conditional');
  const form           = document.getElementById('requestForm');
  const submitBtn      = document.getElementById('submitBtn');

  if (!categorySelect) return;

  function updateConditionals() {
    const cat = categorySelect.value;
    const soc = socialSelect ? socialSelect.value : '';

    conditionals.forEach(function (el) {
      const parts = el.dataset.showWhen.split('=');
      const key   = parts[0];
      const val   = parts[1];
      let show    = (key === 'category' && cat === val) ||
                    (key === 'social' && cat === '1' && soc === val);

      el.classList.toggle('show', show);
      if (!show) {
        el.querySelectorAll('select, input, textarea').forEach(function (i) { i.value = ''; });
      }
    });
  }

  categorySelect.addEventListener('change', updateConditionals);
  if (socialSelect) socialSelect.addEventListener('change', updateConditionals);
  updateConditionals();

  // ── File upload drag-and-drop ───────────────────────────────────────────────
  const dropZone  = document.getElementById('drop-zone');
  const fileInput = document.getElementById('file-input');
  const fileList  = document.getElementById('file-list');

  if (dropZone && fileInput && fileList) {
    const MAX_FILES  = 10;
    const MAX_BYTES  = 25 * 1024 * 1024;
    const ALLOWED    = /\.(jpe?g|png|gif|webp|pdf|docx?|xlsx?|pptx?|mp4|mov|zip)$/i;

    let dt = new DataTransfer();

    function fmtBytes(b) {
      if (b < 1024)           return b + ' B';
      if (b < 1024 * 1024)    return (b / 1024).toFixed(1) + ' KB';
      return (b / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function renderFiles() {
      fileList.innerHTML = '';
      Array.from(dt.files).forEach(function (f, idx) {
        const item = document.createElement('div');
        item.className = 'file-item';
        item.innerHTML =
          '<span class="file-item-name" title="' + esc(f.name) + '">' + esc(f.name) + '</span>' +
          '<span class="file-item-size">' + fmtBytes(f.size) + '</span>' +
          '<button type="button" class="file-item-remove" data-idx="' + idx + '" aria-label="Remove">' +
            '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>' +
          '</button>';
        fileList.appendChild(item);
      });
      // Sync hidden input so files submit with form
      fileInput.files = dt.files;
    }

    function addFiles(incoming) {
      Array.from(incoming).forEach(function (f) {
        if (dt.files.length >= MAX_FILES) return;
        if (!ALLOWED.test(f.name)) { alert('"' + f.name + '" is not an allowed file type.'); return; }
        if (f.size > MAX_BYTES)    { alert('"' + f.name + '" exceeds the 25 MB limit.');     return; }
        dt.items.add(f);
      });
      renderFiles();
    }

    dropZone.addEventListener('click', function () { fileInput.click(); });
    dropZone.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') fileInput.click(); });

    dropZone.addEventListener('dragover', function (e) {
      e.preventDefault();
      dropZone.classList.add('drag-over');
    });
    dropZone.addEventListener('dragleave', function () { dropZone.classList.remove('drag-over'); });
    dropZone.addEventListener('drop', function (e) {
      e.preventDefault();
      dropZone.classList.remove('drag-over');
      addFiles(e.dataTransfer.files);
    });

    fileInput.addEventListener('change', function () {
      addFiles(this.files);
      this.value = ''; // allow re-selecting same file after removal
    });

    fileList.addEventListener('click', function (e) {
      const btn = e.target.closest('.file-item-remove');
      if (!btn) return;
      const idx   = parseInt(btn.dataset.idx, 10);
      const fresh = new DataTransfer();
      Array.from(dt.files).forEach(function (f, i) { if (i !== idx) fresh.items.add(f); });
      dt = fresh;
      renderFiles();
    });
  }

  // ── Versions (subitems) ─────────────────────────────────────────────────────
  const versionsList  = document.getElementById('versions-list');
  const addVersionBtn = document.getElementById('add-version-btn');
  const MAX_VERSIONS  = 10;

  if (versionsList && addVersionBtn) {
    function updateAddBtn() {
      addVersionBtn.disabled = versionsList.children.length >= MAX_VERSIONS;
    }

    addVersionBtn.addEventListener('click', function () {
      if (versionsList.children.length >= MAX_VERSIONS) return;

      const row = document.createElement('div');
      row.className = 'version-row';
      row.innerHTML =
        '<input type="text" name="versions[]" placeholder="e.g. 25–35 audience" maxlength="200">' +
        '<button type="button" class="version-remove" aria-label="Remove version">' +
          '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>' +
        '</button>';
      versionsList.appendChild(row);
      row.querySelector('input').focus();
      updateAddBtn();
    });

    versionsList.addEventListener('click', function (e) {
      const btn = e.target.closest('.version-remove');
      if (!btn) return;
      btn.closest('.version-row').remove();
      updateAddBtn();
    });
  }

  // ── Submit: disable button + show contextual label ──────────────────────────
  if (form && submitBtn) {
    form.addEventListener('submit', function () {
      const hasFiles = fileInput && fileInput.files && fileInput.files.length > 0;
      submitBtn.disabled    = true;
      submitBtn.textContent = hasFiles ? 'Uploading files…' : 'Submitting…';
    });
  }

  function esc(str) {
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

}());
