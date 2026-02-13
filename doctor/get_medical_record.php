<?php
session_name('sess_doctor'); session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user']) || ($_SESSION['usertype'] ?? '') !== 'd') {
    echo json_encode(['error' => 'Unauthorized']); exit;
}

require_once '../connection.php';

// Helpers
function findPatientKeyByUID($database, string $patientUid): ?string {
    if ($patientUid === '') return null;
    $snap = $database->getReference('patients')->orderByChild('patient_uid')->equalTo($patientUid)->getSnapshot();
    $val  = $snap->getValue();
    if (!$val || !is_array($val)) return null;
    $keys = array_keys($val);
    return $keys[0] ?? null;
}
function normStr($v){ return is_string($v) ? trim($v) : ''; }

// Input: either ?puid=... OR ?key=...
$puid = isset($_GET['puid']) ? trim($_GET['puid']) : '';
$key  = isset($_GET['key'])  ? trim($_GET['key'])  : '';

try {
    // Resolve node key
    $nodeKey = $key;
    if ($nodeKey === '' && $puid !== '') {
        $nodeKey = findPatientKeyByUID($database, $puid) ?? '';
    }
    if ($nodeKey === '') {
        echo json_encode(['error' => 'Patient not found']); exit;
    }

    // 1) NEW schema under patients/{nodeKey}/medical_record
    $newMed = $database->getReference('patients/'.$nodeKey.'/medical_record')->getValue();

    // 2) LEGACY fallback under medical_records/{nodeKey}
    $legacy = $database->getReference('medical_records/'.$nodeKey)->getValue();

    // Merge (new overrides legacy)
    $merged = [];
    if (is_array($legacy)) $merged = $legacy;
    if (is_array($newMed)) $merged = array_replace_recursive($merged, $newMed);

    // Normalize physician {name, contact}
    $phys = ['name' => '', 'contact' => ''];
    if (isset($merged['physician'])) {
        if (is_array($merged['physician'])) {
            $phys['name']    = normStr($merged['physician']['name'] ?? '');
            $phys['contact'] = normStr($merged['physician']['contact'] ?? $merged['physician']['phone'] ?? '');
        } else {
            $phys['name'] = normStr($merged['physician']);
        }
    }

    // Normalize list helpers
    $mapList = function($arr, $kind){
        $out = [];
        if (!is_array($arr)) return $out;
        foreach ($arr as $row) {
            $row = is_array($row) ? $row : [];
            if ($kind === 'med') {
                $out[] = [
                    '_id'       => $row['_id'] ?? '',
                    'name'      => normStr($row['name'] ?? $row['medication_name'] ?? ''),
                    'dosage'    => normStr($row['dosage'] ?? ''),
                    'frequency' => normStr($row['frequency'] ?? ''),
                    'physician' => normStr($row['physician'] ?? ''),
                    'start_date'=> normStr($row['start_date'] ?? ''),
                    'end_date'  => normStr($row['end_date'] ?? ''),
                ];
            } elseif ($kind === 'surg') {
                $out[] = [
                    '_id'      => $row['_id'] ?? '',
                    'procedure'=> normStr($row['procedure'] ?? ''),
                    'physician'=> normStr($row['physician'] ?? ''),
                    'hospital' => normStr($row['hospital'] ?? ''),
                    'date'     => normStr($row['date'] ?? ''),
                    'notes'    => normStr($row['notes'] ?? ''),
                ];
            } elseif ($kind === 'ill') {
                $out[] = [
                    '_id'           => $row['_id'] ?? '',
                    'illness'       => normStr($row['illness'] ?? ''),
                    'start_date'    => normStr($row['start_date'] ?? ''),
                    'end_date'      => normStr($row['end_date'] ?? ''),
                    'physician'     => normStr($row['physician'] ?? ''),
                    'treatment_notes'=> normStr($row['treatment_notes'] ?? ''),
                ];
            } elseif ($kind === 'vax') {
                $out[] = [
                    '_id' => $row['_id'] ?? '',
                    'name'=> normStr($row['name'] ?? ''),
                    'date'=> normStr($row['date'] ?? ''),
                ];
            }
        }
        return $out;
    };

    $data = [
        'last_update'        => normStr($merged['last_update'] ?? $merged['updated_at'] ?? ''),
        'physician'          => $phys,
        'medications'        => $mapList($merged['medications'] ?? [], 'med'),
        'surgical_procedures'=> $mapList($merged['surgical_procedures'] ?? [], 'surg'),
        'illnesses'          => $mapList($merged['illnesses'] ?? [], 'ill'),
        'vaccinations'       => $mapList($merged['vaccinations'] ?? [], 'vax'),
    ];

    echo json_encode($data);
} catch (\Kreait\Firebase\Exception\DatabaseException $e) {
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    echo json_encode(['error' => 'Unexpected: '.$e->getMessage()]);
}
