<?php
/**
 * mpesa_helper.php — Safaricom Daraja (M-Pesa) STK Push wrapper
 *
 * Functions:
 *   mpesa_format_phone($phone)                          → string|null   e.g. 2547XXXXXXXX
 *   mpesa_get_access_token()                             → string|null
 *   mpesa_stk_push($phone, $amount, $accountRef, $desc)  → array|null  ['MerchantRequestID','CheckoutRequestID',...]
 *   mpesa_record_successful_payment($conn, ...)          → array  ['receipt_number' => ...]
 *
 * mpesa_record_successful_payment() is the single place that writes into
 * fee_payments and re-syncs student_fee_assignments statuses. It mirrors the
 * exact logic financial_dashboard.php's "record_payment" action uses, so an
 * M-Pesa payment shows up on both the student dashboard and the finance
 * dashboard exactly like a payment the Finance Office typed in manually —
 * same table, same receipt format, same status recalculation.
 */

require_once __DIR__ . '/mpesa_config.php';

// ── 1. Normalise a Kenyan phone number to Daraja's expected 2547XXXXXXXX / 2541XXXXXXXX format ──
function mpesa_format_phone(string $phone): ?string
{
    $phone = trim($phone);
    $phone = preg_replace('/[^\d+]/', '', $phone); // strip spaces, dashes, etc.
    $phone = ltrim($phone, '+');

    if (preg_match('/^0(7|1)\d{8}$/', $phone)) {          // 07XXXXXXXX / 01XXXXXXXX
        return '254' . substr($phone, 1);
    }
    if (preg_match('/^254(7|1)\d{8}$/', $phone)) {         // already 2547XXXXXXXX
        return $phone;
    }
    if (preg_match('/^(7|1)\d{8}$/', $phone)) {            // 7XXXXXXXX (no leading 0)
        return '254' . $phone;
    }
    return null; // not a recognisable Safaricom-format number
}

// ── 2. Get a fresh OAuth access token ────────────────────────────────────────
function mpesa_get_access_token(): ?string
{
    $credentials = base64_encode(MPESA_CONSUMER_KEY . ':' . MPESA_CONSUMER_SECRET);
    $url = MPESA_BASE_URL . '/oauth/v1/generate?grant_type=client_credentials';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Basic $credentials"],
        CURLOPT_TIMEOUT        => 20,
    ]);

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err || $code !== 200) {
        error_log("mpesa_get_access_token failed — HTTP $code: $err | body: $body");
        return null;
    }

    $data = json_decode($body, true);
    return $data['access_token'] ?? null;
}

// ── 3. Trigger an STK Push (Lipa Na M-Pesa Online) prompt to the phone ──────
/**
 * @param string $phone       2547XXXXXXXX formatted (use mpesa_format_phone first)
 * @param float  $amount      Amount in KES (whole shillings — Daraja sandbox rejects decimals)
 * @param string $accountRef  Shows on the STK prompt, e.g. "STU-00012 Fees" (max 12 chars used safely)
 * @param string $desc        Short transaction description
 * @return array|null  Raw Daraja response (contains MerchantRequestID, CheckoutRequestID) or null on failure
 */
function mpesa_stk_push(string $phone, float $amount, string $accountRef, string $desc = 'Fee Payment'): ?array
{
    $token = mpesa_get_access_token();
    if (!$token) return null;

    $timestamp = date('YmdHis');
    $password  = base64_encode(MPESA_SHORTCODE . MPESA_PASSKEY . $timestamp);

    $payload = json_encode([
        'BusinessShortCode' => MPESA_SHORTCODE,
        'Password'          => $password,
        'Timestamp'         => $timestamp,
        'TransactionType'   => 'CustomerPayBillOnline',
        'Amount'            => (int) round($amount), // Daraja sandbox requires whole numbers
        'PartyA'            => $phone,
        'PartyB'            => MPESA_SHORTCODE,
        'PhoneNumber'       => $phone,
        'CallBackURL'       => MPESA_CALLBACK_URL,
        'AccountReference'  => substr($accountRef, 0, 12),
        'TransactionDesc'   => substr($desc, 0, 13),
    ]);

    $ch = curl_init(MPESA_BASE_URL . '/mpesa/stkpush/v1/processrequest');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $token",
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        error_log("mpesa_stk_push cURL error: $err");
        return null;
    }

    $data = json_decode($body, true);
    if ($code !== 200 || empty($data['CheckoutRequestID'])) {
        error_log("mpesa_stk_push failed — HTTP $code | body: $body");
        return $data ?: null; // still return so caller can surface ResponseDescription/errorMessage
    }

    return $data;
}

// ── 4. Optional: actively query Daraja for the status of a pushed STK request ──
// (Used as a fallback in case the callback is delayed/unreachable.)
function mpesa_query_stk_status(string $checkoutRequestId): ?array
{
    $token = mpesa_get_access_token();
    if (!$token) return null;

    $timestamp = date('YmdHis');
    $password  = base64_encode(MPESA_SHORTCODE . MPESA_PASSKEY . $timestamp);

    $payload = json_encode([
        'BusinessShortCode' => MPESA_SHORTCODE,
        'Password'          => $password,
        'Timestamp'         => $timestamp,
        'CheckoutRequestID' => $checkoutRequestId,
    ]);

    $ch = curl_init(MPESA_BASE_URL . '/mpesa/stkpushquery/v1/query');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $token",
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 20,
    ]);

    $body = curl_exec($ch);
    curl_close($ch);
    return json_decode($body, true);
}

// ── 5. Auto-generate a receipt number — same format financial_dashboard.php uses ──
function mpesa_generate_receipt($conn): string
{
    $res = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM fee_payments"));
    return 'RCP-' . date('Y') . '-' . str_pad(($res['c'] + 1), 5, '0', STR_PAD_LEFT);
}

// ── 6. Shared "write the payment + resync statuses" logic ───────────────────
/**
 * Records a completed M-Pesa payment into fee_payments (same table & shape the
 * Finance Office's manual "Record Payment" form writes to) and recalculates
 * every student_fee_assignments row for that student — identical to the
 * redistribution block in financial_dashboard.php's record_payment action.
 * Because it's the same table, this is what makes the student dashboard AND
 * the finance dashboard update automatically & consistently, with zero
 * changes needed on the finance dashboard itself.
 *
 * @return array ['receipt_number' => string, 'payment_id' => int]
 */
function mpesa_record_successful_payment(
    $conn,
    int $student_id,
    ?int $fee_assignment_id,
    float $amount_paid,
    string $mpesa_receipt,
    string $phone,
    string $payment_date = null
): array {
    $payment_date = $payment_date ?: date('Y-m-d');
    $receipt      = mpesa_generate_receipt($conn);
    $assign_val   = $fee_assignment_id ?: null;
    $notes        = 'Auto via M-Pesa STK Push · Phone: ' . mysqli_real_escape_string($conn, $phone);
    $txn_ref      = mysqli_real_escape_string($conn, $mpesa_receipt);
    $notes_esc    = mysqli_real_escape_string($conn, $notes);

    // "recorded_by" — attribute M-Pesa auto-payments to the system Finance account
    // if one exists, so financial_dashboard.php's joins (which expect recorded_by)
    // still resolve to a real user without any changes on that end.
    $sys_recorder = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id FROM users WHERE email='finance@smartlms.com' LIMIT 1"));
    $recorded_by  = $sys_recorder ? intval($sys_recorder['id']) : $student_id;

    mysqli_query($conn, "INSERT INTO fee_payments
        (student_id, fee_assignment_id, amount_paid, payment_method, transaction_ref, receipt_number, notes, payment_date, recorded_by)
        VALUES ($student_id," . ($assign_val ? intval($assign_val) : 'NULL') . ",$amount_paid,'mpesa','$txn_ref','$receipt','$notes_esc','$payment_date',$recorded_by)");

    $payment_id = mysqli_insert_id($conn);

    // ── Redistribute ALL payments (linked + general) across fee assignments — same as financial_dashboard.php ──
    $total_paid_global = floatval(mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COALESCE(SUM(amount_paid),0) AS t FROM fee_payments WHERE student_id=$student_id"))['t']);
    $assignments_all = mysqli_query($conn,
        "SELECT id, net_amount FROM student_fee_assignments WHERE student_id=$student_id AND status != 'waived' ORDER BY due_date ASC, assigned_at ASC");
    $rem = $total_paid_global;
    while ($arow = mysqli_fetch_assoc($assignments_all)) {
        $net = floatval($arow['net_amount']);
        $aid = intval($arow['id']);
        if ($rem >= $net) {
            mysqli_query($conn, "UPDATE student_fee_assignments SET status='paid' WHERE id=$aid");
            $rem -= $net;
        } elseif ($rem > 0) {
            mysqli_query($conn, "UPDATE student_fee_assignments SET status='partial' WHERE id=$aid");
            $rem = 0;
        } else {
            $row2 = mysqli_fetch_assoc(mysqli_query($conn, "SELECT due_date FROM student_fee_assignments WHERE id=$aid"));
            $new_stat = (!empty($row2['due_date']) && strtotime($row2['due_date']) < time()) ? 'overdue' : 'pending';
            mysqli_query($conn, "UPDATE student_fee_assignments SET status='$new_stat' WHERE id=$aid");
        }
    }

    return ['receipt_number' => $receipt, 'payment_id' => $payment_id];
}
