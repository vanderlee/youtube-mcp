<?php

declare(strict_types=1);

?>
<p>Grant this client permission to your connected YouTube account: <?= $escape($scopes) ?></p>
<?= $this->form('/oauth/authorize', 'Approve', fields: ['decision' => 'approve']) ?>
<?= $this->form('/oauth/authorize', 'Deny', fields: ['decision' => 'deny']) ?>
