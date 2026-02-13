<?php
session_name('sess_admin'); session_start();

if (!isset($_SESSION['user']) || $_SESSION['usertype'] !== 'a') {
    header('Location: ../login.php'); exit();
}

require_once '../connection.php';
date_default_timezone_set('Asia/Manila');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error_message'] = 'Invalid request method.';
    header('Location: index.php'); exit();
}

$apptId = trim($_POST['appointment_id'] ?? '');
$action = trim($_POST['action'] ?? '');
if ($apptId === '' || !in_array($action, ['approve','reject'], true)) {
    $_SESSION['error_message'] = 'Missing or invalid parameters.';
    header('Location: index.php'); exit();
}

$actorEmail = $_SESSION['user']     ?? 'unknown@system';
$actorType  = $_SESSION['usertype'] ?? ''; // e.g. "a"
$nowDT      = new DateTime('now', new DateTimeZone('Asia/Manila'));
$nowDate    = $nowDT->format('Y-m-d');
$nowTime    = $nowDT->format('H:i:s');
$nowStamp   = $nowDT->format('Y-m-d H:i:s');

// --- 1) Simple log (like your screenshot)
$log = function(string $statusText) use ($database, $actorEmail, $actorType, $nowDate, $nowTime) {
    try {
        $database->getReference('logs')->push([
            'datestamp' => $nowDate,
            'email'     => $actorEmail,
            'status'    => $statusText,
            'timestamp' => $nowTime,
            'type'      => $actorType,
        ]);
    } catch (\Throwable $e) {
        // ignore errors
    }
};

// --- 2) Internal log (full audit details, dev/debug use)
$logInternal = function(string $action, array $context = []) use ($database, $actorEmail, $nowStamp, $nowDate, $nowTime) {
    try {
        $database->getReference('logs_internal')->push(array_merge([
            'action'    => $action,
            'actor'     => $actorEmail,
            'datestamp' => $nowDate,
            'timestamp' => $nowTime,
            'fullstamp' => $nowStamp,
            'level'     => 'info',
        ], $context));
    } catch (\Throwable $e) {
        // ignore errors
    }
};

try {
    // Locate appointment (new vs legacy path)
    $targetPath = "appointments/{$apptId}";
    $snap = $database->getReference($targetPath)->getSnapshot();
    if (!$snap->exists()) {
        $legacyPath = "appointment/{$apptId}";
        $snap = $database->getReference($legacyPath)->getSnapshot();
        if ($snap->exists()) {
            $targetPath = $legacyPath;
        } else {
            $log('appointment_not_found', ['level' => 'error']);
            $_SESSION['error_message'] = 'Appointment not found.';
            header('Location: index.php'); exit();
        }
    }

    $appt = $snap->getValue() ?: [];

    // Normalize commonly used fields
    $doctorId   = $appt['doctorId']   ?? ($appt['docid'] ?? null);
    $patientId  = $appt['patientId']  ?? null;
    $sessionKey = $appt['sessionKey'] ?? null;
    $slotKey    = $appt['slotKey']    ?? null;

    if ($action === 'approve') {
        // --- APPROVE: just set status=confirmed
       $database->getReference($targetPath)->update([
    'status' => 'confirmed',
    'updatedAt' => $nowStamp,
        ]);
        $log('Appointment Approved');
        $logInternal('appointment_status_update', [
            'from' => (string)($appt['status'] ?? 'pending'),
            'to'   => 'confirmed',
            'path' => $targetPath,
        ]);

        $_SESSION['success_message'] = 'Appointment approved.';
        header('Location: index.php'); exit();
    }

    // 1) Archive first (copy original + metadata)
    $archivePayload = $appt;
    $archivePayload['status']        = 'rejected';
    $archivePayload['archivedAt']    = $nowStamp;
    $archivePayload['archivedBy']    = $actorEmail;
    $archivePayload['archiveReason'] = 'rejected_by_admin';
    $archivePayload['originalPath']  = $targetPath;

    try {
       $database->getReference("archives/appointments/{$apptId}")->set($archivePayload);
        $log('Appointment Rejected & Archived');
        $logInternal('appointment_archived', [
            'apptId' => $apptId,
            'path'   => "archives/appointments/{$apptId}",
        ]);
    } catch (\Throwable $e) {
        // If archive fails, don't delete the live record; mark it rejected as fallback
        $database->getReference($targetPath)->update([
            'status'    => 'rejected',
            'updatedAt' => $nowStamp,
            'rejectedAt'=> $nowStamp,
            'rejectedBy'=> $actorEmail,
        ]);
        $log('appointment_archive_failed', ['level' => 'error', 'message' => $e->getMessage()]);
        $_SESSION['error_message'] = 'Archiving failed; appointment kept with status=rejected.';
        header('Location: index.php'); exit();
    }

    // 2) Cleanup denormalized pointers and slot/queue indexes
    // 2a) Session bookings index
    if ($doctorId && $sessionKey) {
        try {
            $database->getReference("doctor/{$doctorId}/sessions/{$sessionKey}/bookings/{$apptId}")->set(null);
            $log('appointment_cleanup_session_booking', ['level' => 'info', 'doctorId' => $doctorId, 'sessionKey' => $sessionKey]);
        } catch (\Throwable $e) {
            $log('appointment_cleanup_session_booking_failed', ['level' => 'error', 'message' => $e->getMessage()]);
        }
    }

    // 2b) Patient slot guard (allows rebooking)
    if ($patientId && $slotKey) {
        try {
            $database->getReference("patients/{$patientId}/slots/{$slotKey}")->set(null);
            $log('appointment_cleanup_patient_slot', ['level' => 'info', 'patientId' => $patientId, 'slotKey' => $slotKey]);
        } catch (\Throwable $e) {
            $log('appointment_cleanup_patient_slot_failed', ['level' => 'error', 'message' => $e->getMessage()]);
        }
    }

    // 2c) Queue map entry
    if ($doctorId && $slotKey) {
        try {
            $database->getReference("doctor/{$doctorId}/queues/{$slotKey}/appointments/{$apptId}")->set(null);
            $log('appointment_cleanup_queue_map', ['level' => 'info', 'doctorId' => $doctorId, 'slotKey' => $slotKey]);
        } catch (\Throwable $e) {
            $log('appointment_cleanup_queue_map_failed', ['level' => 'error', 'message' => $e->getMessage()]);
        }
    }

    // 2d) Denormalized appointment links
    if ($patientId) {
        try {
            $database->getReference("patients/{$patientId}/appointments/{$apptId}")->set(null);
            $log('appointment_cleanup_patient_appt_link', ['level' => 'info', 'patientId' => $patientId]);
        } catch (\Throwable $e) {
            $log('appointment_cleanup_patient_appt_link_failed', ['level' => 'error', 'message' => $e->getMessage()]);
        }
    }
    if ($doctorId) {
        try {
            $database->getReference("doctor/{$doctorId}/appointments/{$apptId}")->set(null);
            $log('appointment_cleanup_doctor_appt_link', ['level' => 'info', 'doctorId' => $doctorId]);
        } catch (\Throwable $e) {
            $log('appointment_cleanup_doctor_appt_link_failed', ['level' => 'error', 'message' => $e->getMessage()]);
        }
    }

    // 3) Remove the live appointment record from its node
    try {
        $database->getReference($targetPath)->set(null);
        $log('appointment_removed_from_live', ['level' => 'info', 'path' => $targetPath]);
    } catch (\Throwable $e) {
        $log('appointment_remove_live_failed', ['level' => 'error', 'message' => $e->getMessage(), 'path' => $targetPath]);
        $_SESSION['error_message'] = 'Archived, but failed to remove from live node.';
        header('Location: index.php'); exit();
    }

    $_SESSION['success_message'] = 'Appointment rejected, archived, and removed from live list.';
    header('Location: index.php'); exit();

} catch (Throwable $e) {
    $log('appointment_status_update_unhandled_exception', ['level' => 'error', 'message' => $e->getMessage()]);
    $_SESSION['error_message'] = 'Update failed: '.$e->getMessage();
    header('Location: index.php'); exit();
}
