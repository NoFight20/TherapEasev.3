<?php
include("../connection.php");

// We now support BOTH:
// - ?puid=PATIENT_UID   (preferred, used by your JS)
// - ?key=FIREBASE_NODE  (legacy fallback)
$puid = $_GET['puid'] ?? null;
$key  = $_GET['key']  ?? null;

function is_assoc_array(array $arr): bool {
    return array_keys($arr) !== range(0, count($arr) - 1);
}

/**
 * Converts a node that may be keyed by push IDs into
 * a flat list, preserving the key as "_id".
 */
function mapWithId($node) {
    $out = [];
    if (!is_array($node)) return $out;

    if (is_assoc_array($node)) {
        // e.g. { "-Nx1": {...}, "-Nx2": {...} }
        foreach ($node as $k => $v) {
            if (is_array($v)) {
                $v['_id'] = $k; // keep Firebase child key
            }
            $out[] = $v;
        }
    } else {
        // e.g. [ {...}, {...} ]
        foreach ($node as $v) {
            if (is_array($v)) {
                $v['_id'] = '';
            }
            $out[] = $v;
        }
    }
    return $out;
}

/**
 * Resolve a Firebase "patients" node key from a patient_uid.
 */
function findPatientKeyByUID($database, string $patientUid): ?string {
    if ($patientUid === '') return null;

    $snap = $database->getReference('patients')
        ->orderByChild('patient_uid')
        ->equalTo($patientUid)
        ->getSnapshot();

    $val = $snap->getValue();
    if (!$val || !is_array($val)) return null;

    $keys = array_keys($val);
    return $keys[0] ?? null;
}

// ----------------- Resolve patient key -----------------
$patientKey = null;

// Prefer patient_uid (new flow)
if (!empty($puid)) {
    $patientKey = findPatientKeyByUID($database, $puid);
}

// Fallback to direct key (legacy)
if (!$patientKey && !empty($key)) {
    $patientKey = $key;
}

if (!$patientKey) {
    echo json_encode(['error' => 'Invalid patient identifier (missing puid/key).']);
    exit;
}

try {
    // 1) Load medical_record under patients/{patientKey}
    $ref  = $database->getReference("patients/{$patientKey}/medical_record");
    $data = $ref->getValue() ?: [];

    // Normalize sections so JS always gets an array
    $data['medications']         = isset($data['medications'])         ? mapWithId($data['medications'])         : [];
    $data['surgical_procedures'] = isset($data['surgical_procedures']) ? mapWithId($data['surgical_procedures']) : [];
    $data['illnesses']           = isset($data['illnesses'])           ? mapWithId($data['illnesses'])           : [];
    $data['vaccinations']        = isset($data['vaccinations'])        ? mapWithId($data['vaccinations'])        : [];

    // ----------------- NEW: attach laboratory_results -----------------
    $labResults = [];

    // Get patient node first so we can read email & uid
    $patientNode   = $database->getReference("patients/{$patientKey}")->getValue() ?: [];
    $patientEmail  = $patientNode['email']       ?? null;
    $patientUidRec = $patientNode['patient_uid'] ?? $puid; // fallback to request puid

    // helper to merge lab nodes without duplicates
    $appendLabs = function ($set, &$labResults) {
        if (!$set || !is_array($set)) return;
        foreach ($set as $labKey => $lab) {
            if (!is_array($lab)) continue;
            // use labKey as unique
            $lab['_id'] = $labKey;
            $labResults[$labKey] = [
                '_id'                => $lab['_id']              ?? $labKey,
                'test_type'          => $lab['test_type']        ?? 'Laboratory Test',
                'status'             => $lab['status']           ?? 'pending',
                'expected_ready_date'=> $lab['expected_ready_date'] ?? ($lab['ready_date'] ?? ''),
                'ready_time'         => $lab['ready_time']       ?? '',
                'requesting_doctor'  => $lab['requesting_doctor']?? 'Laboratory',
            ];
        }
    };

    // 2a) by patient_email (if available)
    if (!empty($patientEmail)) {
        $labSnapEmail = $database->getReference('laboratory_results')
            ->orderByChild('patient_email')
            ->equalTo($patientEmail)
            ->getSnapshot();

        $labByEmail = $labSnapEmail->getValue();
        $appendLabs($labByEmail, $labResults);
    }

    // 2b) by patient_uid (if available)
    if (!empty($patientUidRec)) {
        $labSnapUid = $database->getReference('laboratory_results')
            ->orderByChild('patient_uid')
            ->equalTo($patientUidRec)
            ->getSnapshot();

        $labByUid = $labSnapUid->getValue();
        $appendLabs($labByUid, $labResults);
    }

    // Re-index as a numeric array for the frontend
    $data['lab_results'] = array_values($labResults);

    echo json_encode($data);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
