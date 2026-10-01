<?php
/**
 * mpesa_config.php — Safaricom Daraja (M-Pesa) credentials
 *
 * HOW TO GET THESE VALUES:
 *   1. Go to https://developer.safaricom.co.ke and sign in / create an account
 *   2. Create an app under "My Apps" → copy the Consumer Key & Consumer Secret
 *   3. For SANDBOX testing, use:
 *        Shortcode: 174379
 *        Passkey:   bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919
 *      (these are Safaricom's fixed public sandbox values — safe to keep below)
 *   4. For GO-LIVE, request your Paybill/Till shortcode + passkey from Safaricom
 *      and replace the sandbox values + switch MPESA_ENV to 'production'
 *   5. Callback URL must be a PUBLICLY reachable HTTPS URL (Daraja cannot call
 *      localhost). During development, run ngrok:
 *        ngrok http 80
 *      then paste the https://xxxx.ngrok-free.app URL into MPESA_CALLBACK_BASE_URL
 *      below, e.g. https://xxxx.ngrok-free.app/mpesa_callback.php
 */

define('MPESA_CONSUMER_KEY',    'D67GpzwMzOLWAVNKcrgLDbCPjn3wQwFqN9jswlDffNogvj2g');
define('MPESA_CONSUMER_SECRET', '9fvrygXnB7hGM6wBxp38a2KzcrGGDzwliACRe5MC3DyfoqLabIyKAU1fZBgAIuBv');

// Sandbox defaults — safe to leave as-is until you go live
define('MPESA_SHORTCODE', '174379');
define('MPESA_PASSKEY',   'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919');

// 'sandbox' or 'production'
define('MPESA_ENV', 'sandbox');

// Full public URL to mpesa_callback.php (must be HTTPS, reachable by Safaricom).
// ✅ Confirmed working locally at localhost/Smart%20lms/mpesa_callback.php
define('MPESA_CALLBACK_URL', 'https://such-pastrami-cone.ngrok-free.dev/Smart%20lms/mpesa_callback.php');

// ── Demo mode: auto-activates when credentials haven't been filled in yet ──
// This lets the rest of the app (and anyone testing it) work immediately —
// "payments" are simulated and auto-marked successful after a few seconds,
// without needing real Daraja credentials configured.
define('MPESA_DEMO_MODE', (
    MPESA_CONSUMER_KEY === 'YOUR_CONSUMER_KEY_HERE' ||
    MPESA_CONSUMER_SECRET === 'YOUR_CONSUMER_SECRET_HERE' ||
    strpos(MPESA_CALLBACK_URL, 'YOUR-NGROK-OR-DOMAIN') !== false
));

define('MPESA_BASE_URL', MPESA_ENV === 'production'
    ? 'https://api.safaricom.co.ke'
    : 'https://sandbox.safaricom.co.ke'
);
