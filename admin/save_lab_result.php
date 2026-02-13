<?php
session_name('sess_admin'); session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user']) || ($_SESSION['usertype'] ?? '') !== 'a') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

include("../connection.php");
date_default_timezone_set('Asia/Manila');

$adminEmail = $_SESSION['user'] ?? '';

$patientUid   = trim($_POST['patient_uid']   ?? '');
$patientEmail = trim($_POST['patient_email'] ?? '');
$testType     = trim($_POST['test_type']     ?? '');
$status       = trim($_POST['status']        ?? 'pending');
$reqDate      = trim($_POST['requested_date'] ?? '');
$expDate      = trim($_POST['expected_ready_date'] ?? '');
$readyTime    = trim($_POST['ready_time']    ?? '');
$reqDoctor    = trim($_POST['requesting_doctor'] ?? '');
$labEmail     = trim($_POST['lab_email']     ?? $adminEmail);

if ($patientUid === '' || $patientEmail === '' || $testType === '') {
    echo json_encode([
        'success' => false,
        'error'   => 'Missing required fields (patient, email, or test type).'
    ]);
    exit;
}

// Fetch patient name for logs (optional)
$patientName = '';
try {
    $snap = $database->getReference('patients')
        ->orderByChild('patient_uid')
        ->equalTo($patientUid)
        ->getSnapshot();
    $val = $snap->getValue();
    if ($val && is_array($val)) {
        $first = reset($val);
        $patientName = $first['name'] ?? trim(($first['fname'] ?? '').' '.($first['lname'] ?? ''));
    }
} catch (\Throwable $e) {
    // just ignore for now
}

try {
    $payload = [
        'patient_uid'        => $patientUid,
        'patient_email'      => $patientEmail,
        'test_type'          => $testType,
        'status'             => $status,
        'requested_date'     => $reqDate,
        'expected_ready_date'=> $expDate,
        'ready_time'         => $readyTime,
        'requesting_doctor'  => $reqDoctor,
        'lab_email'          => $labEmail,
        'created_at'         => time(),
    ];

    $database->getReference('laboratory_results')->push($payload);

    // Log action
    $database->getReference('logs')->push([
        'action'      => 'add_lab_result',
        'email'       => $adminEmail,
        'patientId'   => $patientUid,
        'patientName' => $patientName,
        'timestamp'   => date('H:i:s'),
        'datestamp'   => date('Y-m-d'),
        'status'      => 'Added lab result: '.$testType,
    ]);

    echo json_encode(['success' => true]);
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
