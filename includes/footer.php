<?php $base = $base ?? ''; ?>
<script src="<?= e($base) ?>assets/js/auth.js" defer></script>
<?php foreach (($extraScripts ?? []) as $s): ?>
<script src="<?= e($base . $s) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
