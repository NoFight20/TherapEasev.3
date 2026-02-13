<?php
// Start the session
$isAjax = (isset($_GET['ajax']) && $_GET['ajax'] === '1');
if ($isAjax) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
}

session_name('sess_it'); session_start();

if (isset($_SESSION["user"])) {
    if (($_SESSION["user"]) == "" || $_SESSION['usertype'] != 'it') {
        header("location: ../login.php");
        exit();
    } else {
        $useremail = $_SESSION["user"];
    }
} else {
    header("location: ../login.php");
    exit();
}

// Import Firebase connection
include("../connection.php");

date_default_timezone_set('Asia/Manila');
$today = date('Y-m-d');

// log helper: dynamic ID key per role
function writeLog($database, string $actorEmail, string $action, string $targetId, string $role, string $status): void {
    $ts = time();

    $idFields = [
        'patient' => 'patient_id',
        'doctor'  => 'doctor_id',
        'admin'   => 'admin_id',
        'it'      => 'it_id',
    ];
    $field = $idFields[$role] ?? 'account_id';

    try {
        $database->getReference('logs')->push([
            'action'    => $action,
            'email'     => $actorEmail,
            'datestamp' => date('Y-m-d', $ts),
            $field      => $targetId,
            'status'    => $status,
            'timestamp' => date('H:i:s', $ts)
        ]);
    } catch (\Throwable $e) {
        error_log('Log write error: ' . $e->getMessage());
    }
}

function roleToUserType($role) {
    switch ($role) {
        case 'patient': return 'p';
        case 'admin':   return 'a';
        case 'doctor':  return 'd';
        case 'it':      return 'it';
        default:        return null;
    }
}

function fetchAccountsByRole($database, $role){
    $accounts = [];

    $nodeMap = [
        'patient' => 'patients',
        'doctor'  => 'doctor',
        'it'      => 'it',
        'admin'   => 'admin',
    ];

    if (isset($nodeMap[$role])){
        $snap = $database->getReference($nodeMap[$role])->getValue();

        if ($snap){
            foreach ($snap as $key => $r){
                $isArchived = !empty($r['archived']) || (!empty($r['status']) && $r['status'] === 'archived');
                if ($isArchived) continue;

                $email = isset($r['email']) ? $r['email'] : '(No Email)';
                $name  = isset($r['name'])  ? $r['name']  : 'N/A';

                $row = [
                    'key'   => $key,
                    'email' => $email,
                    'name'  => $name,   
                    'type'  => $role
                ];

                if (isset($r['fname'])) { $row['fname'] = $r['fname']; }
                if (isset($r['lname'])) { $row['lname'] = $r['lname']; }

                if ($role === 'doctor' || $role === 'admin') {
                    if (isset($r['sname'])) {
                        $row['sname'] = $r['sname'];
                    } elseif (isset($r['specialty'])) {
                        $row['sname'] = $r['specialty']; 
                    }
                    if (isset($r['specialty'])) {
                        $row['specialty'] = $r['specialty'];
                    }
                }

                $accounts[$key] = $row;
            }
        }
    }

    if ($type = roleToUserType($role)){
        $users = $database->getReference('users')
            ->orderByChild('type')->equalTo($type)->getValue();

        if ($users){
            foreach ($users as $key => $u){
                $isArchivedU = !empty($u['archived']) || (!empty($u['status']) && $u['status'] === 'archived');
                if ($isArchivedU) continue;

                if (!isset($accounts[$key])) continue;

                $emailU = isset($u['email']) ? $u['email'] : null;
                $nameU  = isset($u['name'])  ? $u['name']  : (isset($u['username']) ? $u['username'] : null);

                if (empty($accounts[$key]['email']) || $accounts[$key]['email'] === '(No Email)'){
                    $accounts[$key]['email'] = $emailU ?: '(No Email)';
                }

                if (empty($accounts[$key]['fname']) && isset($u['fname'])) {
                    $accounts[$key]['fname'] = $u['fname'];
                }
                if (empty($accounts[$key]['lname']) && isset($u['lname'])) {
                    $accounts[$key]['lname'] = $u['lname'];
                }

                if ((!isset($accounts[$key]['name']) || $accounts[$key]['name'] === 'N/A') && $nameU) {
                    $accounts[$key]['name'] = $nameU;
                }

                if (($role === 'doctor' || $role === 'admin')) {
                    if (!isset($accounts[$key]['sname'])) {
                        if (isset($u['sname'])) {
                            $accounts[$key]['sname'] = $u['sname'];
                        } elseif (isset($u['specialty'])) {
                            $accounts[$key]['sname'] = $u['specialty'];
                        }
                    }
                    if (!isset($accounts[$key]['specialty']) && isset($u['specialty'])) {
                        $accounts[$key]['specialty'] = $u['specialty'];
                    }
                }
            }
        }
    }

    return $accounts;
}

function fetchArchivedPatients($database){
    $accounts = [];

    try {
        $archiveRoot = $database->getReference('archive')->getValue() ?: [];
    } catch (\Throwable $e) {
        error_log('Failed to load archive: ' . $e->getMessage());
        return $accounts;
    }

    if (!is_array($archiveRoot)) {
        return $accounts;
    }

    // 1) archive/patients node (structured archive)
    if (isset($archiveRoot['patients']) && is_array($archiveRoot['patients'])) {
        foreach ($archiveRoot['patients'] as $key => $r) {
            if (!is_array($r)) continue;

            // If there's a nested "patient" node, use that. Otherwise use the record itself.
            $p = isset($r['patient']) && is_array($r['patient']) ? $r['patient'] : $r;

            $email = $p['email'] ?? '(No Email)';
            $fname = $p['fname'] ?? '';
            $lname = $p['lname'] ?? '';
            $name  = trim($fname . ' ' . $lname);
            if ($name === '') {
                $name = $p['name'] ?? 'N/A';
            }

            // Prefer patient_uid or uid as the logical identity,
            // but keep $key as the actual archive node key
            $uidKey = $p['patient_uid'] ?? $p['uid'] ?? $key;

            $accounts[$uidKey] = [
                'key'   => $key,              // ← IMPORTANT CHANGE (was $uidKey)
                'email' => $email,
                'name'  => $name,
                'fname' => $fname,
                'lname' => $lname,
                'sname' => $p['sname'] ?? null,
                'type'  => 'patient',
            ];
        }
    }

    // 2) Top-level archive nodes with patient data (legacy style)
    $skipKeys = ['patients', 'users', 'admin', 'doctor', 'it'];
    foreach ($archiveRoot as $key => $r) {
        if (in_array($key, $skipKeys, true)) continue;
        if (!is_array($r)) continue;

        $p = null;

        // Case A: archive/{key}/patient = { ... }
        if (isset($r['patient']) && is_array($r['patient'])) {
            $p = $r['patient'];
        } else {
            // Case B: record itself looks like a patient
            if (isset($r['fname']) || isset($r['lname']) || isset($r['name']) || isset($r['email'])) {
                $p = $r;
            }
        }

        if (!$p || !is_array($p)) continue;

        $email = $p['email'] ?? '(No Email)';
        $fname = $p['fname'] ?? '';
        $lname = $p['lname'] ?? '';
        $name  = trim($fname . ' ' . $lname);
        if ($name === '') {
            $name = $p['name'] ?? 'N/A';
        }

        // Prefer patient_uid or uid as logical identity, but keep archive key for 'key'
        $uidKey = $p['patient_uid'] ?? $p['uid'] ?? $key;

        if (isset($accounts[$uidKey])) continue; // avoid duplicates

        $accounts[$uidKey] = [
            'key'   => $key,              // ← IMPORTANT CHANGE (was $uidKey)
            'email' => $email,
            'name'  => $name,
            'fname' => $fname,
            'lname' => $lname,
            'sname' => $p['sname'] ?? null,
            'type'  => 'patient',
        ];
    }

    return array_values($accounts);
}


/**
 * Fetch archived accounts for non-patient roles (doctor, admin, it)
 * from archive/{node}/{uid}
 */
function fetchArchivedNonPatients($database, string $role) {
    $accounts = [];

    $nodeMap = [
        'doctor' => 'doctor',
        'admin'  => 'admin',
        'it'     => 'it',
    ];

    if (!isset($nodeMap[$role])) {
        return $accounts;
    }

    $node = $nodeMap[$role];

    try {
        $snap = $database->getReference("archive/{$node}")->getValue() ?: [];
    } catch (\Throwable $e) {
        error_log('Failed to load archive/'.$node.': ' . $e->getMessage());
        return $accounts;
    }

    if (!is_array($snap)) {
        return $accounts;
    }

    foreach ($snap as $key => $r) {
        if (!is_array($r)) continue;

        $email = $r['email'] ?? '(No Email)';
        $fname = $r['fname'] ?? '';
        $lname = $r['lname'] ?? '';
        $name  = trim($fname . ' ' . $lname);
        if ($name === '') {
            $name = $r['name'] ?? 'N/A';
        }

        $acc = [
            'key'   => $key,
            'email' => $email,
            'name'  => $name,
            'fname' => $fname,
            'lname' => $lname,
            'type'  => $role,
        ];

        if ($role === 'doctor' || $role === 'admin') {
            $acc['sname'] = $r['sname'] ?? ($r['specialty'] ?? null);
            if (isset($r['specialty'])) {
                $acc['specialty'] = $r['specialty'];
            }
        }

        $accounts[] = $acc;
    }

    return $accounts;
}

/**
 * Wrapper: fetch archived accounts by role
 */
function fetchArchivedAccountsByRole($database, string $role) {
    if ($role === 'patient') {
        return fetchArchivedPatients($database);
    }
    return fetchArchivedNonPatients($database, $role);
}

// Fetch current IT user (also store its UID so we can skip self on bulk delete)
$currentItKey = null;
try {
    $reference = $database
        ->getReference('it')
        ->orderByChild('email')
        ->equalTo($useremail)
        ->getSnapshot();

    $userfetch = $reference->getValue();

    if ($userfetch) {
        $currentItKey = array_key_first($userfetch);
        foreach ($userfetch as $key => $value) {
            $username = $value['name'];
        }
    } else {
        echo "No user found.";
    }
} catch (\Kreait\Firebase\Exception\DatabaseException $e) {
    echo "Error querying the database: " . $e->getMessage();
}

/* ==========================================================
   HARD DELETE ALL FOR CURRENT ROLE (BULK)
   ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hard_delete_all_role'])) {
    $roleDel = trim($_POST['role_to_delete'] ?? '');

    $roleNodeMap = [
        'patient' => 'patients',
        'doctor'  => 'doctor',
        'admin'   => 'admin',
        'it'      => 'it',
    ];
    $targetNode = $roleNodeMap[$roleDel] ?? null;

    if (!$targetNode) {
        echo "<script>alert('Invalid role for bulk delete.'); window.location.href='clients.php';</script>";
        exit();
    }

    try {
        $accountsToDelete = $database->getReference($targetNode)->getValue() ?: [];

        $deleted = 0;
        $skippedSelf = 0;

        foreach ($accountsToDelete as $uid => $acc) {
            // SAFETY: skip currently logged-in IT if deleting IT role
            if ($roleDel === 'it' && $currentItKey && $uid === $currentItKey) {
                $skippedSelf++;
                continue;
            }

            // remove role node data
            $database->getReference("$targetNode/$uid")->remove();

            // remove users mirror if exists
            $database->getReference("users/$uid")->remove();

            // hard delete auth user (best-effort)
            try {
                $auth->deleteUser($uid);
            } catch (\Throwable $e) {
                error_log("Auth delete failed for $uid: " . $e->getMessage());
            }

            $deleted++;
        }

        $msg = "Hard deleted {$deleted} {$roleDel} account(s).";
        if ($skippedSelf > 0) {
            $msg .= " Skipped {$skippedSelf} currently logged-in IT account.";
        }

        writeLog($database, $useremail, 'hard_delete_all_role', $roleDel, $roleDel, $msg);

        echo "<script>alert(".json_encode($msg)."); window.location.href='clients.php?role=".$roleDel."';</script>";
        exit();

    } catch (\Throwable $e) {
        writeLog($database, $useremail, 'hard_delete_all_role_error', $roleDel, $roleDel, 'Error: '.$e->getMessage());
        echo "<script>alert('Bulk delete failed: " . htmlspecialchars($e->getMessage(), ENT_QUOTES) . "'); window.location.href='clients.php';</script>";
        exit();
    }
}

/* ==========================================================
   RETRIEVE ARCHIVED PATIENT
   ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['retrieve_key'])) {
    $retrieveKey = trim($_POST['retrieve_key'] ?? '');

    if ($retrieveKey === '') {
        echo "<script>alert('Error: Missing archive key.'); window.location.href='clients.php?view=archive';</script>";
        exit();
    }

    try {
        $archiveRoot = $database->getReference('archive')->getValue() ?: [];
    } catch (\Throwable $e) {
        error_log('Failed to load archive for retrieve: ' . $e->getMessage());
        echo "<script>alert('Error loading archive: " . htmlspecialchars($e->getMessage(), ENT_QUOTES) . "'); window.location.href='clients.php?view=archive';</script>";
        exit();
    }

    $patientData = null;
    $medData     = null;

    // 1) archive/patients/{key}
    if (isset($archiveRoot['patients'][$retrieveKey]) && is_array($archiveRoot['patients'][$retrieveKey])) {
        $node = $archiveRoot['patients'][$retrieveKey];

        if (isset($node['patient']) && is_array($node['patient'])) {
            $patientData = $node['patient'];
        } else {
            $patientData = $node;
        }

        if (isset($node['medical_record']) && is_array($node['medical_record'])) {
            $medData = $node['medical_record'];
        }
    }

    // 2) if not found, try archive/{key}
    if (!$patientData && isset($archiveRoot[$retrieveKey]) && is_array($archiveRoot[$retrieveKey])) {
        $node = $archiveRoot[$retrieveKey];

        if (isset($node['patient']) && is_array($node['patient'])) {
            $patientData = $node['patient'];
        } else {
            $patientData = $node;
        }

        if (isset($node['medical_record']) && is_array($node['medical_record'])) {
            $medData = $node['medical_record'];
        }
    }

    if (!$patientData) {
        echo "<script>alert('No patient data found for this archive key.'); window.location.href='clients.php?view=archive';</script>";
        exit();
    }

    $newKey = $patientData['uid'] ?? $patientData['patient_uid'] ?? $retrieveKey;

    // Clean archive flags and mark active
    unset($patientData['archivedAt'], $patientData['archivedBy'], $patientData['status']);
    $patientData['status'] = 'active';

    try {
        // restore patient
        $database->getReference("patients/{$newKey}")->set($patientData);

        // restore medical record if available
        if ($medData) {
            $database->getReference("medical_record/{$newKey}")->set($medData);
        }

        // restore user record if it exists in archive/users
        if (isset($archiveRoot['users'][$newKey]) && is_array($archiveRoot['users'][$newKey])) {
            $u = $archiveRoot['users'][$newKey];
            unset($u['archivedAt'], $u['archivedBy'], $u['status']);
            $u['status'] = 'active';
            $database->getReference("users/{$newKey}")->set($u);
        }

        writeLog($database, $useremail, 'retrieve_patient', $newKey, 'patient', "Retrieved patient from archive");

        echo "<script>alert('Patient retrieved successfully.'); window.location.href='clients.php?role=patient';</script>";
        exit();
    } catch (\Throwable $e) {
        writeLog($database, $useremail, 'retrieve_patient_error', $retrieveKey, 'patient', 'Error: '.$e->getMessage());
        echo "<script>alert('Error retrieving patient: " . htmlspecialchars($e->getMessage(), ENT_QUOTES) . "'); window.location.href='clients.php?view=archive';</script>";
        exit();
    }
}

/* ==========================================================
   EXISTING: Handle archive single (active → archive)
   ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_key'])) {
    $keyToArchive = trim($_POST['archive_key']);

    if ($keyToArchive === '') {
        echo "<script>alert('Error: Missing record key.');</script>";
    } else {
        try {
            $archiveBase = 'archive';
            $ts = time();
            $tsMeta = [
                'archivedAt' => $ts,
                'archivedBy' => $useremail ?? '(system)',
                'status'     => 'archived',
            ];

            $roleNodes = ['patients', 'doctor', 'admin', 'it'];
            $movedSomething = false;

            foreach ($roleNodes as $node) {
                $path = "$node/$keyToArchive";
                $ref  = $database->getReference($path);
                $data = $ref->getValue();

                if ($data) {
                    $database->getReference("$archiveBase/$node/$keyToArchive")->set(array_merge($data, $tsMeta));
                    $ref->remove();
                    $movedSomething = true;
                }
            }

            $userPath = "users/$keyToArchive";
            $userRef  = $database->getReference($userPath);
            $userData = $userRef->getValue();

            if ($userData) {
                $database->getReference("$archiveBase/users/$keyToArchive")->set(array_merge($userData, $tsMeta));
                $userRef->remove();
                $movedSomething = true;
            }

            try {
                $auth->disableUser($keyToArchive);
            } catch (\Throwable $e) {
                error_log('Auth disable error: ' . $e->getMessage());
            }

            try {
                $database->getReference('logs')->push([
                    'action'    => 'archive',
                    'email'     => $useremail,
                    'datestamp' => date('Y-m-d', $ts),
                    'patientId' => $keyToArchive,
                    'status'    => 'Archived patient data and associated medical records.',
                    'timestamp' => date('H:i:s', $ts)
                ]);
            } catch (\Throwable $e) {
                error_log('Log write error: ' . $e->getMessage());
            }

            if ($movedSomething) {
                echo "<script>alert('Account archived successfully.'); window.location.href='clients.php';</script>";
            } else {
                echo "<script>alert('No matching records found to archive for this key.');</script>";
            }
            exit();

        } catch (\Throwable $e) {
            echo "<script>alert('Error archiving account: " . htmlspecialchars($e->getMessage(), ENT_QUOTES) . "');</script>";
        }
    }
}

// Generic edit handler for any role
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_account_edit'])) {
    $key = trim($_POST['edit_key'] ?? '');
    $updatedEmail = trim($_POST['edit_email'] ?? '');
    $newPassword  = $_POST['edit_password'] ?? '';
    $confirmPassword = $_POST['edit_confirm_password'] ?? '';
    $roleSubmitted = $_POST['edit_role'] ?? ''; 
    $fname = trim($_POST['fname'] ?? '');
    $lname = trim($_POST['lname'] ?? '');
    $fullName = trim($fname . ' ' . $lname);

    if ($updatedEmail === '' || $fname === '' || $lname === '') {
        echo "<script>alert('Email, First Name, and Last Name are required.'); window.history.back();</script>";
        exit();
    }

    $roleNodeMap = [
        'patient' => 'patients',
        'doctor'  => 'doctor',
        'admin'   => 'admin',
        'it'      => 'it',
    ];
    $targetNode = $roleNodeMap[$roleSubmitted] ?? null;

    if ($key === '' || !$targetNode) {
        echo "<script>alert('Invalid edit payload: missing key or role.'); window.history.back();</script>";
        exit();
    }

    if ($newPassword !== '' || $confirmPassword !== '') {
        if ($newPassword !== $confirmPassword) {
            echo "<script>alert('Passwords do not match!'); window.history.back();</script>";
            exit();
        }
    }

    try {
        $database->getReference("$targetNode/$key")->update([
            'email' => $updatedEmail,
            'fname' => $fname,
            'lname' => $lname,
            'name'  => $fullName
        ]);

        $userRef  = $database->getReference("users/$key");
        $userData = $userRef->getValue();
        if ($userData) {
            $userRef->update([
                'email' => $updatedEmail,
                'fname' => $fname,
                'lname' => $lname,
                'name'  => $fullName
            ]);
        }

        if ($newPassword !== '') {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

            $database->getReference("$targetNode/$key")->update([
                'password' => $hashedPassword
            ]);

            if ($userData) {
                $database->getReference("users/$key")->update([
                    'password' => $hashedPassword
                ]);
            }

            try { $auth->updateUser($key, ['password' => $newPassword]); }
            catch (\Throwable $e) { error_log('Auth password update error: ' . $e->getMessage()); }
        }

        try { $auth->updateUser($key, ['email' => $updatedEmail]); }
        catch (\Throwable $e) { error_log('Auth email update error: ' . $e->getMessage()); }

        $pwdNote = ($newPassword !== '') ? ' (password updated)' : '';
        writeLog($database, $useremail, 'update_account', $key, $roleSubmitted, "Updated {$roleSubmitted} account{$pwdNote}");

        echo "<script>alert('Account updated successfully!'); window.location.href = 'clients.php?role=" . htmlspecialchars($roleSubmitted, ENT_QUOTES) . "';</script>";
        exit();
    } catch (\Throwable $e) {
        writeLog($database, $useremail, 'update_account_error', $key ?: '(missing-key)', $roleSubmitted, 'Error: ' . $e->getMessage());
        echo "<script>alert('Error updating account: " . htmlspecialchars($e->getMessage(), ENT_QUOTES) . "');</script>";
    }
}

// ------------------ VIEW / ROLE / FILTERS -------------------
$view = isset($_REQUEST['view']) ? $_REQUEST['view'] : 'active';
$isArchiveView = ($view === 'archive');

$role = isset($_REQUEST['role']) ? $_REQUEST['role'] : 'patient';
$validRoles = ['patient','it','doctor','admin'];

if (!in_array($role, $validRoles)) { 
    $role = 'patient'; 
}

// NOTE: we NO LONGER force patient in archive mode; archive can show by role
$showSpecialty = in_array($role, ['doctor','admin'], true); 

$deptId = isset($_REQUEST['dept']) ? trim($_REQUEST['dept']) : '';

$specialties = [];
try {
    $spSnap = $database->getReference('specialties')->getValue() ?: [];
    foreach ($spSnap as $sid => $row) {
        if (!is_array($row)) continue;
        $label = isset($row['sname']) ? trim($row['sname']) : '';
        if ($label === '') continue;
        $specialties[$sid] = $label;
    }
} catch (\Throwable $e) {
    error_log('Failed to load specialties: ' . $e->getMessage());
}

// Load accounts (active or archive)
if ($isArchiveView) {
    $accounts = fetchArchivedAccountsByRole($database, $role);
    $showSpecialty = in_array($role, ['doctor','admin'], true);
} else {
    $accounts = fetchAccountsByRole($database, $role);

    if (in_array($role, ['doctor','admin'], true) && is_array($accounts)) {
        if (!array_is_list($accounts)) {
            $accounts = array_values($accounts);
        }
        usort($accounts, function($a, $b) use ($specialties) {
            $sa = isset($a['specialty']) ? (string)$a['specialty'] : '';
            $sb = isset($b['specialty']) ? (string)$b['specialty'] : '';
            $la = $sa !== '' && isset($specialties[$sa]) ? $specialties[$sa] : ($a['sname'] ?? '');
            $lb = $sb !== '' && isset($specialties[$sb]) ? $specialties[$sb] : ($b['sname'] ?? '');
            $c1 = strcasecmp((string)$la, (string)$lb);
            if ($c1 !== 0) return $c1;
            $na = trim(($a['fname'] ?? '') . ' ' . ($a['lname'] ?? ''));
            $nb = trim(($b['fname'] ?? '') . ' ' . ($b['lname'] ?? ''));
            return strcasecmp($na, $nb);
        });
    }
}

$rowsHtml = "";
$searchQuery = isset($_REQUEST['search']) ? trim($_REQUEST['search']) : '';
$filteredCount = 0;

if ($accounts) {
    foreach ($accounts as $acc) {
        $acc = is_array($acc) ? $acc : [];

        $keyRaw = $acc['key']   ?? '';
        $emRaw  = $acc['email'] ?? '(No Email)';
        $nmRaw  = $acc['name']  ?? 'N/A';

        $fnRaw = $acc['fname'] ?? '';
        $lnRaw = $acc['lname'] ?? '';
        $fullNameRaw = trim($fnRaw . ' ' . $lnRaw);
        if ($fullNameRaw === '') { $fullNameRaw = $nmRaw; }

        $email   = htmlspecialchars($emRaw, ENT_QUOTES, 'UTF-8');
        $name    = htmlspecialchars($fullNameRaw, ENT_QUOTES, 'UTF-8');
        $jsKey   = json_encode($keyRaw, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
        $jsEmail = json_encode($emRaw,  JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
        $jsFname = json_encode($fnRaw,  JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
        $jsLname = json_encode($lnRaw,  JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);

        // ---- Resolve canonical specialty / department ----
        $accSpecId = null;
        if ($role === 'doctor' || $role === 'admin') {
            $rawSpec = $acc['specialty'] ?? null;
            $sn      = $acc['sname'] ?? null;

            if (is_string($rawSpec) && $rawSpec !== '') {
                if (isset($specialties[$rawSpec])) {
                    $accSpecId = $rawSpec;
                } else {
                    $matchId = array_search($rawSpec, $specialties, true);
                    if ($matchId !== false) {
                        $accSpecId = (string)$matchId;
                    }
                }
            }

            if ($accSpecId === null && is_string($sn) && trim($sn) !== '') {
                $matchId = array_search($sn, $specialties, true);
                if ($matchId !== false) {
                    $accSpecId = (string)$matchId;
                }
            }

            if ($accSpecId !== null && isset($specialties[$accSpecId])) {
                $spRaw = $specialties[$accSpecId];
            } else {
                if (is_array($sn)) {
                    $isAssoc = array_keys($sn) !== range(0, count($sn) - 1);
                    if ($isAssoc) $sn = array_values($sn);
                    $spRaw = implode(", ", $sn);
                } elseif (is_string($sn) && trim($sn) !== '') {
                    $spRaw = $sn;
                } else {
                    $spRaw = 'N/A';
                }
            }
        } else {
            // For patients in archive view, show department if sname exists
            if ($isArchiveView && $role === 'patient') {
                $sn = $acc['sname'] ?? null;
                if (is_string($sn) && trim($sn) !== '') {
                    $spRaw = $sn;
                } else {
                    $spRaw = 'No Specialty / Department';
                }
            } else {
                $spRaw = 'No Specialty / Department';
            }
        }

        $specialty = htmlspecialchars($spRaw, ENT_QUOTES, 'UTF-8');

        // In active view, department filter for doctor/admin only
        if (!$isArchiveView && ($role === 'doctor' || $role === 'admin') && $deptId !== '') {
            $accSpecIdStr = $accSpecId !== null ? (string)$accSpecId : '';
            if ($accSpecIdStr !== (string)$deptId) {
                continue;
            }
        }

        if ($searchQuery === '' ||
            stripos($emRaw, $searchQuery) !== false ||
            stripos($nmRaw, $searchQuery)  !== false ||
            stripos($fnRaw, $searchQuery)  !== false ||
            stripos($lnRaw, $searchQuery)  !== false) {

            $actionButtons = '';

            if ($isArchiveView && $role === 'patient') {
                // Archive view → show Retrieve button ONLY for patients
                $actionButtons .= "
                    <form action='' method='POST' style='display:inline;'>
                        <input type='hidden' name='retrieve_key' value='" . htmlspecialchars($keyRaw, ENT_QUOTES, 'UTF-8') . "'>
                        <button type='submit'
                                onclick='return confirm(\"Retrieve this patient back to active accounts?\");'
                                class='login-btn btn-primary btn'>
                            <i class='fas fa-undo' style='margin-right:8px;'></i>Retrieve
                        </button>
                    </form>";
            } else {
                // Active view → normal Edit + Archive
                if ($role === 'patient' || $role === 'it' || $role === 'doctor' || $role === 'admin') {
                    $actionButtons .= "
                        <button onclick='openEditAccountPopup({$jsKey}, {$jsEmail}, {$jsFname}, {$jsLname})' class=\"login-btn btn-primary btn\">
                            <i class=\"fas fa-edit\" style=\"margin-right:8px;\"></i>Edit
                        </button>";
                }
                $actionButtons .= "
                    <form action='' method='POST' style='display:inline;margin-left:8px;'>
                        <input type='hidden' name='archive_key' value='" . htmlspecialchars($keyRaw, ENT_QUOTES, 'UTF-8') . "'>
                        <button type='submit' onclick='return confirm(\"Archive this account? It will be moved to the archive node.\");' 
                                class='login-btn btn-primary btn'>
                            <i class='fas fa-archive' style='margin-right:8px;'></i>Archive
                        </button>
                    </form>";
            }

           $rowsHtml .= "<tr style='text-align:center;vertical-align:middle;'>
    <td style='padding:15px 20px;border-bottom:1px solid #ddd;'>{$email}</td>
    <td style='padding:15px 20px;border-bottom:1px solid #ddd;'>{$name}</td>
    <td class='col-specialty' style='padding:15px 20px;border-bottom:1px solid #ddd;'>{$specialty}</td>
    <td style='padding:15px 20px;border-bottom:1px solid #ddd;'>
        <div class='action-stack'>{$actionButtons}</div>
    </td>
</tr>";


            $filteredCount++;
        }
    }
}

if (empty($rowsHtml)) {
    $rowsHtml = "<tr><td colspan='4'><center>No matching records found.</center></td></tr>";
}

$datalistEmails = array_values(array_unique(array_map(function($a){
    return isset($a['email']) ? $a['email'] : '';
}, $accounts)));

// Role label (active vs archive)
if ($isArchiveView) {
    switch ($role) {
        case 'patient':
            $roleLabel = 'Archived Patients';
            break;
        case 'it':
            $roleLabel = 'Archived IT';
            break;
        case 'doctor':
            $roleLabel = 'Archived Doctors';
            break;
        case 'admin':
            $roleLabel = 'Archived Admins';
            break;
        default:
            $roleLabel = 'Archived Accounts';
    }
} else {
    $roleLabel = ucfirst($role);
}

$totalCount = is_array($accounts) ? count($accounts) : 0;

$deptBadge = '';
if (!$isArchiveView && $showSpecialty && $deptId !== '' && isset($specialties[$deptId])) {
    $deptBadge = ' — ' . $specialties[$deptId];
}

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode([
        'rows_html'      => $rowsHtml,
        'count_total'    => $totalCount,
        'count_filtered' => $filteredCount,
        'role_label'     => $roleLabel,
        'show_specialty' => $showSpecialty,
    ]);
    exit;
}

// ID generator
function genUidWithPrefix(string $prefix): string {
    return $prefix . date('Ymd') . '-' . substr(bin2hex(random_bytes(4)), 0, 10);
}

// Create account handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_account'])) {
    $roleNew   = trim($_POST['create_role'] ?? '');
    $emailNew  = trim($_POST['create_email'] ?? '');
    $fnameNew  = trim($_POST['create_fname'] ?? '');
    $lnameNew  = trim($_POST['create_lname'] ?? '');
    $passNew   = $_POST['create_password'] ?? '';
    $confirmPass = $_POST['create_confirm_password'] ?? '';

    $specialtyId = trim($_POST['create_specialty_id'] ?? '');

    if ($passNew !== $confirmPass) {
        echo "<script>alert('Passwords do not match.'); window.history.back();</script>";
        exit();
    }

    if (!in_array($roleNew, ['admin','doctor','it'], true)) {
        echo "<script>alert('Invalid role.'); window.history.back();</script>"; exit();
    }
    if ($emailNew === '' || $fnameNew === '' || $lnameNew === '' || $confirmPass === '') {
        echo "<script>alert('All fields are required.'); window.history.back();</script>"; exit();
    }

    $snameText = null;
    if (in_array($roleNew, ['admin','doctor'], true)) {
        if ($specialtyId === '') {
            echo "<script>alert('Please select a specialty for {$roleNew}.'); window.history.back();</script>"; exit();
        }
        try {
            $spRow = $database->getReference("specialties/{$specialtyId}")->getValue();
            if (!$spRow || empty($spRow['sname'])) {
                echo "<script>alert('Selected specialty was not found.'); window.history.back();</script>"; exit();
            }
            $snameText = trim($spRow['sname']);
        } catch (\Throwable $e) {
            error_log('Specialty lookup failed: ' . $e->getMessage());
            echo "<script>alert('Unable to validate specialty.'); window.history.back();</script>"; exit();
        }
    }

    $map = [
        'admin'  => ['node' => 'admin',  'type' => 'a',  'prefix' => 'A-',  'uid_field' => 'admin_uid'],
        'doctor' => ['node' => 'doctor', 'type' => 'd',  'prefix' => 'D-',  'uid_field' => 'doctor_uid'],
        'it'     => ['node' => 'it',     'type' => 'it', 'prefix' => 'IT-', 'uid_field' => 'it_uid'],
    ];
    $meta = $map[$roleNew];

    $newId = genUidWithPrefix($meta['prefix']);
    $full  = trim($fnameNew . ' ' . $lnameNew);

    try {
        try {
            $auth->createUser([
                'uid'         => $newId,
                'email'       => $emailNew,
                'password'    => $confirmPass,
                'displayName' => $full
            ]);
        } catch (\Throwable $e) {
            error_log('Auth create error: ' . $e->getMessage());
            writeLog($database, $useremail, 'create_account_error', '(auth)', $roleNew, 'Auth create error: ' . $e->getMessage());
            echo "<script>alert('Failed to create Auth user: " . htmlspecialchars($e->getMessage(), ENT_QUOTES) . "');</script>";
            exit();
        }

        $rolePayload = [
            $meta['uid_field'] => $newId,
            'email'   => $emailNew,
            'fname'   => $fnameNew,
            'lname'   => $lnameNew,
            'name'    => $full,
            'created' => time(),
            'type'    => $meta['type'],
        ];
        if (in_array($roleNew, ['admin','doctor'], true)) {
            $rolePayload['sname']     = $snameText;
            $rolePayload['specialty'] = $specialtyId;
        }

        $database->getReference("{$meta['node']}/{$newId}")->set($rolePayload);

        $userPayload = [
            'email'   => $emailNew,
            'fname'   => $fnameNew,
            'lname'   => $lnameNew,
            'name'    => $full,
            'type'    => $meta['type'],
            'created' => time(),
        ];
        if (in_array($roleNew, ['admin','doctor'], true)) {
            $userPayload['sname']     = $snameText;
            $userPayload['specialty'] = $specialtyId;
        }
        $database->getReference("users/{$newId}")->set($userPayload);

        writeLog($database, $useremail, 'create_account', $newId, $roleNew, "Created {$roleNew} account");

        echo "<script>alert('{$roleNew} account created!'); window.location.href='clients.php?role={$roleNew}';</script>";
        exit();
    } catch (\Throwable $e) {
        writeLog($database, $useremail, 'create_account_error', '(db)', $roleNew, $e->getMessage());
        echo "<script>alert('Error creating account: " . htmlspecialchars($e->getMessage(), ENT_QUOTES) . "');</script>";
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/animations.css">  
    <link rel="stylesheet" href="../css/main.css">  
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="clients.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <title>Accounts</title>
    <style>
    .menu-btn a {
      display: block;
      text-decoration: none; 
      padding: 1px; 
      width: 100%; 
    }
    .menu-btn:hover { background-color: #E8F8E8; }
    .overlay { animation: fadeInOverlay 0.3s ease; }
    .popup { animation: slideUpPopup 0.4s ease; }
    @keyframes fadeInOverlay { from {opacity: 0;} to {opacity: 1;} }
    @keyframes slideUpPopup {
      from { transform: translateY(30px); opacity: 0; }
      to   { transform: translateY(0);    opacity: 1; }
    }

    .filters {
        display: flex;
        align-items: center;
        gap: 16px;
        flex-wrap: wrap;           
    }

    .role-filter,
    .search-filter {
        display: inline-flex;
        align-items: center;
        gap: 10px;
    }

    :root { --control-h: 38px; }

    .filters .input-text,
    .filters select,
    .filters input[type="text"] {
        height: var(--control-h);
        padding: 8px 10px;
        box-sizing: border-box;
    }

    .filters .login-btn {
        height: var(--control-h);
        padding: 0 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .role-filter label {
        margin: 0 6px 0 0;
        line-height: 1;  
    }

    #searchInput {
        width: clamp(320px, 42vw, 640px);
    }

    @media (max-width: 600px) {
        .filters { gap: 10px; }
        #searchInput { width: 100%; }
    }

    #accountsTable.hide-specialty .col-specialty { 
        display: none; 
    }

    /* delete-all button style */
    .btn-danger {
        background:#dc2626 !important;
        border-color:#dc2626 !important;
        color:#fff !important;
    }
    .btn-danger:hover {
        background:#b91c1c !important;
        border-color:#b91c1c !important;
    }

      /* =========================
     BASE (desktop unchanged)
     ========================= */
  .mobile-header { display: none; }
  #hamburger-menu { display: none; }
  #menu-overlay { display: none; }

  /* Keep table readable on desktop */
  #accountsTable { width: 80%; }

  /* =========================
     MOBILE VIEW (Accounts)
     ========================= */
  @media (max-width: 768px) {

    html, body {
      width: 100%;
      overflow-x: hidden;
    }

    /* ---- Fixed mobile header (title + date) ---- */
    .mobile-header {
      display: flex !important;
      position: fixed;
      top: 0; left: 0; right: 0;
      height: 56px;
      background: lightgreen;
      z-index: 10050;
      align-items: center;
      padding: 0 12px;
      box-sizing: border-box;
      font-weight: 800;
      color: #000;
    }

    .mobile-left { width: 34px; } /* reserved for hamburger */
    .mobile-center {
      flex: 1;
      text-align: center;
      font-size: 16px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .mobile-right {
      font-size: 12px;
      font-weight: 700;
      white-space: nowrap;
    }

    /* ---- Hamburger button ---- */
    #hamburger-menu {
      display: flex !important;
      position: fixed !important;
      top: 11px !important;
      left: 12px !important;
      width: 34px !important;
      height: 34px !important;
      padding: 8px !important;
      border-radius: 10px !important;
      background: rgba(255, 255, 255, .48) !important;
      z-index: 10060 !important;
      cursor: pointer;
      flex-direction: column;
      justify-content: center;
      gap: 5px;
      box-sizing: border-box;
    }

    #hamburger-menu .bar {
      height: 2px !important;
      width: 100% !important;
      background: #111 !important;
      border-radius: 2px;
      transition: .25s;
    }

    #hamburger-menu.active .bar:nth-child(1) {
      transform: rotate(-45deg) translate(-4px, 5px);
    }
    #hamburger-menu.active .bar:nth-child(2) { opacity: 0; }
    #hamburger-menu.active .bar:nth-child(3) {
      transform: rotate(45deg) translate(-4px, -5px);
    }

    /* ---- Push everything below header ---- */
    .container {
      padding-top: 56px !important;
      width: 100% !important;
      max-width: 100% !important;
      box-sizing: border-box;
    }

    .dash-body {
      margin: 0 !important;
      padding: 12px !important;
      width: 100% !important;
      box-sizing: border-box;
    }

    /* ---- Hide desktop date+calendar on the right (top row) ---- */
    .dash-body > table > tbody > tr:first-child td:nth-child(2),
    .dash-body > table > tbody > tr:first-child td:nth-child(3) {
      display: none !important;
    }

    /* ---- Use space: remove big left margin ---- */
    .dash-body > table > tbody > tr:nth-child(2) td div[style*="margin-left:45px"] {
      margin-left: 0 !important;
    }

    /* ---- Filters stack nicely ---- */
    .filters {
      width: 100%;
      gap: 10px !important;
    }

    #searchInput {
      width: 100% !important;
      max-width: 100% !important;
    }

    .filters .login-btn {
      width: 100% !important;
      justify-content: center;
    }

    /* ---- Sidebar becomes drawer ---- */
    .menu {
      display: block !important;
      position: fixed !important;
      top: 56px !important;
      left: -280px !important;
      width: 280px !important;
      max-width: 86vw !important;
      height: calc(100vh - 56px) !important;
      background: lightgreen !important;
      z-index: 10040 !important;
      overflow-y: auto !important;
      transition: left .25s ease !important;
      box-shadow: 10px 0 30px rgba(0,0,0,.12) !important;
    }
    .menu.active { left: 0 !important; }

    /* ---- Overlay behind drawer ---- */
    #menu-overlay {
      display: block !important;
      position: fixed !important;
      inset: 0 !important;
      background: rgba(0,0,0,.35) !important;
      opacity: 0 !important;
      pointer-events: none !important;
      transition: opacity .2s ease !important;
      z-index: 10030 !important;
    }

    body.menu-open #menu-overlay {
      opacity: 1 !important;
      pointer-events: auto !important;
    }
    body.menu-open { overflow: hidden; }

    /* ---- TABLE: full width + compressed ---- */
    .abc.scroll {
      width: 100% !important;
      overflow-x: hidden !important;   /* no sideways scroll */
      margin-top: 10px !important;
    }

    #accountsTable {
      width: 100% !important;
      table-layout: fixed !important;
    }

    #accountsTable th,
    #accountsTable td {
      padding: 10px 8px !important;
      font-size: 13px !important;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      vertical-align: middle;
    }

    /* Hide Specialty column on mobile to save space */
    #accountsTable .col-specialty,
    #accountsTable th.col-specialty,
    #accountsTable td.col-specialty {
      display: none !important;
    }

    /* Make Actions column smaller */
    #accountsTable th:last-child,
    #accountsTable td:last-child {
      width: 42% !important;
    }

    /* Action buttons stack */
    #accountsTable td:last-child form,
    #accountsTable td:last-child button {
      width: 100%;
      margin: 0 !important;
    }

    #accountsTable td:last-child form {
      display: block !important;
      margin-top: 8px !important;
    }

    /* Modals fit mobile */
    .overlay .popup {
      width: min(520px, 92vw) !important;
      max-height: 82vh !important;
      overflow: auto !important;
      margin: 0 !important;
      left: 50% !important;
      top: 50% !important;
      transform: translate(-50%, -50%) !important;
    }
  }



/* Mobile: clean action buttons */
@media (max-width: 768px) {

  /* Edit + Archive buttons */
  .sub-table td:last-child .btn,
  .sub-table td:last-child .login-btn {
    width: 100%;
    padding: 8px 10px !important;
    font-size: 13px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
  }

  /* Reduce icon size */
  .sub-table td:last-child i {
    font-size: 13px;
  }
}
@media (max-width: 768px) {

  /* Keep table layout stable */
  .sub-table {
    width: 100% !important;
    table-layout: fixed;
  }

  /* Stack buttons inside wrapper (NOT the <td>) */
  .action-stack {
    display: flex;
    flex-direction: column;
    gap: 8px;
    align-items: stretch;
  }

  /* Make buttons consistent and not clipped */
  .action-stack .login-btn,
  .action-stack .btn,
  .action-stack button {
    width: 100% !important;
    min-height: 38px;
    padding: 9px 12px !important;
    font-size: 13px !important;
    border-radius: 10px;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px;
    white-space: nowrap;
    box-sizing: border-box;
  }

  /* Fix icon alignment */
  .action-stack i {
    margin: 0 !important;
    font-size: 14px;
  }

}

@media (max-width: 768px) {
  .mobile-right{
    display:flex !important;
    align-items:center !important;
    gap:8px !important;
  }

  .mh-cal{
    width:34px !important;
    height:34px !important;
    border-radius:10px !important;
    border:none !important;
    background:rgba(255, 255, 255, 0) !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    cursor:pointer !important;
    padding:0 !important;
    overflow: visible !important;
  }

  .mh-cal img{
    width:18px !important;
    height:18px !important;
    display:block !important;
    opacity:1 !important;
    visibility:visible !important;
  }
}

    </style>
</head>

<body data-view-mode="<?= $isArchiveView ? 'archive' : 'active' ?>">
<!-- ✅ MOBILE HEADER -->
<div class="mobile-header" id="mobileHeader">

    <div class="mobile-left">
        <div id="hamburger-menu">
            <div class="bar"></div>
            <div class="bar"></div>
            <div class="bar"></div>
        </div>
    </div>

    <div class="mobile-center">Home</div>

    <div class="mobile-right mh-right">
        <div class="mh-date">
            <span class="mh-date-label">Date</span>
            <span class="mh-date-value"><?php echo $today; ?></span>
        </div>
        <button class="mh-cal btn-label" type="button" aria-label="Calendar">
            <img src="../img/calendar.svg" width="100%">
        </button>
    </div>
</div>

<div id="menu-overlay"></div>



    <div class="container">
        <div class="menu">
            <table class="menu-container" border="0">
                <tr>
                    <td style="padding:10px" colspan="2">
                        <table border="0" class="profile-container">
                            <tr>
                                <td width="30%" style="padding-left:20px" >
                                <p class="profile-title"><?php echo substr($username,0,50) ?></p>
                                <p class="profile-subtitle"><?php echo substr($useremail,0,50)  ?></p>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="2">
                                <a href="../logout.php" ><input type="button" value="Log out" class="logout-btn btn-primary-soft btn"></a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr class="menu-row">
                    <td class="menu-btn ">
                        <a href="dashboard.php" class="non-style-link-menu ">
                            <p class="menu-text">Dashboard</p>
                        </a>
                    </td>
                </tr>
                <tr class="menu-row">
                    <td class="menu-btn ">
                        <a href="logs.php" class="non-style-link-menu">
                            <p class="menu-text">Logs</p>
                        </a>
                    </td>
                </tr>
                <tr class="menu-row">
                    <td class="menu-btn menu-active">
                        <a href="clients.php" class="non-style-link-menu non-style-link-menu-active">
                            <p class="menu-text">Accounts</p>
                        </a>
                    </td>
                </tr>
                <tr class="menu-row">
                    <td class="menu-btn ">
                        <a href="cms.php" class="non-style-link-menu">
                            <p class="menu-text">CMS</p>
                        </a>
                    </td>
                </tr>
            </table>
        </div>
		
		<div class="dash-body" style="margin-top: 15px">
            <table border="0" width="100%" style=" border-spacing: 0;margin:0;padding:0;margin-top:25px; ">
                <tr>
                    <td>
                        <p class="heading-main12" style="font-size:20px;color:rgb(49,49,49);margin-bottom:10px;">
                        <span id="accountsHeading">
                            <?= $roleLabel ?><?= $deptBadge ? " <small style='color:#2d6a4f;'>{$deptBadge}</small>" : '' ?> (<?= $searchQuery !== '' ? $filteredCount : $totalCount ?>)
                        </span>
                        </p>
                    </td>
                    <td width="15%">
                        <p style="font-size: 14px;color: rgb(119, 119, 119);padding: 0;margin: 0;text-align: right;">
                            Today's Date
                        </p>
                        <p class="heading-sub12" style="padding: 0;margin: 0;">
                        <?php 
                            date_default_timezone_set('Asia/Manila');
                            $today = date('Y-m-d');
                            echo $today;
                        ?>
                        </p>
                    </td>
                    <td width="10%">
                        <button  class="btn-label"  style="display: flex;justify-content: center;align-items: center;"><img src="../img/calendar.svg" width="100%"></button>
                    </td>
                </tr>
                <tr>
                    <td colspan="4" style="padding-top:10px;">
                        <div style="margin-left:45px;">
                            <div class="filters">
                                <div class="role-filter" style="display:flex;gap:10px;align-items:center;">
                                    <label for="roleSelect" style="font-size:14px;color:#555;">Filter by role:</label>
                                    <select name="role" id="roleSelect" class="input-text header-searchbar" style="max-width:220px;" <?= $isArchiveView ? 'disabled' : '' ?>>
                                        <option value="patient" <?= $role==='patient'?'selected':''; ?>>Patients</option>
                                        <option value="it"      <?= $role==='it'?'selected':''; ?>>IT</option>
                                        <option value="doctor"  <?= $role==='doctor'?'selected':''; ?>>Doctor</option>
                                        <option value="admin"   <?= $role==='admin'?'selected':''; ?>>Admin</option>
                                    </select>
                                </div>

                                <div id="deptFilterWrap" class="role-filter" style="display:<?= (!$isArchiveView && $showSpecialty) ? 'inline-flex' : 'none' ?>;gap:10px;align-items:center;">
                                  <label for="deptSelect" style="font-size:14px;color:#555;">Department:</label>
                                  <select id="deptSelect" class="input-text header-searchbar" style="max-width:280px;">
                                    <option value="">All Departments</option>
                                    <?php foreach ($specialties as $sid => $label): ?>
                                      <option value="<?= htmlspecialchars((string)$sid, ENT_QUOTES, 'UTF-8'); ?>"
                                        <?= ($deptId !== '' && (string)$deptId === (string)$sid) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                                      </option>
                                    <?php endforeach; ?>
                                  </select>
                                </div>

                                <div class="search-filter" style="display:flex;gap:10px;align-items:center;">
                                    <input type="text" id="searchInput" class="input-text header-searchbar"
                                        placeholder="Search by name or email"
                                        value="<?= htmlspecialchars($searchQuery, ENT_QUOTES) ?>"
                                        list="emailList">

                                    <button id="searchBtn" class="login-btn btn-primary btn">
                                        <i class="fas fa-search" style="margin-right:8px;"></i>Search
                                    </button>

                                    <button id="clearBtn" class="login-btn btn-primary-soft btn">
                                        <i class="fas fa-times-circle" style="margin-right:8px;"></i>Clear
                                    </button>

                                    <?php if (!$isArchiveView): ?>
                                    <button id="newAccountBtn" class="login-btn btn-primary btn">
                                        <i class="fas fa-user-plus" style="margin-right:8px;"></i>New Account
                                    </button>
                                    <?php endif; ?>

                                    <?php if ($isArchiveView): ?>
                                      <button id="archivePatientsBtn" class="login-btn btn-primary-soft btn">
                                        <i class="fas fa-users" style="margin-right:8px;"></i>Back to Active
                                      </button>
                                    <?php else: ?>
                                      <button id="archivePatientsBtn" class="login-btn btn-primary-soft btn">
                                        <i class="fas fa-archive" style="margin-right:8px;"></i>Archived Patients
                                      </button>
                                    <?php endif; ?>

                                    <!-- ✅ HARD DELETE ALL CURRENT ROLE (kept commented for safety)
                                    <button id="deleteAllBtn" class="login-btn btn-danger btn">
                                        <i class="fas fa-trash-alt" style="margin-right:8px;"></i>Delete All (Current Role)
                                    </button> -->
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>

				<tr>
					<td colspan="9" style="padding-top: 25px;">
					<center>
					
					<div class="abc scroll">
                        <table id="accountsTable"
                                class="sub-table scrolldown <?= $showSpecialty ? '' : 'hide-specialty' ?>"
                                width="80%" border="0">
                            <thead>
                            <tr>
                                <th class="table-headin">Email</th>
                                <th class="table-headin">Name</th>
                                <th class="table-headin col-specialty">Specialty / Department</th> 
                                <th class="table-headin">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php echo $rowsHtml; ?>
                            </tbody>
                        </table>
                        </div>
				</tr>	

          <!-- Edit Account Modal -->
<div class="overlay" id="editAccountPopup" style="display: none; background-color: rgba(0, 0, 0, 0.7); z-index: 999; justify-content: center;">
  <div class="popup" style="max-width: 450px; background-color: #fff; padding: 30px; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.2); justify-content: center; margin-top: -30px; margin-left: 80px;">
    <a href="#" class="close" onclick="closeEditAccountPopup()" style="position: absolute; top: 15px; right: 20px; font-size: 28px; text-decoration: none; color: #555;">&times;</a>
    <h2 style="margin-top: 0; color: #2d6a4f; text-align: center;">Edit Account</h2>
    <form action="" method="POST">
      <input type="hidden" id="editAccountKey" name="edit_key">
      <input type="hidden" id="editRole" name="edit_role">

      <div class="form-group" style="margin-bottom: 15px;">
        <label for="editEmail" style="font-weight: bold;">Email:</label>
        <input type="text" id="editEmail" name="edit_email" required
               style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ccc; margin-top: 5px;">
      </div>

      <div class="form-group" style="margin-bottom: 15px;">
        <label for="name" class="form-label" style="margin-right: 10px;">First Name:</label>
             <input type="text" id="editFname" name="fname" class="input-text" placeholder="First Name"  style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ccc; margin-top: 5px;">
      </div>
      <div class="form-group" style="margin-bottom: 15px;">
              <label for="name" class="form-label" style="margin-right: 10px; margin-top: 20px;">Last Name:</label>
              <input type="text" id="editLname" name="lname" class="input-text" placeholder="Last Name"  style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ccc; margin-top: 5px;">
      </div>

      <hr style="margin: 20px 0; border-top: 1px solid #eee;">

      <div class="form-group" style="margin-bottom: 15px;">
        <label for="editPassword" style="font-weight: bold;">New Password:</label>
        <input type="password" id="editPassword" name="edit_password"
               placeholder="Leave blank to keep current"
               style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ccc; margin-top: 5px;">
      </div>

      <div class="form-group" style="margin-bottom: 25px;">
        <label for="editConfirmPassword" style="font-weight: bold;">Confirm Password:</label>
        <input type="password" id="editConfirmPassword" name="edit_confirm_password"
               style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ccc; margin-top: 5px;">
      </div>

      <div style="text-align: center;">
        <button type="submit" name="save_account_edit"
                style="padding: 12px 30px; background-color: #40916c; color: #fff; border: none; border-radius: 8px; font-size: 16px; cursor: pointer; transition: background 0.3s;">
          Save Changes
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Create Account Modal -->
<div class="overlay" id="createAccountPopup" style="display:none;background-color:rgba(0,0,0,0.7);z-index:999;justify-content:center;">
  <div class="popup" style="max-width:480px;background-color:#fff;padding:30px;border-radius:15px;box-shadow:0 5px 15px rgba(0,0,0,0.2);justify-content:center;margin-top:-30px;margin-left:80px;">
    <a href="#" class="close" onclick="closeCreateAccountPopup()" style="position:absolute;top:15px;right:20px;font-size:28px;text-decoration:none;color:#555;">&times;</a>
    <h2 style="margin-top:0;color:#2d6a4f;text-align:center;">Create Account</h2>
    <form action="" method="POST">
      <input type="hidden" name="create_account" value="1">

      <div class="form-group" style="margin-bottom:15px;">
        <label for="createRole" style="font-weight:bold; display:block; margin-bottom:5px;">Role:</label>
        <select id="createRole" name="create_role" class="input-text" required
                style="width:100%; padding:10px; border-radius:8px; border:1px solid #ccc; margin-top:5px; background-color:#fff;">
          <option value="" disabled selected>Select role</option>
          <option value="admin">Admin</option>
          <option value="doctor">Doctor</option>
          <option value="it">IT</option>
        </select>
      </div>

      <div id="createSpecialtyWrap" class="form-group" style="margin-bottom:15px; display:none;">
        <label for="createSpecialty" class="form-label" style="font-weight:bold;">Specialty:</label>
        <select id="createSpecialty" name="create_specialty_id" class="input-text"
                style="width:100%; padding:10px; border-radius:8px; border:1px solid:#ccc; margin-top:5px;">
            <option value="" disabled selected>Select specialty</option>
            <?php foreach ($specialties as $sid => $label): ?>
            <option value="<?php echo htmlspecialchars($sid, ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
            </option>
            <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group" style="margin-bottom:15px;">
        <label for="createEmail" style="font-weight:bold;">Email:</label>
        <input type="email" id="createEmail" name="create_email" placeholder="Email" required
               style="width:100%;padding:10px;border-radius:8px;border:1px solid #ccc;margin-top:5px;">
      </div>

      <div class="form-group" style="margin-bottom:15px;">
        <label for="createFname" class="form-label">First Name:</label>
        <input type="text" id="createFname" name="create_fname" placeholder="First Name" required
               style="width:100%;padding:10px;border-radius:8px;border:1px solid:#ccc;margin-top:5px;">
      </div>

      <div class="form-group" style="margin-bottom:15px;">
        <label for="createLname" class="form-label">Last Name:</label>
        <input type="text" id="createLname" name="create_lname" placeholder="Last Name" required
               style="width:100%;padding:10px;border-radius:8px;border:1px solid:#ccc;margin-top:5px;">
      </div>

      <div class="form-group" style="margin-bottom:20px;">
        <label for="createPassword" class="form-label">Password:</label>
        <input type="password" id="createPassword" name="create_password" placeholder="Password" required
               style="width:100%;padding:10px;border-radius:8px;border:1px solid:#ccc;margin-top:5px;">
      </div>

      <div class="form-group" style="margin-bottom:20px;">
        <label for="createConfirmPassword" class="form-label">Confirm Password:</label>
        <input type="password" id="createConfirmPassword" name="create_confirm_password" placeholder="Confirm Password" required
               style="width:100%;padding:10px;border-radius:8px;border:1px solid:#ccc;margin-top:5px;">
      </div>

      <div style="text-align:center;">
        <button type="submit" id="createSubmitBtn"
                style="padding:12px 30px;background-color:#40916c;color:#fff;border:none;border-radius:8px;font-size:16px;cursor:pointer;">
          Create
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ✅ HARD DELETE ALL MODAL -->
<div class="overlay" id="deleteAllPopup" style="display:none;background-color:rgba(0,0,0,0.7);z-index:999;justify-content:center;">
  <div class="popup" style="max-width:420px;background:#fff;padding:24px 26px;border-radius:12px;box-shadow:0 8px 20px rgba(0,0,0,.25);position:relative;">
    <a href="#" class="close" onclick="closeDeleteAllPopup()" style="position:absolute;top:10px;right:14px;font-size:26px;text-decoration:none;color:#444;">&times;</a>

    <h3 style="margin:0 0 10px;color:#b91c1c;text-align:center;">HARD DELETE ALL</h3>
    <p style="font-size:14px;color:#444;text-align:center;line-height:1.4;">
      This will permanently delete <b>ALL accounts in the selected role</b><br>
      and remove them from Firebase Auth. <br><br>
      Type <b>DELETE</b> to confirm.
    </p>

    <form method="POST" id="deleteAllForm">
      <input type="hidden" name="hard_delete_all_role" value="1">
      <input type="hidden" id="roleToDeleteInput" name="role_to_delete" value="">
      <input type="text" id="deleteConfirmInput" class="input-text" placeholder="Type DELETE"
             style="width:100%;padding:10px;border:1px solid #ccc;border-radius:8px;margin-top:6px;">
      <button type="submit" id="deleteAllConfirmBtn" class="login-btn btn-danger btn"
              style="width:100%;margin-top:12px;cursor:not-allowed;opacity:.6;" disabled>
        Confirm Hard Delete
      </button>
    </form>
  </div>
</div>

<!-- datalist for email autocomplete -->
<datalist id="emailList">
<?php foreach ($datalistEmails as $em) {
    if ($em) {
        echo '<option value="'.htmlspecialchars($em, ENT_QUOTES, 'UTF-8').'"></option>';
    }
} ?>
</datalist>

<script>
// === Global edit modal handlers ===
function openEditAccountPopup(key, email, fname, lname) {
  document.getElementById('editAccountKey').value = key;
  document.getElementById('editEmail').value = email;
  document.getElementById('editFname').value = fname || '';
  document.getElementById('editLname').value = lname || '';
  const roleSelect = document.getElementById('roleSelect');
  document.getElementById('editRole').value = roleSelect ? roleSelect.value : 'patient';
  document.getElementById('editAccountPopup').style.display = 'block';
}
function closeEditAccountPopup() {
  document.getElementById('editAccountPopup').style.display = 'none';
}

// --- Debounce helper
function debounce(fn, delay = 300) {
  let t;
  return (...args) => {
    clearTimeout(t);
    t = setTimeout(() => fn.apply(null, args), delay);
  };
}

// AJAX filter logic
(function(){
  const roleSelect   = document.getElementById('roleSelect');
  const searchInput  = document.getElementById('searchInput');
  const searchBtn    = document.getElementById('searchBtn');
  const clearBtn     = document.getElementById('clearBtn');
  const tbody        = document.querySelector('table.sub-table tbody');
  const headingEl    = document.getElementById('accountsHeading');

  const deptWrap     = document.getElementById('deptFilterWrap');
  const deptSelect   = document.getElementById('deptSelect');

  const viewMode     = document.body.dataset.viewMode || 'active';
  const archiveBtn   = document.getElementById('archivePatientsBtn');

  function refreshArchiveBtnLabel() {
    if (!archiveBtn) return;
    // Only change label in ACTIVE view.
    if (viewMode !== 'active') return;

    const role = roleSelect ? roleSelect.value : 'patient';
    let labelText;

    switch (role) {
      case 'patient':
        labelText = 'Archived Patients';
        break;
      case 'it':
        labelText = 'Archived IT';
        break;
      case 'doctor':
        labelText = 'Archived Doctors';
        break;
      case 'admin':
        labelText = 'Archived Admins';
        break;
      default:
        labelText = 'Archived Accounts';
    }

    archiveBtn.innerHTML =
      "<i class='fas fa-archive' style='margin-right:8px;'></i>" + labelText;
  }

  let currentController = null;

  function fetchAndRender(){
    const role   = roleSelect ? roleSelect.value : 'patient';
    const qRaw   = (searchInput.value || '').trim();
    const search = encodeURIComponent(qRaw);

    const deptVal = (!<?= json_encode($isArchiveView); ?> && deptSelect) ? (deptSelect.value || '') : '';
    const deptQS  = deptVal ? `&dept=${encodeURIComponent(deptVal)}` : '';

    const url    = `${location.pathname}?ajax=1&view=${encodeURIComponent(viewMode)}&role=${encodeURIComponent(role)}${search ? `&search=${search}` : ''}${deptQS}`;

    if (currentController) currentController.abort();
    currentController = new AbortController();

    fetch(url, { method: 'GET', credentials: 'same-origin', signal: currentController.signal })
      .then(r => r.json())
      .then(data => {
        tbody.innerHTML = data.rows_html || '<tr><td colspan="3"><center>No data</center></td></tr>';
        if (headingEl) {
          const hasSearch = (qRaw.length > 0);
          const count = hasSearch ? (data.count_filtered ?? 0) : (data.count_total ?? 0);
          headingEl.textContent = `${data.role_label} (${count})`;
        }

        const table = document.getElementById('accountsTable');
        if (table) {
          table.classList.toggle('hide-specialty', !data.show_specialty);
        }

        if (deptWrap) {
          deptWrap.style.display = (viewMode === 'active' && data.show_specialty) ? 'inline-flex' : 'none';
          if (!(viewMode === 'active' && data.show_specialty) && deptSelect) deptSelect.value = '';
        }

        try { window.history.replaceState(null, '', location.pathname); } catch (_) {}
      })
      .catch(err => {
        if (err.name !== 'AbortError') console.error('Fetch error:', err);
      });
  }

  const debouncedFetch = debounce(fetchAndRender, 300);

  if (viewMode === 'active') {
    roleSelect && roleSelect.addEventListener('change', () => {
      if (deptSelect) deptSelect.value = '';
      fetchAndRender();
      refreshArchiveBtnLabel(); // update button text when filter changes
    });
    if (deptSelect) {
      deptSelect.addEventListener('change', (e) => {
        e.preventDefault();
        fetchAndRender();
      });
    }
  }

  searchInput.addEventListener('input', debouncedFetch);
  searchInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); fetchAndRender(); }
  });
  if (searchBtn) searchBtn.addEventListener('click', (e) => { e.preventDefault(); fetchAndRender(); });
  if (clearBtn) clearBtn.addEventListener('click', (e) => { e.preventDefault(); searchInput.value=''; fetchAndRender(); });

  window.openEditAccountPopup = openEditAccountPopup;
  window.closeEditAccountPopup = closeEditAccountPopup;

  // Initial label sync
  refreshArchiveBtnLabel();
})();

// Create Account popup handlers
function openCreateAccountPopup() {
  const roleSelect = document.getElementById('roleSelect');
  const createRole = document.getElementById('createRole');
  if (roleSelect && createRole) {
    const r = roleSelect.value;
    if (['admin','doctor','it'].includes(r)) createRole.value = r;
    else createRole.value = 'admin';
  }
  document.getElementById('createAccountPopup').style.display = 'block';
}
function closeCreateAccountPopup() {
  document.getElementById('createAccountPopup').style.display = 'none';
}
(function(){
  const newBtn = document.getElementById('newAccountBtn');
  if (newBtn) newBtn.addEventListener('click', (e) => {
    e.preventDefault();
    openCreateAccountPopup();
  });
})();

// Show/hide Specialty inside Create Account modal
(function(){
  const roleSel   = document.getElementById('createRole');
  const spWrap    = document.getElementById('createSpecialtyWrap');
  const spSelect  = document.getElementById('createSpecialty');

  function syncSpecialtyVisibility(){
    const role = (roleSel?.value || '').toLowerCase();
    const needsSp = role === 'doctor' || role === 'admin';
    if (!spWrap || !spSelect) return;

    spWrap.style.display = needsSp ? 'block' : 'none';
    spSelect.required = needsSp;
    if (!needsSp) spSelect.value = '';
  }

  roleSel && roleSel.addEventListener('change', syncSpecialtyVisibility);
  syncSpecialtyVisibility();
})();

/* ===========================
   HARD DELETE ALL MODAL JS
   =========================== */
function openDeleteAllPopup(){
  const roleSel = document.getElementById('roleSelect');
  const roleVal = roleSel ? roleSel.value : 'patient';

  document.getElementById('roleToDeleteInput').value = roleVal;
  document.getElementById('deleteConfirmInput').value = '';
  document.getElementById('deleteAllConfirmBtn').disabled = true;
  document.getElementById('deleteAllConfirmBtn').style.cursor = 'not-allowed';
  document.getElementById('deleteAllConfirmBtn').style.opacity = '.6';

  document.getElementById('deleteAllPopup').style.display = 'flex';
}
function closeDeleteAllPopup(){
  document.getElementById('deleteAllPopup').style.display = 'none';
}

(function(){
  const delBtn = document.getElementById('deleteAllBtn');
  const confirmInput = document.getElementById('deleteConfirmInput');
  const confirmBtn = document.getElementById('deleteAllConfirmBtn');

  if (delBtn) delBtn.addEventListener('click', (e)=>{
    e.preventDefault();
    openDeleteAllPopup();
  });

  if (confirmInput) confirmInput.addEventListener('input', ()=>{
    const ok = confirmInput.value.trim().toUpperCase() === 'DELETE';
    confirmBtn.disabled = !ok;
    confirmBtn.style.cursor = ok ? 'pointer' : 'not-allowed';
    confirmBtn.style.opacity = ok ? '1' : '.6';
  });
})();

// Toggle Archive / Active view
(function(){
  const archiveBtn = document.getElementById('archivePatientsBtn');
  if (!archiveBtn) return;

  archiveBtn.addEventListener('click', (e) => {
    e.preventDefault();
    const url = new URL(window.location.href);
    const viewMode = document.body.dataset.viewMode || 'active';

    if (viewMode === 'archive') {
      // Back to active, keep role param
      url.searchParams.delete('view');
    } else {
      const roleSel = document.getElementById('roleSelect');
      const roleVal = roleSel ? roleSel.value : 'patient';
      url.searchParams.set('view', 'archive');
      url.searchParams.set('role', roleVal); // respect current filter
    }
    url.searchParams.delete('ajax');
    window.location.href = url.toString();
  });
})();


(function () {
  const hamburgerMenu = document.getElementById('hamburger-menu');
  const menu = document.querySelector('.menu');
  const overlay = document.getElementById('menu-overlay');

  function openMenu() {
    hamburgerMenu.classList.add('active');
    menu.classList.add('active');
    document.body.classList.add('menu-open');
  }

  function closeMenu() {
    hamburgerMenu.classList.remove('active');
    menu.classList.remove('active');
    document.body.classList.remove('menu-open');
  }

  if (hamburgerMenu) {
    hamburgerMenu.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      if (menu.classList.contains('active')) closeMenu();
      else openMenu();
    });
  }

  if (overlay) overlay.addEventListener('click', closeMenu);

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeMenu();
  });
})();

</script>

</body>
</html>
