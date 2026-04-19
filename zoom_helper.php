<?php
/**
 * zoom_helper.php — Zoom Server-to-Server OAuth wrapper
 *
 * Functions:
 *   zoom_get_token()           → string|null
 *   zoom_create_meeting(...)   → array|null  ['meeting_id', 'join_url', 'start_url']
 *   zoom_delete_meeting(...)   → bool
 */

require_once __DIR__ . '/zoom_config.php';

// ── 1. Get a fresh access token ──────────────────────────────────────────────
function zoom_get_token(): ?string
{
    $credentials = base64_encode(ZOOM_CLIENT_ID . ':' . ZOOM_CLIENT_SECRET);
    $url = 'https://zoom.us/oauth/token?grant_type=account_credentials&account_id='
         . urlencode(ZOOM_ACCOUNT_ID);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Basic $credentials",
            'Content-Type: application/x-www-form-urlencoded',
        ],
    ]);

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err || $code !== 200) {
        error_log("zoom_get_token failed — HTTP $code: $err | body: $body");
        return null;
    }

    $data = json_decode($body, true);
    return $data['access_token'] ?? null;
}

// ── 2. Create a Zoom scheduled meeting ──────────────────────────────────────
/**
 * @param string $topic          Session title
 * @param string $meet_date      e.g. "2026-04-25"
 * @param string $meet_time      e.g. "10:00" or "10:00:00"
 * @param int    $duration       Duration in minutes (default from config)
 * @return array|null  ['meeting_id' => string, 'join_url' => string, 'start_url' => string]
 */
function zoom_create_meeting(
    string $topic,
    string $meet_date,
    string $meet_time,
    int    $duration = ZOOM_DEFAULT_DURATION
): ?array {
    $token = zoom_get_token();
    if (!$token) return null;

    // Zoom expects ISO 8601: "2026-04-25T10:00:00"
    $start_time = $meet_date . 'T' . substr($meet_time, 0, 5) . ':00';

    $payload = json_encode([
        'topic'      => $topic,
        'type'       => 2,            // 2 = Scheduled meeting
        'start_time' => $start_time,
        'duration'   => $duration,
        'timezone'   => ZOOM_TIMEZONE,
        'settings'   => [
            'host_video'       => true,
            'participant_video' => true,
            'join_before_host' => true,   // students can join before lecturer
            'waiting_room'     => false,
            'approval_type'    => 2,      // no registration needed
            'audio'            => 'both',
            'mute_upon_entry'  => false,
        ],
    ]);

    $ch = curl_init('https://api.zoom.us/v2/users/me/meetings');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $token",
            'Content-Type: application/json',
        ],
    ]);

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err || $code !== 201) {
        error_log("zoom_create_meeting failed — HTTP $code: $err | body: $body");
        return null;
    }

    $data = json_decode($body, true);

    if (empty($data['id']) || empty($data['join_url'])) {
        error_log("zoom_create_meeting — unexpected response: $body");
        return null;
    }

    return [
        'meeting_id' => (string) $data['id'],
        'join_url'   => $data['join_url'],
        'start_url'  => $data['start_url'],   // host-only link (expires 90 days)
    ];
}

// ── 3. Delete a Zoom meeting ─────────────────────────────────────────────────
/**
 * @param string $meeting_id  Zoom meeting ID stored in DB
 * @return bool
 */
function zoom_delete_meeting(string $meeting_id): bool
{
    if (!$meeting_id) return false;

    $token = zoom_get_token();
    if (!$token) return false;

    $ch = curl_init("https://api.zoom.us/v2/meetings/" . urlencode($meeting_id));
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $token",
        ],
    ]);

    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) error_log("zoom_delete_meeting failed — $err");

    // 204 = success, 404 = already gone (both are fine for us)
    return in_array($code, [204, 404]);
}