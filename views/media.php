<?php

declare(strict_types=1);

?>
<p>Choose a video (up to 64 MiB), PNG/JPEG image (up to 2 MiB), or timed SRT/VTT caption (up to 1 MiB). Files are private, expire after 24 hours and are usable only with this account connection. Staging does not publish anything.</p>
<form method="post" action="/media" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
    <input type="file" name="media" required>
    <button type="submit">Stage file</button>
</form>
