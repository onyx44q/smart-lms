<?php
/**
 * check_mpesa_status.php — polled by the student dashboard (every ~3s) after
 * an STK push is initiated, to find out once mpesa_callback.php (or, in
 * DEMO_MODE, the simulation below) has resolved the transaction.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
include 'config.php';
require_once 'mpesa_helper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$student_id   = intval($_SESSION['user_id']);
$checkout_id  = mysqli_real_escape_string($conn, trim($_GET['checkout_request_id'] ?? $_POST['checkout_request_id'] ?? ''));

if (!$checkout_id) {
    echo json_encode(['status' => 'error', 'message' => 'Missing checkout_request_id']);
    exit();
}

$txn = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT * FROM mpesa_transactions WHERE checkout_request_id='$checkout_id' AND student_id=$student_id LIMIT 1"));

if (!$txn) {
    echo json_encode(['status' => 'error', 'message' => 'Transaction not found']);
    exit();
}

// ── DEMO MODE: auto-complete the simulated push a few seconds after creation ──
if ($txn['status'] === 'pending' && MPESA_DEMO_MODE && strpos($checkout_id, 'DEMO-') === 0) {
    $created_ts = strtotime($txn['created_at']);
    if (time() - $created_ts >= 5) {
        $demo_receipt = 'DEMO' . strtoupper(substr(md5($checkout_id), 0, 8));
        $result = mpesa_record_successful_payment(
            $conn,
            intval($txn['student_id']),
            $txn['fee_assignment_id'] ? intval($txn['fee_assignment_id']) : null,
            floatval($txn['amount']),
            $demo_receipt,
            $txn['phone']
        );
        mysqli_query($conn, "UPDATE mpesa_transactions SET
            status='success', result_code=0, result_desc='Demo payment simulated',
            mpesa_receipt_number='$demo_receipt', fee_payment_id=" . intval($result['payment_id']) . "
            WHERE id=" . intval($txn['id']));
        $txn['status'] = 'success';
        $txn['mpesa_receipt_number'] = $demo_receipt;
    }
}

$response = ['status' => $txn['status']];

switch ($txn['status']) {
    case 'success':
        $response['message']        = 'Payment received! Your balance has been updated.';
        $response['receipt_number'] = $txn['mpesa_receipt_number'];
        $response['amount']         = floatval($txn['amount']);
        break;
    case 'failed':
        $response['message'] = $txn['result_desc'] ?: 'Payment failed. Please try again.';
        break;
    case 'cancelled':
        $response['message'] = 'Payment was cancelled or the prompt timed out.';
        break;
    default:
        $response['message'] = 'Waiting for confirmation on your phone…';
}

echo json_encode($response);
