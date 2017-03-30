<?php foreach ($stylesheets as $stylesheet): ?>
<link rel="stylesheet" type="text/css" href="<?=$stylesheet['url'];?>" media="<?=$stylesheet['media'];?>"></script>
<?php endforeach; ?>
<?php if (!empty($inline_css)): ?>
<?php foreach ($inline_css as $css): ?>
<style>
<?=$css;?>
</style>
<?php endforeach; ?>
<?php endif; ?>
