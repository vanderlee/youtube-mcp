<?php

declare(strict_types=1);

?>
<p class="intro">Connect your channel and authorize your AI client to manage your playlists.</p>
<div class="dashboard-grid">
    <section class="card">
        <div class="channel-summary">
            <span class="youtube-mark" aria-hidden="true">▶</span>
            <div>
                <p class="status<?= $connection ? ' connected' : '' ?>"><?= $connection ? 'Connected' : 'Not connected' ?></p>
                <h2><?= $connection ? $escape($connection['channel_name']) : 'Your YouTube channel' ?></h2>
                <p class="muted channel-id"><?= $connection ? 'Channel ID: ' . $escape($connection['channel_id']) : 'Connect Google to get started.' ?></p>
            </div>
        </div>
        <div class="actions">
            <?= $this->form('/google/connect', $connection ? 'Reconnect Google' : 'Connect YouTube account', 'primary') ?>
            <?php if ($connection): ?>
                <?= $this->form('/google/disconnect', 'Disconnect') ?>
            <?php endif; ?>
        </div>
    </section>
    <?php if ($connection): ?>
        <section class="card">
            <h2>Creator media</h2>
            <p>Stage private files for video, thumbnail, banner or caption tools.</p>
            <a href="/media">Stage media</a>
        </section>
    <?php endif; ?>
    <section class="card">
        <h2>Observed usage</h2>
        <dl class="usage">
            <div><dt>General units</dt><dd><?= number_format($usage['buckets']['general']['user_spent']) ?></dd></div>
            <div><dt>Search calls</dt><dd><?= number_format($usage['buckets']['search']['user_spent']) ?></dd></div>
        </dl>
        <p class="muted usage-note">Estimates only. YouTube enforces quotas.</p>
    </section>
</div>
<section class="card chatgpt-card">
    <h2>Connect an AI client</h2>
    <p class="muted">Add this endpoint as a custom MCP server using OAuth.</p>
    <label class="endpoint-label" for="mcp-endpoint">MCP server URL</label>
    <input class="endpoint" id="mcp-endpoint" type="text" readonly spellcheck="false" value="<?= $escape($endpoint) ?>">
    <p class="muted endpoint-help">Select the URL to copy it into ChatGPT, Claude, or Gemini CLI.</p>
    <?php if ($connection): ?>
        <?= $this->form('/oauth/revoke', 'Revoke all AI client access') ?>
    <?php else: ?>
        <p class="muted">Connect and confirm your YouTube channel before authorizing an AI client.</p>
    <?php endif; ?>
</section>
<section class="account-section">
    <div>
        <h2>Account</h2>
        <p class="muted">Delete your service account and stored connections.</p>
    </div>
    <?= $this->form('/account/delete', 'Delete account', 'danger') ?>
</section>
