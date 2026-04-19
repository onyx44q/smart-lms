<?php
/**
 * zoom_config.php — Zoom Server-to-Server OAuth credentials
 *
 * HOW TO GET THESE VALUES  (see step-by-step guide below):
 *   1. Go to https://marketplace.zoom.us and sign in
 *   2. Click "Develop" → "Build App"
 *   3. Choose "Server-to-Server OAuth" → Create
 *   4. Copy Account ID, Client ID, Client Secret from the "App Credentials" tab
 *   5. Under "Scopes" add:  meeting:write:admin  meeting:read:admin
 *   6. Activate the app
 */

define('ZOOM_ACCOUNT_ID',    'YOUR_ACCOUNT_ID_HERE');
define('ZOOM_CLIENT_ID',     'YOUR_CLIENT_ID_HERE');
define('ZOOM_CLIENT_SECRET', 'YOUR_CLIENT_SECRET_HERE');

// Timezone for meeting start times — change if needed
// Full list: https://marketplace.zoom.us/docs/api-reference/other-references/abbreviation-lists#timezones
define('ZOOM_TIMEZONE', 'Africa/Nairobi');

// Default meeting duration in minutes (lecturer can extend in Zoom after joining)
define('ZOOM_DEFAULT_DURATION', 60);