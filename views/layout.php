<?php

declare(strict_types=1);

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width">
    <meta name="color-scheme" content="dark">
    <title><?= $escape($title) ?> · <?= $escape($brandName) ?></title>
    <link rel="icon" type="image/png" sizes="256x256" href="/assets/favicon.png">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header">
    <div class="header-inner">
        <a class="brand" href="<?= $escape($brandUrl) ?>" aria-label="<?= $escape($brandName) ?> home">
            <span><strong><?= $escape($brandName) ?></strong><span class="brand-divider">/</span><span class="brand-product">youtube</span></span>
            <small><?= $escape($brandTagline) ?></small>
        </a>
        <?php if ($signedIn): ?>
            <?= $this->form('/logout', 'Sign out', 'text-button') ?>
        <?php endif; ?>
    </div>
</header>
<main id="main">
    <h1><?= $escape($title) ?></h1>
    <?= $body ?>
</main>
<footer><?= $escape($brandName) ?> · <?= $escape($brandTagline) ?></footer>
</body>
</html>
