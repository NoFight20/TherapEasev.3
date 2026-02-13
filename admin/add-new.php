<?php

// ------- Session guard -------
session_name('sess_admin');
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user'] === '' || ($_SESSION['usertype'] ?? '') !== 'a') {
    header('Location: ../login.php'); exit();
}
$adminEmail = $_SESSION['user'];

// ------- Firebase (Kreait) -------
include("../connection.php");

// ------- Helpers -------
function genUidWithPrefix(string $prefix = 'D-'): string {
    return $prefix . bin2hex(random_bytes(8)); // 16 hex chars
}
function redirect_with_msg(string $href, string $msg) {
    $sep = (strpos($href, '?') !== false) ? '&' : '?';
    header('Location: ' . $href . $sep . 'msg=' . rawurlencode($msg));
    exit();
}
function norm($s){ return mb_strtolower(trim((string)$s)); }

// ------- Accept POST only -------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_with_msg('doctors.php?action=add&error=3', 'Error: Invalid request method.');
}

// ------- Load admin profile -------
try {
    $adminSnap = $database->getReference('admin')
        ->orderByChild('email')->equalTo($adminEmail)->getSnapshot();

    if (!$adminSnap->exists()) {
        redirect_with_msg('doctors.php?action=add&error=3', 'Admin record not found.');
    }

    $adminRows = $adminSnap->getValue();
    $adminRow  = reset($adminRows);

    $adminSpecIdRaw = trim((string)($adminRow['spec_id'] ?? ''));
    $adminSnameRaw  = $adminRow['sname'] ?? '';

    // sname can be array/string in your app; pick a usable string
    if (is_array($adminSnameRaw)) {
        $tmp = array_values(array_filter(array_map('trim', $adminSnameRaw)));
        $adminSname = $tmp[0] ?? '';
    } else {
        $adminSname = trim((string)$adminSnameRaw);
    }
} catch (\Throwable $e) {
    error_log('Admin load failed: '.$e->getMessage());
    redirect_with_msg('doctors.php?action=add&error=5', 'Unable to load admin profile.');
}

// ------- Resolve specialty id + name -------
// Priority: 1) admin.spec_id (if valid) -> 2) match admin.sname in /specialties
// -> 3) fallback to posted <select name="spec">
$resolvedSpecId   = '';
$resolvedSpecName = '';

try {
    $specialties = $database->getReference('specialties')->getValue() ?: [];

    $useId = function($id) use ($specialties, &$resolvedSpecId, &$resolvedSpecName) {
        if ($id !== '' && isset($specialties[$id]) && !empty($specialties[$id]['sname'])) {
            $resolvedSpecId   = $id;
            $resolvedSpecName = trim($specialties[$id]['sname']);
            return true;
        }
        return false;
    };

    // 1) admin.spec_id
    if (!$useId($adminSpecIdRaw)) {
        // 2) match by admin.sname
        $want = norm($adminSname);
        if ($want !== '') {
            foreach ($specialties as $sid => $row) {
                $label = norm($row['sname'] ?? '');
                if ($label !== '' && $label === $want) {
                    if ($useId($sid)) { break; }
                }
            }
        }

        // 3) fallback to posted selection
        if ($resolvedSpecId === '') {
            $postedSpec = trim((string)($_POST['spec'] ?? ''));
            $useId($postedSpec);
        }
    }
} catch (\Throwable $e) {
    error_log('Specialty resolve failed: '.$e->getMessage());
    redirect_with_msg('doctors.php?action=add&error=5', 'Unable to resolve specialty.');
}

// If still unresolved, stop with a clear fix path
if ($resolvedSpecId === '' || $resolvedSpecName === '') {
    redirect_with_msg(
        'doctors.php?action=add&error=3',
        'No valid specialty found. Set admin "spec_id" (or matching "sname") OR choose a specialty from the dropdown.'
    );
}

// ------- Collect user-entered fields -------
// NEW: username is now the required login ID for doctors
$usernameNew = trim($_POST['username'] ?? '');
$fnameNew    = trim($_POST['fname']    ?? '');
$lnameNew    = trim($_POST['lname']    ?? '');
$emailNew    = trim($_POST['email']    ?? '');   // optional (can be blank)
$teleNew     = trim($_POST['tele']     ?? '');
$passNew     = $_POST['password']      ?? '';
$confirmNew  = $_POST['cpassword']     ?? '';

// ------- Validate fields -------
if ($usernameNew === '') {
    redirect_with_msg('doctors.php?action=add&error=3', 'Please enter a username.');
}
if ($fnameNew === '' || $lnameNew === '' || $confirmNew === '') {
    redirect_with_msg('doctors.php?action=add&error=3', 'All required fields must be filled.');
}
if ($passNew !== $confirmNew) {
    redirect_with_msg('doctors.php?action=add&error=2', 'Passwords do not match.');
}

// Optional: username pattern check
if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $usernameNew)) {
    redirect_with_msg(
        'doctors.php?action=add&error=3',
        'Username must be 3–50 characters and can only contain letters, numbers, dot, underscore, and dash.'
    );
}

// Email is optional now: allow blank OR valid
if ($emailNew !== '' && !filter_var($emailNew, FILTER_VALIDATE_EMAIL)) {
    redirect_with_msg(
        'doctors.php?action=add&error=3',
        'Please enter a valid email address or leave it blank.'
    );
}

// Decide which email to use in Firebase Auth (required by Firebase)
// If admin provided a real email, use it; otherwise synthesize from username
$usernameSlug = preg_replace('/[^a-z0-9._-]+/i', '', $usernameNew);
if ($usernameSlug === '') {
    $usernameSlug = 'doctor' . bin2hex(random_bytes(3));
}

if ($emailNew !== '') {
    $emailLower = strtolower($emailNew);
} else {
    // Internal-only login email derived from username
    $emailLower = strtolower($usernameSlug . '@therapease.local');
}

// ------- Duplicate checks -------
// 1) Firebase Auth is canonical (based on Auth email we will use)
try {
    // will throw \Kreait\Firebase\Exception\Auth\UserNotFound if not exists
    $existingAuthUser = $auth->getUserByEmail($emailLower);
    // If no exception, a user exists
    redirect_with_msg('doctors.php?action=add&error=1', 'Error: Username/email already exists (Auth).');
} catch (\Kreait\Firebase\Exception\Auth\UserNotFound $e) {
    // not found in Auth -> OK
} catch (\Throwable $e) {
    error_log('Auth duplicate check error: '.$e->getMessage());
    // continue to DB check
}

// 2) Strict Realtime DB checks for username + email (skip archived/deleted if present)
try {
    $dbDup = false;

    // Check USERS by username
    $usersByUsername = $database->getReference('users')
        ->orderByChild('username')->equalTo($usernameNew)->getSnapshot();

    if ($usersByUsername->exists()) {
        foreach ($usersByUsername->getValue() as $uid => $row) {
            $archived = (isset($row['archived']) && $row['archived']) || (isset($row['deleted']) && $row['deleted']);
            if ($archived) continue;
            if (norm($row['username'] ?? '') === norm($usernameNew)) {
                $dbDup = true; break;
            }
        }
    }

    // Check DOCTOR by username
    if (!$dbDup) {
        $docsByUsername = $database->getReference('doctor')
            ->orderByChild('username')->equalTo($usernameNew)->getSnapshot();
        if ($docsByUsername->exists()) {
            foreach ($docsByUsername->getValue() as $docId => $row) {
                $archived = (isset($row['archived']) && $row['archived']) || (isset($row['deleted']) && $row['deleted']);
                if ($archived) continue;
                if (norm($row['username'] ?? '') === norm($usernameNew)) {
                    $dbDup = true; break;
                }
            }
        }
    }

    // Also guard by email if a real email is provided
    if (!$dbDup && $emailNew !== '') {
        $usersByEmail = $database->getReference('users')
            ->orderByChild('email')->equalTo($emailLower)->getSnapshot();
        if ($usersByEmail->exists()) {
            foreach ($usersByEmail->getValue() as $uid => $row) {
                $archived = (isset($row['archived']) && $row['archived']) || (isset($row['deleted']) && $row['deleted']);
                if ($archived) continue;
                if (norm($row['email'] ?? '') === $emailLower) {
                    $dbDup = true; break;
                }
            }
        }

        if (!$dbDup) {
            $docsByEmail = $database->getReference('doctor')
                ->orderByChild('email')->equalTo($emailLower)->getSnapshot();
            if ($docsByEmail->exists()) {
                foreach ($docsByEmail->getValue() as $docId => $row) {
                    $archived = (isset($row['archived']) && $row['archived']) || (isset($row['deleted']) && $row['deleted']);
                    if ($archived) continue;
                    if (norm($row['email'] ?? '') === $emailLower) {
                        $dbDup = true; break;
                    }
                }
            }
        }
    }

    if ($dbDup) {
        redirect_with_msg('doctors.php?action=add&error=1', 'Error: Username/email already exists (DB).');
    }
} catch (\Throwable $e) {
    error_log('DB duplicate check failed: '.$e->getMessage());
    // don’t block; Auth already the main source of truth
}

// ------- Create Auth user with stable prefixed UID -------
$newId = genUidWithPrefix('D-');
$full  = trim($fnameNew.' '.$lnameNew);

try {
    $auth->createUser([
        'uid'         => $newId,
        'email'       => $emailLower,
        'password'    => $confirmNew,
        'displayName' => $full,
    ]);
} catch (\Throwable $e) {
    error_log('Auth create error: '.$e->getMessage());
    redirect_with_msg('doctors.php?action=add&error=5', 'Failed to create Auth user: '.$e->getMessage());
}

$nowUnix        = time();
$hashedPassword = password_hash($confirmNew, PASSWORD_BCRYPT);

// This is the email that will be used for login internally (could be synthetic)
// If you want to store a separate contact email, you can add another field later.
$loginEmail = $emailLower;

$doctorPayload = [
    'doctor_uid' => $newId,
    'username'   => $usernameNew,
    'email'      => $loginEmail,
    'tele'       => $teleNew,
    'fname'      => $fnameNew,
    'lname'      => $lnameNew,
    'name'       => $full,
    'specialty'  => $resolvedSpecId,
    'sname'      => $resolvedSpecName,
    'type'       => 'd',
    'created'    => $nowUnix,
    'password'   => $hashedPassword
];

$userPayload = [
    'username'   => $usernameNew,
    'email'      => $loginEmail,
    'fname'      => $fnameNew,
    'lname'      => $lnameNew,
    'name'       => $full,
    'type'       => 'd',
    'specialty'  => $resolvedSpecId,
    'sname'      => $resolvedSpecName,
    'created'    => $nowUnix,
    'password'   => $hashedPassword
];

// ------- Persist to Realtime DB -------
try {
    $database->getReference("doctor/{$newId}")->set($doctorPayload);
    $database->getReference("users/{$newId}")->set($userPayload);

    // Optional log
    $database->getReference('logs')->push([
        'event'      => 'create_doctor',
        'uid'        => $newId,
        'username'   => $usernameNew,
        'email'      => $loginEmail,
        'sname'      => $resolvedSpecName,
        'specialty'  => $resolvedSpecId,
        'datestamp'  => date('Y-m-d'),
        'timestamp'  => date('H:i:s'),
        'status'     => 'Doctor account created',
        'created_by' => $adminEmail,
    ]);

    redirect_with_msg('doctors.php?action=add&error=4', 'Doctor account created!');
} catch (\Throwable $e) {
    error_log('DB write error: '.$e->getMessage());
    redirect_with_msg('doctors.php?action=add&error=5', 'Error creating account: '.$e->getMessage());
}
