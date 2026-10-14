# Retired unofficial Music integration

TV/Devices OAuth, browser-cookie authentication, unofficial Music transport and its 21 tools have been removed. The service now retains 18 official YouTube Data API and local-service tools. Music catalog, library, discovery and listening history are no longer available.

Deploy the complete release and run the existing initialization as the subscription user. Migration `005_remove_music_authentication.sql` removes the retired Music credential tables while preserving normal Google connections, signing keys, usage and operation receipts. Historical migrations remain unchanged. Existing private backups and transient browser sessions follow their existing retention policies.

Old `MUSIC_*` environment settings are ignored and can be removed from private configuration. Refresh ChatGPT's tools and reconnect if it still requests removed Music scopes. This migration does not remotely revoke Google grants or sign browsers out; those can be removed separately in Google account settings.

See [connection setup](CONNECTIONS.md), [Plesk upgrade](PLESK.md#data-api-only-upgrade) and [remaining tools](../README.md).
