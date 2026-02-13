<?php
session_name('sess_admin'); session_start();

if (!isset($_SESSION['user']) || $_SESSION['usertype'] !== 'a') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}
if (isset($_SESSION['canEditMedicalForm']) && $_SESSION['canEditMedicalForm'] === false) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No edit permission']);
    exit();
}

header('Content-Type: application/json');
include("../connection.php");
date_default_timezone_set('Asia/Manila');

$payload     = json_decode(file_get_contents('php://input'), true);
$patientKey  = $payload['patient_key']  ?? '';
$section     = $payload['section']      ?? '';
$rowId       = $payload['row_id']       ?? '';
$patientName = trim($payload['patient_name'] ?? '');

if ($patientKey === '' || $section === '' || $rowId === '') {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit();
}

try {
    $srcPath = "patients/{$patientKey}/medical_record/{$section}/{$rowId}";
    $srcRef  = $database->getReference($srcPath);
    $rowData = $srcRef->getValue();

    if ($rowData === null) {
        echo json_encode(['success' => false, 'error' => 'Row not found']);
        exit();
    }

    // Best-effort fallback to fetch name if not provided
    if ($patientName === '') {
        try {
            $p = $database->getReference("patients/{$patientKey}")->getValue();
            if (is_array($p)) {
                if (!empty($p['name'])) {
                    $patientName = $p['name'];
                } else {
                    $fn = $p['fname'] ?? ($p['first_name'] ?? '');
                    $ln = $p['lname'] ?? ($p['last_name'] ?? '');
                    $combo = trim($fn . ' ' . $ln);
                    if ($combo !== '') $patientName = $combo;
                }
            }
        } catch (Exception $e) {
            // ignore fallback failure
        }
    }

    // add archive metadata (including patient's name)
    $rowData['_archived_at']   = date('Y-m-d H:i:s');
    $rowData['_archived_by']   = $_SESSION['user'];
    $rowData['_patient_name']  = $patientName;

    // write to archive bucket
    $archivePath = "archive_rows/{$patientKey}/{$section}/{$rowId}";
    $database->getReference($archivePath)->set($rowData);

    // delete original
    $srcRef->remove();

    // log
    try {
        $database->getReference('logs')->push([
            'action'       => 'archive_row',
            'datestamp'    => date('Y-m-d'),
            'timestamp'    => date('H:i:s'),
            'patientId'    => $patientKey,
            'patientName'  => $patientName,
            'section'      => $section,
            'rowId'        => $rowId,
            'status'       => 'Archived single medical row.',
        ]);
    } catch (Exception $e) {
        error_log("Log write failed: " . $e->getMessage());
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
