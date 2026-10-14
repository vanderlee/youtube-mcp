<?php

declare(strict_types=1);

?>
<p>Use <strong><?= $escape($channel['snippet']['title']) ?></strong> (<?= $escape($channel['id']) ?>)? Confirm this is the YouTube channel you want to manage.</p>
<?= $this->form('/google/confirm', 'Connect this channel') ?>
