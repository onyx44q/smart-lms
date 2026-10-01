<?php
/**
 * mpesa_callback.php — Daraja STK Push callback endpoint
 *
 * Safaricom's servers POST here (NOT the student's browser) once the
 * customer enters their PIN or cancels/times out. This file:
 *   1. Has no session/login check — Safaricom can't log in, this must be public
 *   2. Updates the matching mpesa_transactions row
 *   3. On success, writes into fee_payments via mpesa_record_successful_payment()
 *      — the exact same table & redistribution logic the Finance Office's
 *      manual "Record Payment" form uses, so both dashboards update
 *      automatically with zero changes needed on the finance side.
 *
 * Must be reachable at a public HTTPS URL (see MPESA_CALLBACK_URL in
 * mpesa_config.php) — during local development, tunnel it with ngrok.
 */
include 'config.php';
require_once 'mpesa_helper.php';

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
error_log("mpesa_callback raw payload: " . $raw);

$data = json_decode($raw, true);
$stk  = $data['Body']['stkCallback'] ?? null;

if (!$stk || !isset($stk['CheckoutRequestID'])) {
    http_response_code(400);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Invalid payload']);
    exit();
}

$checkout_id  = mysqli_real_escape_string($conn, $stk['CheckoutRequestID']);
$result_code  = intval($stk['ResultCode']);
$result_desc  = mysqli_real_escape_string($conn, $stk['ResultDesc'] ?? '');

$txn = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT * FROM mpesa_transactions WHERE checkout_request_id='$checkout_id' LIMIT 1"));

if (!$txn) {
    error_log("mpesa_callback: no matching transaction for CheckoutRequestID=$checkout_id");
    // Still ack 200 so Daraja doesn't retry forever
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    exit();
}

// Already processed (Daraja can retry callbacks) — ack and stop.
if ($txn['status'] !== 'pending') {
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Already processed']);
    exit();
}

if ($result_code === 0) {
    // ── SUCCESS ── pull the receipt number + amount from CallbackMetadata ──
    $meta = $stk['CallbackMetadata']['Item'] ?? [];
    $lookup = [];
    foreach ($meta as $item) {
        if (isset($item['Name'])) $lookup[$item['Name']] = $item['Value'] ?? null;
    }
    $mpesa_receipt = $lookup['MpesaReceiptNumber'] ?? ('MP' . time());
    $amount_paid   = floatval($lookup['Amount'] ?? $txn['amount']);
    $phone_used    = strval($lookup['PhoneNumber'] ?? $txn['phone']);

    $result = mpesa_record_successful_payment(
        $conn,
        intval($txn['student_id']),
        $txn['fee_assignment_id'] ? intval($txn['fee_assignment_id']) : null,
        $amount_paid,
        $mpesa_receipt,
        $phone_used
    );

    $receipt_esc = mysqli_real_escape_string($conn, $result['receipt_number']);
    mysqli_query($conn, "UPDATE mpesa_transactions SET
        status='success',
        result_code=$result_code,
        result_desc='$result_desc',
        mpesa_receipt_number='" . mysqli_real_escape_string($conn, $mpesa_receipt) . "',
        fee_payment_id=" . intval($result['payment_id']) . "
        WHERE id=" . intval($txn['id']));

} else {
    // ── FAILED / CANCELLED (e.g. wrong PIN, insufficient funds, timeout=1032) ──
    $status = ($result_code === 1032) ? 'cancelled' : 'failed';
    mysqli_query($conn, "UPDATE mpesa_transactions SET
        status='$status', result_code=$result_code, result_desc='$result_desc'
        WHERE id=" . intval($txn['id']));
}

// Daraja just needs a 200 + this ack shape — it does not care about the body content
echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
