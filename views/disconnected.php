<?php

declare(strict_types=1);

?>
<p>Credentials have been removed from this service.</p>
<?php if (!$googleRevoked): ?>
    <p>Google revocation could not be confirmed. You can remove access in your Google account settings.</p>
<?php endif; ?>
<a href="/">Return</a>
