<?php
session_name('sess_doctor'); session_start();

if (!isset($_SESSION['user']) || $_SESSION['usertype'] !== 'd') {
    header("location: ../login.php"); exit();
}



include("../connection.php");
date_default_timezone_set('Asia/Manila');
$currentUserEmail = $_SESSION['user'] ?? '';
/** unified log writer (global /logs) */

/**
 * Push a standardized log entry to /logs
 */
function pushStandardLog($database, $action, $patientId, $status, $byEmail = '')
{
    $payload = [
        'action'    => $action,
        'datestamp' => date('Y-m-d'),
        'patientId' => $patientId,
        'status'    => $status,
        'timestamp' => date('H:i:s'),
        'email'     => $byEmail,
    ];
    try {
        $database->getReference('logs')->push($payload);
    } catch (Exception $e) {
        error_log("Failed to write log: " . $e->getMessage());
    }
}

/**
 * Return only fields that actually changed (shallow diff).
 * Skips empty-string / null writes.
 */
function diff_assoc_filtered(array $new, array $old): array
{
    $out = [];
    foreach ($new as $k => $v) {
        $ov = $old[$k] ?? null;
        if ($ov !== $v && $v !== '' && $v !== null) {
            $out[$k] = $v;
        }
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // This field will contain either patient_uid OR the node key
    $idFromForm = isset($_POST['patient_uid']) ? trim($_POST['patient_uid']) : '';

    if ($idFromForm === '') {
        die("Error: patient_uid (or node key) is required to save or update the medical record.");
    }

    $puid       = null; // public patient UID
    $nodeKey    = null; // actual Firebase node key
    $patientPath = '';
    $basePath    = '';

    // 1) Try to resolve by patient_uid first (new-style records)
    $snap = $database->getReference('patients')
        ->orderByChild('patient_uid')
        ->equalTo($idFromForm)
        ->getSnapshot();

    $val = $snap->getValue();

    if ($val && is_array($val)) {
        // Found by patient_uid
        $nodeKeys   = array_keys($val);
        $nodeKey    = $nodeKeys[0];
        $puid       = $idFromForm;
        $patientPath = "patients/{$nodeKey}";
        $basePath    = "{$patientPath}/medical_record";
    } else {
        // 2) Fallback: treat idFromForm as node key (old records without patient_uid)
        $patientPath = "patients/{$idFromForm}";
        $basePath    = "{$patientPath}/medical_record";

        $patientSnap = $database->getReference($patientPath)->getSnapshot();
        $patientVal  = $patientSnap->getValue();

        if (!$patientVal || !is_array($patientVal)) {
            die("Error: patient not found by UID nor by node key.");
        }

        $nodeKey = $idFromForm;
        // Reuse existing patient_uid if any, otherwise use node key as pseudo-UID
        $puid = isset($patientVal['patient_uid']) && $patientVal['patient_uid']
            ? $patientVal['patient_uid']
            : $nodeKey;
    }

    // ========= Collect BASIC PATIENT INFO from form =========
    $patientName   = $_POST['patient_name']  ?? '';
    $patientFname  = $_POST['patient_fname'] ?? '';
    $patientLname  = $_POST['patient_lname'] ?? '';
    $dob           = $_POST['dob']           ?? '';
    $email         = $_POST['email']         ?? '';
    $civilStatus   = $_POST['civil_status']  ?? '';
    $gender        = $_POST['gender']        ?? '';
    $age           = $_POST['age']           ?? '';
    $tele          = $_POST['tele']          ?? '';
    $barangay      = $_POST['barangay']      ?? '';
    $city          = $_POST['city']          ?? '';
    $province      = $_POST['province']      ?? '';

    // ========= Collect MEDICAL RECORD META from form =========
    $lastUpdate     = $_POST['last_update']     ?? date('Y-m-d');
    $physicianName  = $_POST['physician_name']  ?? '';
    $physicianPhone = $_POST['physician_phone'] ?? '';
    $pharmacyName   = $_POST['pharmacy_name']   ?? '';
    $pharmacyPhone  = $_POST['pharmacy_phone']  ?? '';

    try {
        // ========= 0) Patient base info (top-level /patients/{nodeKey}) =========
        $patientRef = $database->getReference($patientPath);
        $oldPatient = $patientRef->getValue() ?: [];

        $newPatient = [
            'name'         => $patientName,
            'fname'        => $patientFname,
            'lname'        => $patientLname,
            'dob'          => $dob,
            'email'        => $email,
            'civil_status' => $civilStatus,
            'gender'       => $gender,
            'age'          => $age,
            'tele'         => $tele,
            'barangay'     => $barangay,
            'city'         => $city,
            'province'     => $province,
        ];

        // Make sure patient_uid is present and correct
        if (!isset($oldPatient['patient_uid']) || !$oldPatient['patient_uid']) {
            $newPatient['patient_uid'] = $puid;
        }

        $patientDiff = diff_assoc_filtered($newPatient, $oldPatient);
        if (!empty($patientDiff)) {
            $patientRef->update($patientDiff);
        }

        // ========= 1) Upsert medical record meta (/patients/{nodeKey}/medical_record) =========
        $recordRef = $database->getReference($basePath);
        $exists    = $recordRef->getSnapshot()->exists();

        $recordRef->update([
            'last_update' => $lastUpdate,
            'physician'   => [
                'name'    => $physicianName,
                'contact' => $physicianPhone
            ],
            'pharmacy'    => [
                'name'    => $pharmacyName,
                'contact' => $pharmacyPhone
            ],
            'updated_by'  => [
                'email' => $currentUserEmail,
                'at'    => date('c'),
            ],
        ]);

        // Helper: build rows from parallel arrays and ignore completely empty rows
        $buildRows = function(array $fields) {
            // $fields = [
            //   'name'       => $_POST['medication_name'] ?? [],
            //   'dosage'     => $_POST['dosage'] ?? [],
            //   ...
            // ]
            $max = 0;
            foreach ($fields as $arr) {
                $max = max($max, is_array($arr) ? count($arr) : 0);
            }

            $rows = [];
            for ($i = 0; $i < $max; $i++) {
                $row = [];
                $hasData = false;
                foreach ($fields as $key => $arr) {
                    $val = isset($arr[$i]) ? trim((string)$arr[$i]) : '';
                    $row[$key] = $val;
                    if ($val !== '') {
                        $hasData = true;
                    }
                }
                if ($hasData) {
                    $rows[] = $row;
                }
            }
            return $rows;
        };

        // ========= 2) MEDICATIONS =========
        $medFields = [
            'name'       => $_POST['medication_name'] ?? [],
            'dosage'     => $_POST['dosage']          ?? [],
            'frequency'  => $_POST['frequency']       ?? [],
            'physician'  => $_POST['med_physician']   ?? [],
            'start_date' => $_POST['med_start_date']  ?? [],
            'end_date'   => $_POST['med_end_date']    ?? [],
            'purpose'    => $_POST['med_purpose']     ?? [],
        ];
        $medRows = $buildRows($medFields);

        $medRef = $database->getReference("$basePath/medications");
        if (!empty($medRows)) {
            // overwrite the entire medications list with what’s in the form
            $medRef->set($medRows);
        } else {
            // no valid rows => remove node
            $medRef->remove();
        }

        // ========= 3) SURGICAL PROCEDURES =========
        $surgFields = [
            'procedure' => $_POST['procedure']      ?? [],
            'physician' => $_POST['surg_physician'] ?? [],
            'hospital'  => $_POST['hospital']       ?? [],
            'date'      => $_POST['surg_date']      ?? [],
            'notes'     => $_POST['surg_notes']     ?? [],
        ];
        $surgRows = $buildRows($surgFields);

        $surgRef = $database->getReference("$basePath/surgical_procedures");
        if (!empty($surgRows)) {
            $surgRef->set($surgRows);
        } else {
            $surgRef->remove();
        }

        // ========= 4) ILLNESSES =========
        $illFields = [
            'illness'         => $_POST['illness']         ?? [],
            'start_date'      => $_POST['ill_start_date']  ?? [],
            'end_date'        => $_POST['ill_end_date']    ?? [],
            'physician'       => $_POST['ill_physician']   ?? [],
            'treatment_notes' => $_POST['treatment_notes'] ?? [],
        ];
        $illRows = $buildRows($illFields);

        $illRef = $database->getReference("$basePath/illnesses");
        if (!empty($illRows)) {
            $illRef->set($illRows);
        } else {
            $illRef->remove();
        }

        // ========= 5) VACCINATIONS =========
        $vaxFields = [
            'name' => $_POST['vaccine_name'] ?? [],
            'date' => $_POST['vaccine_date'] ?? [],
        ];
        $vaxRows = $buildRows($vaxFields);

        $vaxRef = $database->getReference("$basePath/vaccinations");
        if (!empty($vaxRows)) {
            $vaxRef->set($vaxRows);
        } else {
            $vaxRef->remove();
        }

        // ========= 6) Log + redirect with flash =========
        pushStandardLog(
            $database,
            $exists ? 'update_medical_record' : 'create_medical_record',
            $puid, // log public UID
            $exists
                ? 'Updated medical record and patient base info.'
                : 'Created medical record and updated patient base info.',
            $currentUserEmail
        );

        $_SESSION['success_message'] = 'Medical record saved successfully.';
        header("Location: patient.php");
        exit();

    } catch (Exception $e) {
        echo "Error saving data: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    }

} else {
    http_response_code(405);
    echo "Method Not Allowed";
}