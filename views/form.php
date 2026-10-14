<?php

declare(strict_types=1);

?>
<form method="post" action="<?= $escape($action) ?>">
    <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
    <?php foreach ($fields as $name => $value): ?>
        <input type="hidden" name="<?= $escape($name) ?>" value="<?= $escape($value) ?>">
    <?php endforeach; ?>
    <button class="<?= $escape($buttonClass) ?>" type="submit"><?= $escape($label) ?></button>
</form>
