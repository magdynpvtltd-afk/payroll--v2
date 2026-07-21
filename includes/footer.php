    </main>
</div>
<script src="<?= h(asset_url('/assets/js/shortcuts.js')) ?>"></script>
<script src="<?= h(asset_url('/assets/js/app.js')) ?>"></script>
<script src="<?= h(asset_url('/assets/js/dropzones.js')) ?>"></script>
<script src="<?= h(asset_url('/assets/js/datatable.js')) ?>"></script>
<script src="<?= h(asset_url('/assets/js/chips.js')) ?>"></script>
<script src="<?= h(asset_url('/assets/js/combobox.js')) ?>"></script>
<?php
// BOM designer engine. 'sortable' (default) uses the new SortableJS-based
// implementation; 'custom' uses the original hand-rolled drag-drop. Toggle
// in app.config.php under $APP['bom_designer']['engine'] to revert.
$bomEngine = isset($GLOBALS['APP']['bom_designer']['engine'])
    ? $GLOBALS['APP']['bom_designer']['engine']
    : 'sortable';
if ($bomEngine === 'sortable'): ?>
    <script src="<?= h(asset_url('/assets/js/vendor/Sortable.min.js')) ?>"></script>
    <script src="<?= h(asset_url('/assets/js/bom-designer-sortable.js')) ?>"></script>
<?php else: ?>
    <script src="<?= h(asset_url('/assets/js/bom-designer.js')) ?>"></script>
<?php endif; ?>
<?php
// SPA navigation. Skipped when $page_no_spa is set, which a page hosted by a
// sibling app (TaskFlow — see taskflow/magdyn_chrome.php) does. spa.js binds to
// any <main> and swaps sidebar destinations into it in place; on a page served
// from /taskflow/ that would leave MagDyn's markup in a document that still
// carries TaskFlow's <head> and sits under TaskFlow's service-worker scope.
// Those links are ordinary full navigations out of the sibling app instead.
if (empty($page_no_spa)): ?>
    <script src="<?= h(asset_url('/assets/js/spa.js')) ?>"></script>
<?php endif; ?>
<?php if (!empty($extra_js)): ?>
    <?php foreach ((array)$extra_js as $js): ?>
        <script src="<?= h(asset_url($js)) ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>
</body>
</html>
