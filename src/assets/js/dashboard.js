(function () {
  'use strict';

  var table      = document.getElementById('submissions-table');
  var chips      = document.getElementById('filter-chips');
  var searchInput = document.getElementById('search-input');
  var noResults  = document.getElementById('no-results');

  if (!table) return;

  var rows = Array.from(table.querySelectorAll('tbody tr'));
  var activeFilter = 'all';
  var searchTerm   = '';
  var searchTimer  = null;

  var emptyMessages = {
    client_review: {
      h: 'All caught up!',
      p: 'No tasks waiting for your review right now. We\'ll notify you when something needs your attention.'
    },
    approved: {
      h: 'No approved tasks yet',
      p: 'Tasks you\'ve approved will appear here.'
    },
    in_progress: {
      h: 'Nothing in progress',
      p: 'All your tasks have been completed or are awaiting review.'
    },
    _default: {
      h: 'No tasks match your filter.',
      p: ''
    }
  };

  // ── Filter logic ────────────────────────────────────────────────────────────
  function applyFilters() {
    var visible = 0;
    rows.forEach(function (row) {
      var statusMatch = activeFilter === 'all' || row.dataset.status === activeFilter;
      var nameMatch   = searchTerm === '' || (row.dataset.name || '').indexOf(searchTerm) !== -1 || (row.dataset.desc || '').indexOf(searchTerm) !== -1;
      var show = statusMatch && nameMatch;
      row.classList.toggle('hidden', !show);
      if (show) visible++;
    });
    if (noResults) {
      if (visible === 0) {
        var msg = emptyMessages[activeFilter] || emptyMessages._default;
        var h3 = noResults.querySelector('h3');
        var p  = noResults.querySelector('p');
        if (h3) h3.textContent = msg.h;
        if (p)  p.textContent  = msg.p;
        noResults.style.display = 'block';
      } else {
        noResults.style.display = 'none';
      }
    }
  }

  // ── Row click (whole row navigates; buttons use stopPropagation) ──────────
  table.querySelectorAll('tbody tr[data-task-url]').forEach(function (tr) {
    tr.addEventListener('click', function () {
      window.location.href = tr.dataset.taskUrl;
    });
  });

  // ── Filter chips ─────────────────────────────────────────────────────────────
  function activateChip(filter) {
    activeFilter = filter;
    if (chips) {
      chips.querySelectorAll('.chip').forEach(function (c) {
        c.classList.toggle('active', c.dataset.filter === filter);
      });
    }
    // Sync status summary cards
    document.querySelectorAll('.js-chip-trigger').forEach(function (card) {
      card.classList.toggle('active', card.dataset.filter === filter);
    });
    applyFilters();
  }

  if (chips) {
    chips.addEventListener('click', function (e) {
      var chip = e.target.closest('.chip');
      if (chip) activateChip(chip.dataset.filter);
    });
  }

  // Clicking a status summary card also filters
  document.querySelectorAll('.js-chip-trigger').forEach(function (card) {
    card.addEventListener('click', function () {
      var filter = card.dataset.filter;
      // Toggle: click active card → reset to All
      activateChip(activeFilter === filter ? 'all' : filter);
    });
  });

  // ── Search (debounced 200 ms) ────────────────────────────────────────────────
  if (searchInput) {
    searchInput.addEventListener('input', function () {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () {
        searchTerm = searchInput.value.trim().toLowerCase();
        applyFilters();
      }, 200);
    });
  }

  // ── Auto-refresh every 5 min when tab is visible ─────────────────────────────
  var REFRESH_MS = 5 * 60 * 1000;
  var refreshTimer = null;

  function scheduleRefresh() {
    clearTimeout(refreshTimer);
    refreshTimer = setTimeout(function () {
      if (document.visibilityState === 'visible') {
        window.location.href = window.location.pathname + '?refresh=1';
      } else {
        // Tab hidden — reschedule; will trigger on next visibility change
        scheduleRefresh();
      }
    }, REFRESH_MS);
  }

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') {
      scheduleRefresh();
    } else {
      clearTimeout(refreshTimer);
    }
  });

  if (document.visibilityState === 'visible') scheduleRefresh();

}());
