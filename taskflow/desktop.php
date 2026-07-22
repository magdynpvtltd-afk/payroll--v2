<?php
/**
 * Desktop dashboard: the same task set as index.php, but rendered as a
 * sortable / searchable / paginated data table optimised for wide screens.
 *
 * This is THE desktop view — index.php's card list is the phone view, and the
 * pair route by viewport rather than by a remembered preference (see the guard
 * in $headHtml below and its complement in index.php).
 *
 * The chrome deliberately mirrors the MagDyn inventory list-page datatable
 * (includes/datatable.php) so both apps read as one system: a single toolbar
 * strip (summary · page-size · search · actions · clear · centered title ·
 * pager), a sticky uppercase header with sort chevrons, a full grid, and a
 * sticky pill-shaped filter row in the <tfoot>.
 *
 * The BEHAVIOUR stays TaskFlow's own: the inventory table round-trips to the
 * server (AJAX) on every sort/filter/page, which would break this PWA offline.
 * So the server renders every matching row once and the inline script below
 * does the sorting, filtering, and paging entirely in the DOM — no external
 * libs, works offline like the rest of the app.
 *
 * The $columns config drives the header, the filter row, and the mobile sort
 * list from one place, the same way the inventory's $cfg['columns'] does.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/task_query.php';
require __DIR__ . '/uploads.php';
$me = require_login();

$filter = $_GET['filter'] ?? 'mine';   // mine | created | unassigned | unread | all
$status = $_GET['status'] ?? '';       // '' | open | in_progress | done
$admin  = $me['role'] === 'admin';

// 'unassigned' is an admin-only triage view — mirror index.php's guard so a
// non-admin who lands on it (stale link, hand-typed URL) is bounced to Mine.
if ($filter === 'unassigned' && !$admin) { $filter = 'mine'; }

$tasks = tf_task_list($me, $filter, $status, $admin);
// Attachments for every listed task, so the 📎 badge in the Activity column
// can open them in place (see tf_att_trigger() / tf_attachment_list_assets()).
$attMap = tf_list_attachments(array_column($tasks, 'id'));

$pill = fn($f, $lbl) => '<a class="pill ' . ($filter === $f ? 'on' : '') . '" href="?filter=' . $f
    . ($status ? '&status=' . e($status) : '') . '">' . $lbl . '</a>';
$spill = fn($s, $lbl) => '<a class="pill ' . ($status === $s ? 'on' : '') . '" href="?filter=' . e($filter)
    . ($s ? '&status=' . $s : '') . '">' . $lbl . '</a>';

// Numeric sort ranks so the client can sort priority/status meaningfully
// (not alphabetically) — highest urgency first when sorted descending.
$prioRank   = ['high' => 3, 'medium' => 2, 'low' => 1];
$statusRank = ['in_progress' => 3, 'open' => 2, 'done' => 1];
$statusLabel = ['open' => 'Open', 'in_progress' => 'In progress', 'done' => 'Done'];

// Column registry — drives the header row, the tfoot filter row, and the
// mobile sort dropdown. 'select' filters match the cell text exactly; 'text'
// filters match a case-insensitive substring.
$columns = [
    ['key' => 'title',    'label' => 'Task',             'sortable' => true,  'filter' => 'text'],
    ['key' => 'priority', 'label' => 'Priority',         'sortable' => true,  'filter' => 'select',
     'options' => [['high', 'High'], ['medium', 'Medium'], ['low', 'Low']]],
    ['key' => 'status',   'label' => 'Status',           'sortable' => true,  'filter' => 'select',
     'options' => [['Open', 'Open'], ['In progress', 'In progress'], ['Done', 'Done']]],
    ['key' => 'party',    'label' => 'Assigned to / by', 'sortable' => true,  'filter' => 'text'],
    // 'Created date', not 'Assigned date': the value is tf_tasks.created_at and
    // there is no assignment timestamp in the schema to show instead. The two
    // coincide for a task created with an assignee, but NOT for one created
    // unassigned (task_form.php allows it) or reassigned later (task_action.php
    // updates assigned_to only) — so the old label was wrong precisely where it
    // would have mattered.
    ['key' => 'created',  'label' => 'Created date',     'sortable' => true,  'filter' => 'text'],
    ['key' => 'due',      'label' => 'Due date',         'sortable' => true,  'filter' => 'text'],
    ['key' => 'activity', 'label' => 'Activity',         'sortable' => false, 'filter' => 'text'],
    ['key' => 'last',     'label' => 'Last comment',     'sortable' => false, 'filter' => 'text'],
];
$total = count($tasks);

// Wear MagDyn's real chrome — its sidebar nav and script tail — instead of
// TaskFlow's own topbar/tabbar, so the desktop table reads as part of the one
// system. The bridge sets every header/footer hook this page needs (module
// highlight, TaskFlow's stylesheet + PWA identity + app.js, no-SPA) and pulls
// in MagDyn's bootstrap; we only add this page's title and <head> scripts.
require __DIR__ . '/magdyn_chrome.php';

$page_title = 'Tasks';
// 'has-dt-wrap' is MagDyn's viewport-lock body class (see the companion rules
// in style.css): the page itself never scrolls and only the table body does,
// matching every MagDyn list page.
$page_body_class = 'has-dt-wrap';

// Two <head> scripts, appended after MagDyn's header markup:
//   1. Desktop-only guard — mirror image of index.php's. This table is the
//      desktop view, so a phone that lands here (shared link, bookmark, back
//      button) is sent to the card view. Runs before paint, so no table flashes
//      on a screen too narrow for it. Same breakpoint as the card guard, exact
//      complement, so the two can't ping-pong.
//   2. window.TF — the globals app.js reads for web push, normally emitted by
//      TaskFlow's own footer, which a borrowed-chrome page doesn't include.
$page_head_html = <<<'HTML'
<script>
(function () {
  try {
    if (!(window.matchMedia && window.matchMedia('(min-width:720px)').matches)) {
      location.replace('index.php' + (location.search || ''));
    }
  } catch (e) {}
})();
</script>
HTML
    . tf_chrome_globals_script();

require MAGDYN_INCLUDES . '/header.php';
?>
<div class="tfdt-filterbar">
  <div class="filters">
    <?= $pill('mine', 'Mine')
      . $pill('created', 'I assigned')
      . ($admin ? $pill('unassigned', 'Unassigned') : '')
      . $pill('unread', 'Unread')
      . $pill('all', $admin ? 'All' : 'All mine') ?>
  </div>
  <span class="filterbar-sep" aria-hidden="true"></span>
  <div class="filters">
    <?= $spill('', 'Any') . $spill('open', 'Open') . $spill('in_progress', 'In progress') . $spill('done', 'Done') ?>
  </div>
  <?php /* No inventory link or profile menu here any more: MagDyn's sidebar
           (this page now borrows it) already carries the nav, the user card
           and sign-out, so a second copy on this row would just duplicate it. */ ?>
</div>

<div class="tfdt-wrap" id="taskDt">
  <div class="tfdt-toolbar">
    <div class="tfdt-toolbar-left">
      <span class="tfdt-summary">
        Showing <strong class="tfdt-range-start">0</strong> to <strong class="tfdt-range-end">0</strong>
        of <strong class="tfdt-total"><?= (int)$total ?></strong> entries
      </span>
      <label class="tfdt-page-size">
        <select class="tfdt-size">
          <option value="10">10</option>
          <option value="25" selected>25</option>
          <option value="50">50</option>
          <option value="100">100</option>
        </select>
        / page
      </label>
      <div class="tfdt-filter-pill tfdt-search-pill">
        <span class="tfdt-filter-icon" aria-hidden="true">🔍</span>
        <input type="search" class="tfdt-q" placeholder="Search all"
               autocomplete="off" aria-label="Search across every column">
      </div>
      <label class="tfdt-mobile-sort">
        <span class="tfdt-mobile-sort-lbl">Sort</span>
        <select class="tfdt-sort-select" aria-label="Sort by column">
          <option value="">—</option>
          <?php foreach ($columns as $c): if (empty($c['sortable'])) continue; ?>
            <option value="<?= e($c['key']) ?>"><?= e($c['label']) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="button" class="btn btn-sm btn-ghost tfdt-sort-dir" data-dir="asc"
                title="Toggle sort direction (ascending / descending)"
                aria-label="Toggle sort direction">▲</button>
      </label>
      <button type="button" class="btn btn-sm btn-ghost tfdt-clear-filters" hidden
              title="Clear all column filters and the search box.">✕ Clear filters</button>
    </div>
    <h2 class="tfdt-toolbar-title">Tasks</h2>
    <?php /* The page actions live on the RIGHT so the left group stays inside
             its half of the centring grid and never reaches the title. No
             "Card view" button: the card list is the phone view now, and the
             guard in <head> would bounce a desktop visitor straight back. */ ?>
    <div class="tfdt-toolbar-right">
      <span class="tfdt-toolbar-actions">
        <?= tf_push_bell() ?>
        <a class="btn btn-sm btn-primary" href="task_form.php" title="New task (Alt+N)">＋ New task</a>
      </span>
      <span class="tfdt-pager"></span>
    </div>
  </div>

  <div class="tfdt-scroll">
    <table class="tfdt-table" id="taskTable" data-tfdt-resizable="1">
      <thead>
        <tr class="tfdt-headers">
          <?php foreach ($columns as $c): ?>
            <th data-key="<?= e($c['key']) ?>"<?= !empty($c['sortable'])
                    ? ' class="tfdt-sortable" tabindex="0" role="button" aria-sort="none"' : '' ?>>
              <span class="tfdt-th-label"><?= e($c['label']) ?></span>
              <?php if (!empty($c['sortable'])): ?><span class="tfdt-arrow" aria-hidden="true">↕</span><?php endif; ?>
              <?php /* Every column gets a resize grip, including the last —
                       the table can grow past the container and .tfdt-scroll
                       provides the horizontal scroll. */ ?>
              <span class="tfdt-resize-handle" data-tfdt-resize aria-hidden="true"></span>
            </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody class="tfdt-body">
      <?php foreach ($tasks as $t):
          $href      = 'task_view.php?id=' . (int)$t['id'];
          $isMine    = (int)$t['assigned_to'] === (int)$me['id'];
          $assignSort = $t['assigned_to'] === null ? '' : strtolower((string)$t['assignee_name']);
          $createdTs = $t['created_at'] ? (int)strtotime($t['created_at']) : 0;
          $dueTs     = $t['due_date']   ? (int)strtotime($t['due_date'])   : 0;
          $unread    = (int)$t['unread_count'];
      ?>
        <tr data-href="<?= e($href) ?>" class="tfdt-row s-<?= e($t['status']) ?>">
          <td>
            <a href="<?= e($href) ?>" class="tfdt-title <?= $t['status'] === 'done' ? 'done' : '' ?>"><?= e($t['title']) ?></a>
          </td>
          <td data-sort="<?= (int)($prioRank[$t['priority']] ?? 0) ?>">
            <span class="prio p-<?= e($t['priority']) ?>"><?= e($t['priority']) ?></span>
          </td>
          <td data-sort="<?= (int)($statusRank[$t['status']] ?? 0) ?>">
            <span class="stat"><?= e($statusLabel[$t['status']] ?? $t['status']) ?></span>
          </td>
          <td data-sort="<?= e($assignSort) ?>">
            <?php if ($t['assigned_to'] === null): ?>
              <span class="badge b-unassigned">Unassigned</span>
            <?php else: ?>
              <strong><?= e($t['assignee_name']) ?><?= $isMine ? ' (me)' : '' ?></strong>
            <?php endif; ?>
            <span class="muted">by <?= e($t['creator_name']) ?></span>
          </td>
          <td data-sort="<?= $createdTs ?>"><?= e(tf_fmt_date($t['created_at'])) ?></td>
          <td data-sort="<?= $dueTs ?>"><?= $t['due_date'] ? e(tf_fmt_date($t['due_date'])) : '<span class="muted">—</span>' ?></td>
          <td class="tfdt-activity" data-sort="<?= $unread ?>">
            <?php if ($t['comment_count']): ?><span title="Comments">💬 <?= (int)$t['comment_count'] ?></span><?php endif; ?>
            <?php if ($unread > 0): ?><span class="unread-badge" title="<?= $unread ?> unread comment(s)"><?= $unread ?></span><?php endif; ?>
            <?php if ($t['attach_count']): ?><?= tf_att_trigger($attMap[(int)$t['id']] ?? []) ?><?php endif; ?>
          </td>
          <td>
            <?php if ($t['last_comment'] !== null && $t['last_comment'] !== ''): ?>
              <span class="muted"><?= e(tf_excerpt($t['last_comment'], 40)) ?></span>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot class="tfdt-filters">
        <tr>
          <?php foreach ($columns as $i => $c): ?>
            <td class="tfdt-filter-td">
              <div class="tfdt-filter-pill">
                <span class="tfdt-filter-icon" aria-hidden="true">🔍</span>
                <?php /* data-col drives filtering (which <td> to read); data-key
                         is what the saved view state is stored under, so a
                         remembered filter follows its column rather than its
                         position if $columns is ever reordered. */ ?>
                <?php if (($c['filter'] ?? 'text') === 'select'): ?>
                  <select class="tfdt-col-filter" data-col="<?= (int)$i ?>" data-key="<?= e($c['key']) ?>"
                          aria-label="Filter by <?= e($c['label']) ?>">
                    <option value="">all</option>
                    <?php foreach ($c['options'] as $o): ?>
                      <option value="<?= e($o[0]) ?>"><?= e($o[1]) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php else: ?>
                  <input type="search" class="tfdt-col-filter" data-col="<?= (int)$i ?>" data-key="<?= e($c['key']) ?>"
                         placeholder="<?= e($c['label']) ?>" autocomplete="off"
                         aria-label="Filter by <?= e($c['label']) ?>">
                <?php endif; ?>
              </div>
            </td>
          <?php endforeach; ?>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<script>
(function () {
  var root = document.getElementById('taskDt');
  if (!root) return;
  var table   = document.getElementById('taskTable');
  var tbody   = table.tBodies[0];
  var q       = root.querySelector('.tfdt-q');
  var sizeEl  = root.querySelector('.tfdt-size');
  var pager   = root.querySelector('.tfdt-pager');
  var clearBtn = root.querySelector('.tfdt-clear-filters');
  var startEl = root.querySelector('.tfdt-range-start');
  var endEl   = root.querySelector('.tfdt-range-end');
  var totalEl = root.querySelector('.tfdt-total');
  var heads   = Array.prototype.slice.call(table.querySelectorAll('thead th'));
  var filterEls = Array.prototype.slice.call(table.querySelectorAll('.tfdt-col-filter'));
  var sortSel = root.querySelector('.tfdt-sort-select');
  var sortDirBtn = root.querySelector('.tfdt-sort-dir');

  // Snapshot every data row once; we re-append page slices from this array.
  var allRows  = Array.prototype.slice.call(tbody.querySelectorAll('tr.tfdt-row'));
  var colCount = heads.length;

  // column key -> index, so the mobile sort <select> can drive the same sort
  // path as a header click.
  var keyToIdx = {};
  heads.forEach(function (th, i) { keyToIdx[th.getAttribute('data-key')] = i; });

  // `pages` is written by render() and read by the Alt+←/→ handler, so the
  // shortcut clamps against the CURRENT filtered page count, not a stale one.
  var state = { term: '', sortIdx: null, dir: 'asc', size: parseInt(sizeEl.value, 10) || 25, page: 1, pages: 1 };

  // ---- saved view state (search / column filters / sort / page size) ----
  // MagDyn's list pages remember how you left a table ($cfg['save_state'] in
  // includes/datatable.php); this is the same promise for TaskFlow.
  //
  // It stores to localStorage rather than to the server the way MagDyn does,
  // for the same reason the column widths below do: this table renders and
  // filters entirely in the DOM so it keeps working offline, and TaskFlow has
  // no per-user prefs endpoint. Scope is therefore per-browser, not per-user
  // cross-device.
  //
  // The page number is deliberately NOT saved, matching MagDyn: a restored view
  // always lands on page 1.
  //
  // Not keyed by the filter/status pills — those are URL state, and MagDyn
  // likewise keys its saved view by table, not by whatever the page is scoped
  // to. Coming back to Mine vs All shows the same remembered columns.
  var VIEW_STORE = 'tf.dt.view';

  function saveView() {
    var col = {};
    filterEls.forEach(function (el) {
      var k = el.getAttribute('data-key'), v = el.value.trim();
      if (k && v) col[k] = v;
    });
    // Store the sort COLUMN KEY, not state.sortIdx — an index would silently
    // point at the wrong column if $columns is ever reordered.
    var sortKey = state.sortIdx !== null && heads[state.sortIdx]
      ? heads[state.sortIdx].getAttribute('data-key') : '';
    try {
      localStorage.setItem(VIEW_STORE, JSON.stringify({
        q: q.value.trim(), size: state.size, sort: sortKey, dir: state.dir, col: col
      }));
    } catch (e) { /* private mode / quota — the view just isn't remembered */ }
  }

  // Everything restored here is re-validated against the DOM as it stands now,
  // never trusted as-written: the payload can outlive the page that wrote it
  // (a renamed column key, a dropped page size, a filter option that no longer
  // exists), and a stale value applied blindly would restore a view the user
  // can't see the cause of — e.g. a select forced to a value with no matching
  // <option> reads as blank while still filtering every row away.
  function restoreView() {
    var raw = null;
    try { raw = localStorage.getItem(VIEW_STORE); } catch (e) { return; }
    if (!raw) return;
    var v;
    try { v = JSON.parse(raw); } catch (e) { return; }
    if (!v || typeof v !== 'object') return;

    if (typeof v.q === 'string' && v.q) {
      q.value = v.q;
      state.term = v.q.toLowerCase();
    }

    if (v.size && hasOption(sizeEl, String(v.size))) {
      sizeEl.value = String(v.size);
      state.size = parseInt(v.size, 10) || state.size;
    }

    if (v.col && typeof v.col === 'object') {
      filterEls.forEach(function (el) {
        var k = el.getAttribute('data-key');
        if (!k || typeof v.col[k] !== 'string') return;
        if (el.tagName !== 'SELECT') { el.value = v.col[k]; return; }
        if (hasOption(el, v.col[k])) el.value = v.col[k];
      });
    }

    if (v.sort && keyToIdx.hasOwnProperty(v.sort)
        && heads[keyToIdx[v.sort]].classList.contains('tfdt-sortable')) {
      state.sortIdx = keyToIdx[v.sort];
      state.dir = v.dir === 'desc' ? 'desc' : 'asc';
      if (sortSel) sortSel.value = v.sort;
      if (sortDirBtn) {
        sortDirBtn.setAttribute('data-dir', state.dir);
        sortDirBtn.textContent = state.dir === 'asc' ? '▲' : '▼';
      }
    }
  }

  function hasOption(sel, val) {
    return Array.prototype.some.call(sel.options, function (o) { return o.value === val; });
  }

  function sortVal(row, idx) {
    var td = row.cells[idx];
    if (!td) return '';
    var ds = td.getAttribute('data-sort');
    return ds !== null ? ds : td.textContent.trim();
  }
  var numRe = /^-?\d*\.?\d+$/;
  function cmp(a, b) {
    if (numRe.test(a) && numRe.test(b)) return parseFloat(a) - parseFloat(b);
    return a.toLowerCase().localeCompare(b.toLowerCase());
  }

  function paintArrows() {
    heads.forEach(function (th, i) {
      var a = th.querySelector('.tfdt-arrow');
      if (!a) return;
      if (i === state.sortIdx) {
        th.classList.add('tfdt-sorted');
        th.setAttribute('aria-sort', state.dir === 'asc' ? 'ascending' : 'descending');
        a.textContent = state.dir === 'asc' ? '▲' : '▼';
      } else {
        th.classList.remove('tfdt-sorted');
        th.setAttribute('aria-sort', 'none');
        a.textContent = '↕';
      }
    });
  }

  function render() {
    var term = state.term;
    // Collect the active per-column filters once per render.
    var colFilters = [];
    filterEls.forEach(function (el) {
      var v = el.value.trim().toLowerCase();
      if (v) colFilters.push({ idx: parseInt(el.getAttribute('data-col'), 10), val: v, exact: el.tagName === 'SELECT' });
    });

    var view = allRows.filter(function (r) {
      if (term && r.textContent.toLowerCase().indexOf(term) === -1) return false;
      for (var i = 0; i < colFilters.length; i++) {
        var f = colFilters[i], td = r.cells[f.idx];
        var txt = td ? td.textContent.trim().toLowerCase() : '';
        if (f.exact ? txt !== f.val : txt.indexOf(f.val) === -1) return false;
      }
      return true;
    });

    if (clearBtn) clearBtn.hidden = !(term || colFilters.length);

    if (state.sortIdx !== null) {
      var idx = state.sortIdx, dir = state.dir === 'asc' ? 1 : -1;
      view = view.map(function (r, i) { return [r, i]; }).sort(function (x, y) {
        var c = cmp(sortVal(x[0], idx), sortVal(y[0], idx));
        return (dir * c) || (x[1] - y[1]);            // stable tie-break
      }).map(function (p) { return p[0]; });
    }

    var total = view.length;
    var pages = Math.max(1, Math.ceil(total / state.size));
    if (state.page > pages) state.page = pages;
    if (state.page < 1) state.page = 1;
    state.pages = pages;
    var start = (state.page - 1) * state.size;
    var end   = Math.min(total, start + state.size);

    while (tbody.firstChild) tbody.removeChild(tbody.firstChild);
    if (total === 0) {
      var tr = document.createElement('tr');
      var td = document.createElement('td');
      td.className = 'tfdt-empty';
      td.colSpan = colCount;
      td.textContent = (term || colFilters.length) ? 'No tasks match your filters.' : 'No tasks here yet.';
      tr.appendChild(td);
      tbody.appendChild(tr);
    } else {
      for (var i = start; i < end; i++) tbody.appendChild(view[i]);
    }

    // "Showing X to Y of Z entries" — same summary the inventory toolbar shows.
    startEl.textContent = total === 0 ? '0' : String(start + 1);
    endEl.textContent   = String(end);
    totalEl.textContent = String(total);

    paintArrows();
    renderPager(pages);
    // Persist from the one place every sort / filter / search / size change
    // already funnels through, so the saved view can't drift from the shown one.
    saveView();
  }

  function pageBtn(label, target, opts) {
    opts = opts || {};
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'btn btn-sm ' + (opts.current ? 'btn-primary' : 'btn-ghost');
    b.textContent = label;
    if (opts.title) b.title = opts.title;
    if (opts.disabled) b.disabled = true;
    else b.addEventListener('click', function () { state.page = target; render(); });
    return b;
  }
  function ellipsis(txt) {
    var s = document.createElement('span');
    s.className = 'tfdt-ellipsis';
    s.textContent = txt;
    return s;
  }

  // Pager mirrors the inventory pager: « First ‹ Prev … Next › Last »
  function renderPager(pages) {
    while (pager.firstChild) pager.removeChild(pager.firstChild);
    if (pages <= 1) { pager.appendChild(ellipsis('Page 1 of 1')); return; }
    var p = state.page, win = 2;
    var from = Math.max(1, p - win), to = Math.min(pages, p + win);
    pager.appendChild(pageBtn('« First', 1, { disabled: p === 1 }));
    pager.appendChild(pageBtn('‹ Prev', p - 1, { disabled: p === 1, title: 'Previous page (Alt+←)' }));
    if (from > 1) pager.appendChild(ellipsis('…'));
    for (var i = from; i <= to; i++) pager.appendChild(pageBtn(String(i), i, { current: i === p }));
    if (to < pages) pager.appendChild(ellipsis('…'));
    pager.appendChild(pageBtn('Next ›', p + 1, { disabled: p === pages, title: 'Next page (Alt+→)' }));
    pager.appendChild(pageBtn('Last »', pages, { disabled: p === pages }));
  }

  function applySort(idx, dir) {
    state.sortIdx = idx;
    state.dir = dir;
    state.page = 1;
    if (sortSel) sortSel.value = heads[idx] ? heads[idx].getAttribute('data-key') : '';
    if (sortDirBtn) {
      sortDirBtn.setAttribute('data-dir', dir);
      sortDirBtn.textContent = dir === 'asc' ? '▲' : '▼';
    }
    render();
  }

  // ---- header sort ----
  heads.forEach(function (th, idx) {
    if (!th.classList.contains('tfdt-sortable')) return;
    function fire() {
      applySort(idx, state.sortIdx === idx && state.dir === 'asc' ? 'desc' : 'asc');
    }
    th.addEventListener('click', fire);
    th.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fire(); }
    });
  });

  // ---- global search (debounced) ----
  var t;
  q.addEventListener('input', function () {
    clearTimeout(t);
    t = setTimeout(function () {
      state.term = q.value.trim().toLowerCase();
      state.page = 1;
      render();
    }, 150);
  });
  q.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { q.value = ''; state.term = ''; state.page = 1; render(); }
  });

  // ---- per-column filters (text debounced, selects immediate) ----
  var ft;
  filterEls.forEach(function (el) {
    var run = function () { state.page = 1; render(); };
    el.addEventListener('change', run);
    el.addEventListener('input', function () {
      if (el.tagName === 'SELECT') return;      // handled by 'change'
      clearTimeout(ft);
      ft = setTimeout(run, 150);
    });
  });

  // ---- clear filters ----
  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      filterEls.forEach(function (el) { el.value = ''; });
      q.value = '';
      state.term = '';
      state.page = 1;
      render();
    });
  }

  // ---- page size ----
  sizeEl.addEventListener('change', function () {
    state.size = parseInt(sizeEl.value, 10) || 25;
    state.page = 1;
    render();
  });

  // ---- Alt+← / Alt+→ : previous / next page ----
  // Alt+Arrow is the browser's Back/Forward on Windows and Linux, so this
  // swallows both arrows whenever the table is on screen — including at the
  // first and last page, where paging is a no-op. Swallowing the no-op is the
  // point: without it, one Alt+← too many on page 1 quietly navigates off the
  // list instead of doing nothing, which is a far worse surprise than a
  // shortcut that simply stops at the end.
  //
  // Bound on document (not the table) so it works while the caret sits in the
  // search box or a column filter — Alt+Arrow means nothing to a text field.
  //
  // ev.key here, NOT the ev.code that app.js's Alt+N/Alt+S use. That rule
  // exists because a LETTER key under Alt can report a dead key or an accented
  // character in ev.key on some layouts, so only the physical key is
  // trustworthy. Arrows aren't character-producing: ev.key is 'ArrowLeft' /
  // 'ArrowRight' on every layout, while ev.code is the field a synthetic or
  // remote-dispatched event is most likely to leave empty.
  document.addEventListener('keydown', function (ev) {
    if (!ev.altKey || ev.ctrlKey || ev.metaKey || ev.shiftKey) return;
    if (ev.key !== 'ArrowLeft' && ev.key !== 'ArrowRight') return;
    ev.preventDefault();
    var target = state.page + (ev.key === 'ArrowLeft' ? -1 : 1);
    if (target < 1 || target > state.pages) return;
    state.page = target;
    render();
  });

  // ---- mobile "Sort by" control ----
  if (sortSel) {
    sortSel.addEventListener('change', function () {
      if (!sortSel.value) { state.sortIdx = null; state.page = 1; render(); return; }
      applySort(keyToIdx[sortSel.value], sortDirBtn ? (sortDirBtn.getAttribute('data-dir') || 'asc') : 'asc');
    });
  }
  if (sortDirBtn) {
    sortDirBtn.addEventListener('click', function () {
      var d = sortDirBtn.getAttribute('data-dir') === 'asc' ? 'desc' : 'asc';
      sortDirBtn.setAttribute('data-dir', d);
      sortDirBtn.textContent = d === 'asc' ? '▲' : '▼';
      if (sortSel && sortSel.value) applySort(keyToIdx[sortSel.value], d);
    });
  }

  // ---- row click-through (ignore clicks on the title link itself) ----
  tbody.addEventListener('click', function (ev) {
    if (ev.target.closest('a')) return;
    // The 📎 attachment badge opens its own popover — never navigate the row
    // out from under it. (Its handler stopPropagation()s too, but that fires
    // AFTER this bubbling listener, so the badge must be excluded here.)
    if (ev.target.closest('.tf-att-trigger')) return;
    var tr = ev.target.closest('tr.tfdt-row');
    if (tr && tr.dataset.href) window.location.href = tr.dataset.href;
  });

  // ---- column resize + saved widths ----
  // Drag the grip on a header's right edge. Each column's width is stored in
  // localStorage under its key, so the layout survives reloads and keeps
  // working offline (TaskFlow has no per-user prefs endpoint the way MagDyn
  // does — this is per-browser rather than cross-device).
  var STORE = 'tf.dt.colw.';

  var savedW = {};
  var hasSaved = false;
  heads.forEach(function (th) {
    var saved = null;
    try { saved = localStorage.getItem(STORE + th.getAttribute('data-key')); } catch (e) {}
    if (saved && /^\d+$/.test(saved)) { savedW[th.getAttribute('data-key')] = parseInt(saved, 10); hasSaved = true; }
  });
  if (hasSaved) {
    // Under fixed layout the unsized columns split whatever is left of the
    // table's width — so restoring only the saved ones crushes the rest to 0px
    // and `overflow:hidden` makes them vanish outright. Every column therefore
    // gets an explicit width: its saved one, or the natural width it has right
    // now. Measure all of them BEFORE writing any width or flipping to fixed,
    // while auto-layout still reflects the real content.
    var natural = heads.map(function (th) { return th.getBoundingClientRect().width; });
    var total = 0;
    heads.forEach(function (th, i) {
      var w = savedW[th.getAttribute('data-key')];
      if (w == null) w = Math.round(natural[i]);
      th.style.width = w + 'px';
      total += w;
    });
    // An explicit width only sticks under fixed layout; auto-layout would
    // redistribute it. Pinning the table to the widths we just asked for (not
    // to what the container allows) is what stops `width:100%` from squeezing
    // them back to fit — past the container edge, .tfdt-scroll scrolls.
    table.classList.add('tfdt-fixed-layout');
    if (total > 0) table.style.width = total + 'px';
  }

  Array.prototype.forEach.call(table.querySelectorAll('.tfdt-resize-handle'), function (handle) {
    // A grip sits inside a sortable th — never let a drag read as a sort.
    handle.addEventListener('click', function (ev) { ev.stopPropagation(); });
    handle.addEventListener('mousedown', function (ev) {
      ev.preventDefault();
      ev.stopPropagation();
      var th = handle.closest('th');
      if (!th) return;
      var key = th.getAttribute('data-key');
      var startX = ev.clientX;
      var startW = th.getBoundingClientRect().width;

      handle.classList.add('tfdt-resizing');
      document.body.classList.add('tfdt-resizing-active');

      if (!table.classList.contains('tfdt-fixed-layout')) {
        // Freeze every current width before flipping to fixed, so the other
        // columns don't jump the moment the drag starts.
        heads.forEach(function (o) {
          if (!o.style.width) o.style.width = o.getBoundingClientRect().width + 'px';
        });
        table.classList.add('tfdt-fixed-layout');
        startW = th.getBoundingClientRect().width;
      }

      // Recomputed per drag so it stays right after earlier resizes.
      var baseTableW = 0;
      heads.forEach(function (o) { baseTableW += o.getBoundingClientRect().width; });
      table.style.width = Math.round(baseTableW) + 'px';

      function onMove(e2) {
        var newW = Math.max(40, Math.round(startW + (e2.clientX - startX)));
        th.style.width = newW + 'px';
        // Grow the table by the same delta so the column actually expands
        // (and .tfdt-scroll scrolls) instead of squeezing its neighbours.
        table.style.width = Math.round(baseTableW + (newW - startW)) + 'px';
      }
      function onUp() {
        handle.classList.remove('tfdt-resizing');
        document.body.classList.remove('tfdt-resizing-active');
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        if (!key) return;
        try {
          localStorage.setItem(STORE + key, String(Math.round(th.getBoundingClientRect().width)));
        } catch (e) { /* private mode / quota — width still applies this session */ }
      }
      document.addEventListener('mousemove', onMove);
      document.addEventListener('mouseup', onUp);
    });
  });

  // Restore before the first render so the table paints as the user left it —
  // no flash of an unfiltered list resolving into a filtered one.
  restoreView();
  render();

  // Land with the caret already in "Search all", so narrowing the list is just
  // typing rather than reach-for-the-mouse-first.
  //
  // preventScroll matters under body.tf-fixed: the page is pinned to the
  // viewport, and a focus that is allowed to scroll shifts the pinned layout
  // and drags the toolbar out of view.
  //
  // Re-checking the breakpoint even though the <head> guard already sent phones
  // to index.php: location.replace() starts a navigation, it does not stop this
  // script from running first, and a phone flashing its keyboard open on the way
  // out is exactly the kind of thing that guard exists to prevent.
  if (!window.matchMedia || window.matchMedia('(min-width:720px)').matches) {
    try { q.focus({ preventScroll: true }); } catch (e) { q.focus(); }
  }
})();
</script>
<?php tf_attachment_list_assets(); ?>
<?php require MAGDYN_INCLUDES . '/footer.php';
