#!/bin/bash

# Plesk additional deployment action: bash ./deploy.sh
# After upgrading Music scopes, reconnect Google in the dashboard, then ChatGPT.
# Deployment preserves credentials and cannot expand an existing OAuth grant.
ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)" || exit 1

# Protect the log before the delegated script starts.
umask 077
exec bash "$ROOT/bin/post-deploy.sh" \
  --php "${PHP_BIN:-/opt/plesk/php/8.5/bin/php}" \
  --composer "${COMPOSER_BIN:-/usr/lib/plesk-9.0/composer.phar}" \
  --non-interactive > "$ROOT/deploy.log" 2>&1
