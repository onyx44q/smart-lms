<?php
/**
 * initiate_mpesa_payment.php — Student clicks "Pay with M-Pesa", enters a
 * phone number + amount in the modal, this fires the STK push and logs a
 * pending mpesa_transactions row. The dashboard then polls
 * check_mpesa_status.php until Safaricom's callback (mpesa_callback.php)
 * marks it success/failed.
 *
 * The phone number is asked for HERE, at payment time — it is never taken
 * from signup and is not required to be saved anywhere permanent.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
include 'config.php';
require_once 'mpesa_helper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$student_id = intval($_SESSION['user_id']);
$phone_raw  = trim($_POST['phone'] ?? '');
$amount     = floatval($_POST['amount'] ?? 0);
$fee_assignment_id = intval($_POST['fee_assignment_id'] ?? 0) ?: null;

// ── Validate phone ────────────────────────────────────────────────────
$phone = mpesa_format_phone($phone_raw);
if (!$phone) {
    echo json_encode(['success' => false, 'message' => 'Enter a valid Safaricom number, e.g. 07XXXXXXXX or 2547XXXXXXXX.']);
    exit();
}

// ── Validate amount against what's actually owed ─────────────────────
if ($amount < 1) {
    echo json_encode(['success' => false, 'message' => 'Enter an amount greater than 0.']);
    exit();
}

if ($fee_assignment_id) {
    // Paying a specific fee line — must belong to this student
    $fa = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT sfa.*, fs.name AS fee_name FROM student_fee_assignments sfa
         JOIN fee_structures fs ON fs.id = sfa.fee_structure_id
         WHERE sfa.id=$fee_assignment_id AND sfa.student_id=$student_id LIMIT 1"));
    if (!$fa) {
        echo json_encode(['success' => false, 'message' => 'Fee item not found.']);
        exit();
    }
    $paid_so_far = floatval(mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COALESCE(SUM(amount_paid),0) t FROM fee_payments WHERE fee_assignment_id=$fee_assignment_id"))['t']);
    $line_balance = max(0, floatval($fa['net_amount']) - $paid_so_far);
    if ($amount > $line_balance + 0.5) {
        echo json_encode(['success' => false, 'message' => 'Amount exceeds the balance for this fee item (KES ' . number_format($line_balance, 2) . ').']);
        exit();
    }
    $account_ref = 'FEE-' . $fee_assignment_id;
    $description = substr($fa['fee_name'], 0, 13);
} else {
    // General payment toward overall balance
    $total_fees = floatval(mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COALESCE(SUM(net_amount),0) t FROM student_fee_assignments WHERE student_id=$student_id AND status != 'waived'"))['t']);
    $total_paid = floatval(mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COALESCE(SUM(amount_paid),0) t FROM fee_payments WHERE student_id=$student_id"))['t']);
    $overall_balance = max(0, $total_fees - $total_paid);
    if ($overall_balance > 0 && $amount > $overall_balance + 0.5) {
        echo json_encode(['success' => false, 'message' => 'Amount exceeds your total outstanding balance (KES ' . number_format($overall_balance, 2) . ').']);
        exit();
    }
    $account_ref = 'STU-' . str_pad($student_id, 5, '0', STR_PAD_LEFT);
    $description = 'School Fees';
}

// ── DEMO MODE: no real Daraja credentials configured yet ─────────────
if (MPESA_DEMO_MODE) {
    $checkout_id = 'DEMO-' . uniqid();
    mysqli_query($conn, "INSERT INTO mpesa_transactions
        (student_id, fee_assignment_id, phone, amount, account_reference, checkout_request_id, status)
        VALUES ($student_id," . ($fee_assignment_id ?: 'NULL') . ",'$phone',$amount,'$account_ref','$checkout_id','pending')");

    echo json_encode([
        'success' => true,
        'demo' => true,
        'checkout_request_id' => $checkout_id,
        'message' => 'Demo mode: no real Daraja credentials configured. Simulating an STK push — this will auto-complete in a few seconds so you can see the full flow.',
    ]);
    exit();
}

// ── LIVE MODE ──────────────────────────────────────────────────────────
$response = mpesa_stk_push($phone, $amount, $account_ref, $description);

if (!$response || empty($response['CheckoutRequestID'])) {
    $errMsg = $response['errorMessage'] ?? $response['ResponseDescription'] ?? 'Could not reach M-Pesa. Please try again.';
    echo json_encode(['success' => false, 'message' => $errMsg]);
    exit();
}

$checkout_id  = mysqli_real_escape_string($conn, $response['CheckoutRequestID']);
$merchant_id  = mysqli_real_escape_string($conn, $response['MerchantRequestID'] ?? '');

mysqli_query($conn, "INSERT INTO mpesa_transactions
    (student_id, fee_assignment_id, phone, amount, account_reference, merchant_request_id, checkout_request_id, status)
    VALUES ($student_id," . ($fee_assignment_id ?: 'NULL') . ",'$phone',$amount,'$account_ref','$merchant_id','$checkout_id','pending')");

echo json_encode([
    'success' => true,
    'demo' => false,
    'checkout_request_id' => $checkout_id,
    'message' => 'Check your phone and enter your M-Pesa PIN to complete payment.',
]);
