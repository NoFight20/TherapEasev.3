<?php
session_name('sess_admin'); session_start();

include('../connection.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "<script>alert('Direct access is not allowed!'); window.location.href = 'bed.php';</script>";
    exit;
}

$timestamp = date('H:i:s'); 
$datestamp = date('Y-m-d'); 
$useremail = $_SESSION['user'] ?? 'system';

// Get POST data
$patientName  = trim($_POST['patientName'] ?? '');
$bedNumber    = $_POST['bedNumber'] ?? '';
$roomNumber   = $_POST['roomNumber'] ?? '';
$category     = $_POST['category'] ?? ''; 
$doctorId     = $_POST['doctor'] ?? '';

// NEW (optional inputs from your UI; safe if missing)
$patientEmail = strtolower(trim($_POST['patientEmail'] ?? '')); // optional
$patientIdInp = trim($_POST['patientId'] ?? '');                // optional, if your UI has it

// Validate required fields
if (!$patientName || !$bedNumber || !$roomNumber || !$category ) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
    exit;
}

// Fetch doctor data from Firebase to retrieve the doctor's name
$doctorRef = $database->getReference("doctor/$doctorId");
$doctorData = $doctorRef->getValue();
if (!$doctorData) {
    echo json_encode(['success' => false, 'message' => 'Doctor not found.']);
    exit;
}
$doctorName = $doctorData['name'] ?? 'Unknown';

// ================== Resolve/Create Patient ==================
$patientsRef  = $database->getReference('patients');
$patientId    = null;
$patientUid   = null;
$resolvedName = $patientName; // we'll keep the exact provided display name

// (A) If the UI gave us a concrete patient id, trust and hydrate it
if ($patientIdInp !== '') {
    $snap = $database->getReference("patients/{$patientIdInp}")->getSnapshot();
    if ($snap->exists()) {
        $patientId  = $patientIdInp;
        $rec        = $snap->getValue();
        $patientUid = $rec['patient_uid'] ?? null;

        // backfill missing fields if useful
        $patch = [];
        if (empty(trim((string)($rec['name'] ?? ''))) && $resolvedName !== '') $patch['name'] = $resolvedName;
        if ($patientEmail !== '' && empty(trim((string)($rec['email'] ?? '')))) $patch['email'] = $patientEmail;
        if (!$patientUid) { $patientUid = 'P-'.uniqid(); $patch['patient_uid'] = $patientUid; }
        if (!empty($patch)) { $database->getReference("patients/{$patientId}")->update($patch); }
    }
}

// (B) If not resolved yet, try exact email match (preferred unique key)
if ($patientId === null && $patientEmail !== '') {
    $byEmail = $patientsRef->orderByChild('email')->equalTo($patientEmail)->getValue() ?? [];
    if (!empty($byEmail)) {
        $patientId  = array_key_first($byEmail);
        $rec        = $byEmail[$patientId] ?? [];
        $patientUid = $rec['patient_uid'] ?? null;

        // backfill name / uid if missing
        $patch = [];
        if (empty(trim((string)($rec['name'] ?? ''))) && $resolvedName !== '') $patch['name'] = $resolvedName;
        if (!$patientUid) { $patientUid = 'P-'.uniqid(); $patch['patient_uid'] = $patientUid; }
        if (!empty($patch)) { $database->getReference("patients/{$patientId}")->update($patch); }
    }
}

// (C) If still not resolved, try exact name match (case-insensitive)
if ($patientId === null && $resolvedName !== '') {
    // Realtime DB can only orderBy one child—scan all
    $all = $patientsRef->getValue() ?? [];
    if (!empty($all) && is_array($all)) {
        foreach ($all as $pid => $p) {
            $pName = trim((string)($p['name'] ?? ''));
            if (strcasecmp($pName, $resolvedName) === 0) {
                $patientId  = $pid;
                $patientUid = $p['patient_uid'] ?? null;

                // backfill email / uid if missing
                $patch = [];
                if ($patientEmail !== '' && empty(trim((string)($p['email'] ?? '')))) $patch['email'] = $patientEmail;
                if (!$patientUid) { $patientUid = 'P-'.uniqid(); $patch['patient_uid'] = $patientUid; }
                if (!empty($patch)) { $database->getReference("patients/{$patientId}")->update($patch); }
                break;
            }
        }
    }
}

// (D) If still not found, create a brand-new patient node
if ($patientId === null) {
    // Optional: split into fname/lname for consistency
    $fname = $resolvedName;
    $lname = '';
    if (strpos($resolvedName, ' ') !== false) {
        $parts = preg_split('/\s+/', $resolvedName);
        $lname = array_pop($parts);
        $fname = implode(' ', $parts);
    }

    $patientUid = 'P-'.uniqid();
    $newPatient = [
        'fname'         => $fname,
        'lname'         => $lname,
        'name'          => $resolvedName,
        'email'         => $patientEmail,     // may be ''
        'patient_uid'   => $patientUid,
        'date_created'  => date('Y-m-d'),
    ];
    $patientId = $patientsRef->push($newPatient)->getKey();
}

if (!$patientId) {
    echo json_encode(['success' => false, 'message' => 'Could not resolve or create patient record.']);
    exit;
}
// ================== End Patient Resolve/Create ==================


try {
    $roomsRef = $database->getReference("rooms/$category");
    $roomsSnapshot = $roomsRef->getValue();

    if (!$roomsSnapshot) {
        echo json_encode(['success' => false, 'message' => "Category '$category' not found."]);
        exit;
    }

    foreach ($roomsSnapshot as $roomId => $roomData) {
        if (isset($roomData['room_number']) && strval($roomData['room_number']) === strval($roomNumber)) {
            if (isset($roomData['beds']) && is_array($roomData['beds'])) {
                foreach ($roomData['beds'] as $index => $bed) {
                    if (isset($bed['bed_number']) && strval($bed['bed_number']) === strval($bedNumber) && $bed['status'] === 'available') {
                       $database->getReference("rooms/$category/$roomId/beds/$index")->update([
                        'status'        => 'occupied',
                        // keep the old flat field for compatibility:
                        'patientName'   => $resolvedName,
                        // NEW: structured patient reference
                        'patient'       => [
                            'id'    => $patientId,
                            'uid'   => $patientUid,
                            'name'  => $resolvedName,
                            'email' => $patientEmail,
                        ],
                        'doctor'        => [
                            'id'   => $doctorId,
                            'name' => $doctorName,
                        ],
                        'date_admitted' => $datestamp,
                    ]);


                        $database->getReference('logs')->push([
                            'email'     => $useremail,
                            'status'    => "Assigned patient '$patientName' to Bed $bedNumber in Room $roomNumber ($category) with doctor $doctorName (ID: $doctorId).",
                            'timestamp' => $timestamp,
                            'datestamp' => $datestamp
                        ]);

                        echo "<script>alert('Patient assigned to Bed $bedNumber in Room $roomNumber ($category) with doctor assigned.'); window.location.href = 'bed.php';</script>";
                        exit;
                    }
                }
            } elseif (!isset($roomData['beds']) || empty($roomData['beds'])) {
                        $database->getReference("rooms/$category/$roomId")->update([
                'beds' => [[
                    'bed_number'    => 1,
                    'status'        => 'occupied',
                    'patientName'   => $resolvedName, // keep old field
                    'patient'       => [              // NEW structure
                        'id'    => $patientId,
                        'uid'   => $patientUid,
                        'name'  => $resolvedName,
                        'email' => $patientEmail,
                    ],
                    'doctor'        => [
                        'id'   => $doctorId,
                        'name' => $doctorName,
                    ],
                    'date_admitted' => $datestamp,
                ]]
            ]);


                $database->getReference('logs')->push([
                    'email'     => $useremail,
                    'status'    => "Assigned patient '{$resolvedName}' (PID: {$patientId}, PUID: {$patientUid}) to Bed $bedNumber in Room $roomNumber ($category) with doctor $doctorName (ID: $doctorId).",
                    'timestamp' => $timestamp,
                    'datestamp' => $datestamp
                ]);


                echo "<script>alert('Patient assigned to Single-Bed Room $roomNumber ($category) with doctor assigned.'); window.location.href = 'bed.php';</script>";
                exit;
            }
        }
    }

    echo "<script>alert('Bed $bedNumber in Room $roomNumber not found or already occupied.'); window.location.href = 'bed.php';</script>";
} catch (Exception $e) {
    echo "<script>alert('An error occurred: " . addslashes($e->getMessage()) . "'); window.location.href = 'bed.php';</script>";
}
?>
