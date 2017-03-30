<?php foreach ($scripts as $script): ?>
<script src="<?=$script['url'];?>"></script>
<?php endforeach; ?>
<?php if (!empty($inline_js)): ?>
<script>
<?php foreach ($inline_js as $key => $value): ?>
<?=$key?> = <?=Wedeto\FileFormats\JSON\Writer::pprintJSON($value);?>;
<?php endforeach; ?>
</script>
<?php endif; ?>
