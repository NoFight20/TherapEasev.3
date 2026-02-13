<?php
session_name('sess_patient'); 
session_start();

include("../connection.php");

// Logs reference
$logsRef = $database->getReference('logs');

$timestamp = date('H:i:s'); 
$datestamp = date('Y-m-d');

// Validate appointment ID
$id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_STRING);

if (!$id) {
    $_SESSION['error'] = "Invalid appointment ID.";

    $logsRef->push([
        'email' => $_SESSION["user"] ?? 'Unknown',
        'action' => 'Cancel Appointment',
        'appointment_id' => $id ?? 'None',
        'status' => 'Failed: Invalid ID',
        'timestamp' => $timestamp,
        'datestamp' => $datestamp
    ]);

    header("location: appointment.php");
    exit();
}

$useremail = $_SESSION["user"] ?? null;

if (!$useremail) {
    $_SESSION['error'] = "User not logged in.";

    $logsRef->push([
        'email' => 'Unknown',
        'action' => 'Cancel Appointment',
        'appointment_id' => $id,
        'status' => 'Failed: User not logged in',
        'timestamp' => $timestamp,
        'datestamp' => $datestamp
    ]);

    header("location: ../login.php");
    exit();
}

/* 
=====================================
 NEW CORRECT COLLECTION NAME
 appointments/{id}
=====================================
*/
$appointmentRef = $database->getReference("appointments/{$id}");
$appointment = $appointmentRef->getValue();

if (!$appointment) {

    $_SESSION['error'] = "Appointment not found.";

    $logsRef->push([
        'email' => $useremail,
        'action' => 'Cancel Appointment',
        'appointment_id' => $id,
        'status' => 'Failed: Not Found',
        'timestamp' => $timestamp,
        'datestamp' => $datestamp
    ]);

    header("location: appointment.php");
    exit();
}

// Verify owner
if (($appointment['patientEmail'] ?? '') !== $useremail) {
    $_SESSION['error'] = "You do not have permission to cancel this booking.";

    $logsRef->push([
        'email' => $useremail,
        'action' => 'Cancel Appointment',
        'appointment_id' => $id,
        'status' => 'Failed: Unauthorized',
        'timestamp' => $timestamp,
        'datestamp' => $datestamp
    ]);

    header("location: appointment.php");
    exit();
}

/* ========= CANCEL & ARCHIVE ========= */
$archiveRef = $database->getReference("appointment_cancellations/{$id}");
$archiveRef->set($appointment);

// Remove from active appointments
$appointmentRef->remove();

/* ========= FIX DOCTOR & PATIENT LINKS ========== */
$docId     = $appointment['doctorId']   ?? null;
$patientId = $appointment['patientId']  ?? null;
$slotKey   = $appointment['slotKey']    ?? null;
$sessKey   = $appointment['sessionKey'] ?? null;

// Remove from doctor session bookings
if ($docId && $sessKey) {
    $database->getReference("doctor/{$docId}/sessions/{$sessKey}/bookings/{$id}")->remove();
}

// Remove from doctor queue
if ($docId && $slotKey) {
    $database->getReference("doctor/{$docId}/queues/{$slotKey}/appointments/{$id}")->remove();
}

// Remove from patient's booked slots
if ($patientId && $slotKey) {
    $database->getReference("patients/{$patientId}/slots/{$slotKey}")->remove();
}

// Remove from patient's appointment list
if ($patientId) {
    $database->getReference("patients/{$patientId}/appointments/{$id}")->remove();
}

/* ========= LOG SUCCESS ========= */
$logsRef->push([
    'email' => $useremail,
    'action' => 'Cancel Appointment',
    'appointment_id' => $id,
    'status' => 'Success',
    'timestamp' => $timestamp,
    'datestamp' => $datestamp
]);

$_SESSION['message'] = "Booking successfully canceled.";
header("location: appointment.php");
exit();
?>
