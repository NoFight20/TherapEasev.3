<?php
// ---------------------------------------------
// Create Account (Hardened)
// ---------------------------------------------

// Use same session name as login/signup so flash/candidate flags carry over
session_name('sess_public');
session_start();

// Import Firebase configuration (must define $database and $auth)
include("connection.php");

// ---------- Setup ----------
date_default_timezone_set('Asia/Manila');
$todayDate = date('Y-m-d');

// Background (optional)
$defaultBackground = "img/GentriMed.jpg";
try {
    $backgroundData = $database->getReference("backgrounds/login")->getValue();
} catch (Exception $e) {
    $backgroundData = null;
}
$backgroundURL = ($backgroundData && isset($backgroundData['url'])) ? $backgroundData['url'] : $defaultBackground;

// (Optional) site contact for terms
try {
    $contactNode = $database->getReference('site/contact')->getValue();
    $contactInfo = $contactNode ?? [];
} catch (Exception $e) {
    $contactInfo = [];
}

$error = '';
$lastEmail = ''; // used for success/resend UI

// ---------- Helpers ----------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function calc_age_from_dob($dob) {
    try { $b = new DateTime($dob); $t = new DateTime('today'); return $b->diff($t)->y; } catch (Exception $e) { return null; }
}
function ensure_patient_uid($existing) {
    if (!empty($existing['patient_uid'])) return $existing['patient_uid'];
    return 'P-'.bin2hex(random_bytes(4)).'-'.substr(uniqid('', true), -6);
}

// ---- CSRF helpers ----
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_check($token) {
    return is_string($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function normalize_name(string $fname, string $lname): string {
    $fname = trim(preg_replace('/\s+/', ' ', $fname));
    $lname = trim(preg_replace('/\s+/', ' ', $lname));
    return trim($fname.' '.$lname);
}
function is_emptyish($v): bool {
    return $v === '' || $v === null || !isset($v);
}

/**
 * Auto-find an unlinked patient row to connect:
 *  A) same name + same email, AND no uid + no local password
 *  B) same fname + same lname, empty email, AND no uid + no local password
 * Returns ['key'=>..., 'record'=>...] or null.
 */
function findExistingPatientForLink($database, string $fname, string $lname, string $email): ?array {
    $targetName = normalize_name($fname, $lname);

    // ---------- Pass 1: same email + same name, not yet linked
    try {
        $email = trim(strtolower($email));
        if ($email !== '') {
            $byEmail = $database->getReference('patients')
                ->orderByChild('email')->equalTo($email)
                ->getSnapshot()->getValue();

            if ($byEmail && is_array($byEmail)) {
                foreach ($byEmail as $k => $rec) {
                    if (!is_array($rec)) continue;
                    $recName = isset($rec['name'])
                        ? trim((string)$rec['name'])
                        : normalize_name((string)($rec['fname'] ?? ''), (string)($rec['lname'] ?? ''));
                    $noUid = is_emptyish($rec['uid'] ?? null);
                    $noPw  = empty($rec['has_local_password']);
                    if (strcasecmp($recName, $targetName) === 0 && $noUid && $noPw) {
                        return ['key' => $k, 'record' => $rec];
                    }
                }
            }
        }
    } catch (Exception $e) { /* non-fatal */ }

    // ---------- Pass 2: same fname + same lname, empty email, not yet linked
    // (Covers records that only have fname/lname like your screenshot)
    try {
        $byFname = $database->getReference('patients')
            ->orderByChild('fname')->equalTo($fname)
            ->getSnapshot()->getValue();

        if ($byFname && is_array($byFname)) {
            foreach ($byFname as $k => $rec) {
                if (!is_array($rec)) continue;

                $recF = trim((string)($rec['fname'] ?? ''));
                $recL = trim((string)($rec['lname'] ?? ''));
                $recE = trim((string)($rec['email'] ?? ''));
                $noUid = is_emptyish($rec['uid'] ?? null);
                $noPw  = empty($rec['has_local_password']);

                // require exact lname, empty email, and not linked/passworded
                if (strcasecmp($recF, $fname) === 0 &&
                    strcasecmp($recL, $lname) === 0 &&
                    $recE === '' && $noUid && $noPw) {
                    return ['key' => $k, 'record' => $rec];
                }
            }
        }

        // (Optional tiny safety net: also scan by lname then filter fname)
        $byLname = $database->getReference('patients')
            ->orderByChild('lname')->equalTo($lname)
            ->getSnapshot()->getValue();

        if ($byLname && is_array($byLname)) {
            foreach ($byLname as $k => $rec) {
                if (!is_array($rec)) continue;

                $recF = trim((string)($rec['fname'] ?? ''));
                $recL = trim((string)($rec['lname'] ?? ''));
                $recE = trim((string)($rec['email'] ?? ''));
                $noUid = is_emptyish($rec['uid'] ?? null);
                $noPw  = empty($rec['has_local_password']);

                if (strcasecmp($recF, $fname) === 0 &&
                    strcasecmp($recL, $lname) === 0 &&
                    $recE === '' && $noUid && $noPw) {
                    return ['key' => $k, 'record' => $rec];
                }
            }
        }
    } catch (Exception $e) { /* non-fatal */ }

    return null;
}

// ---------- POST: Resend verification (independent action) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend_verification') {
    if (!csrf_check($_POST['csrf_token'] ?? '')) {
        $error = '<label class="form-label" style="color:red;text-align:center;">Invalid request. Please refresh and try again.</label>';
    } else {
        $resendEmail = strtolower(trim($_POST['resend_email'] ?? ($_SESSION['pending_verify_email'] ?? '')));

        // throttle: 60s cooldown
        $now = time();
        $last = $_SESSION['last_resend_at'] ?? 0;
        if ($now - $last < 60) {
            $error = '<label class="form-label" style="color:#b36b00;text-align:center;">Please wait a moment before requesting another verification email.</label>';
        } elseif ($resendEmail !== '') {
            try {
                $auth->sendEmailVerificationLink($resendEmail);
                $_SESSION['last_resend_at'] = $now;
                $error = '<label class="form-label" style="color:green;text-align:center;">Verification email re-sent to '
                       . h($resendEmail) . '.</label>';
                $lastEmail = $resendEmail;
            } catch (\Kreait\Firebase\Exception\AuthException $e) {
                $error = '<label class="form-label" style="color:red;text-align:center;">Couldn’t resend: '
                       . h($e->getMessage()) . '</label>';
            } catch (Exception $e) {
                $error = '<label class="form-label" style="color:red;text-align:center;">Unexpected resend error: '
                       . h($e->getMessage()) . '</label>';
            }
        } else {
            $error = '<label class="form-label" style="color:red;text-align:center;">Missing email to resend verification.</label>';
        }
    }
}

// ---------- POST: Create / Link account ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    // CSRF first
    if (!csrf_check($_POST['csrf_token'] ?? '')) {
        $error = '<label class="form-label" style="color:red;text-align:center;">Invalid request. Please refresh and try again.</label>';
    } elseif (empty($_SESSION['personal'])) {
        $error = '<label class="form-label" style="color:red;text-align:center;">Session expired. Please go back and fill the form again.</label>';
    } else {
        $personal = $_SESSION['personal'];
        $fname    = trim($personal['fname'] ?? '');
        $lname    = trim($personal['lname'] ?? '');
        $dobFromPage1 = trim($personal['dob'] ?? '');
        $gender   = trim($personal['gender'] ?? '');
        $civil    = trim($personal['civil_status'] ?? '');
        $province = trim($personal['province'] ?? 'Cavite');
        $city     = trim($personal['city'] ?? '');
        $barangay = trim($personal['barangay'] ?? '');

        // Normalize + validate inputs
        $email    = strtolower(trim($_POST['newemail'] ?? ''));
        $tele     = trim($_POST['tele'] ?? '');
        $pass1    = (string)($_POST['newpassword'] ?? '');
        $pass2    = (string)($_POST['cpassword'] ?? '');
        $termsAccepted = isset($_POST['terms']) && $_POST['terms'] === 'accepted';

        $lastEmail = $email;

        // Server-side validations
        if (!$termsAccepted) {
            $error = '<label class="form-label" style="color:red;text-align:center;">You must accept the Terms and Agreement to proceed.</label>';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = '<label class="form-label" style="color:red;text-align:center;">Please enter a valid email address.</label>';
        } elseif ($pass1 !== $pass2) {
            $error = '<label class="form-label" style="color:red;text-align:center;">Password did not match. Try Again!</label>';
        } else {
            $passwordPattern = "/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[\W_]).{8,}$/";
            if (!preg_match($passwordPattern, $pass1)) {
                $error = '<label class="form-label" style="color:red;text-align:center;">Password must be at least 8 characters long, with one uppercase, one lowercase, one number, and one special character.</label>';
            }
            // Optional phone constraint
            if ($error === '' && $tele !== '' && !preg_match('/^09\d{9}$/', $tele)) {
                $error = '<label class="form-label" style="color:red;text-align:center;">Invalid mobile number format. Use 11 digits starting with 09.</label>';
            }
        }

        // Defensive duplicate checks (Auth first, then RTDB)
        if ($error === '') {
            try {
                $auth->getUserByEmail($email);
                // If we get here, user exists in Auth
                $error = '<label class="form-label" style="color:red;text-align:center;">An account with this Email address already exists.</label>';
            } catch (\Kreait\Firebase\Exception\Auth\UserNotFound $e) {
                // ok, no auth user yet
            } catch (\Kreait\Firebase\Exception\AuthException $e) {
                $error = '<label class="form-label" style="color:red;text-align:center;">Auth lookup failed: '.h($e->getMessage()).'</label>';
            } catch (Exception $e) {
                $error = '<label class="form-label" style="color:red;text-align:center;">Auth lookup error: '.h($e->getMessage()).'</label>';
            }
        }

        if ($error === '') {
            try {
                $existingUser = $database->getReference('users')->orderByChild('email')->equalTo($email)->getValue();
                if (!empty($existingUser)) {
                    $error = '<label class="form-label" style="color:red;text-align:center;">An account with this Email address already exists.</label>';
                }
            } catch (Exception $e) {
                // Non-fatal—continue, but warn
                $error = '<label class="form-label" style="color:#b36b00;text-align:center;">Warning: Could not confirm user uniqueness in database. You may retry. ('
                       . h($e->getMessage()) . ')</label>';
            }
        }

        // Create Auth user
        if ($error === '') {
            try {
                $createdUser = $auth->createUser([
                    'email'         => $email,
                    'password'      => $pass1,
                    'displayName'   => trim($fname.' '.$lname),
                    'emailVerified' => false
                ]);
                $authUid = $createdUser->uid;
            } catch (\Kreait\Firebase\Exception\AuthException $e) {
                $error = '<label class="form-label" style="color:red;text-align:center;">Unable to create authentication account: '.h($e->getMessage()).'</label>';
            } catch (Exception $e) {
                $error = '<label class="form-label" style="color:red;text-align:center;">Authentication error: '.h($e->getMessage()).'</label>';
            }
        }

        // Send verification (best-effort)
        if ($error === '' && isset($authUid)) {
            try {
                $auth->sendEmailVerificationLink($email);
                $_SESSION['pending_verify_email'] = $email;
                $_SESSION['last_resend_at'] = time();
            } catch (\Kreait\Firebase\Exception\AuthException $e) {
                $error = '<label class="form-label" style="color:#b36b00;text-align:center;">'
                       . 'Account created, but failed sending verification email: '.h($e->getMessage())
                       . '</label>';
            } catch (Exception $e) {
                $error = '<label class="form-label" style="color:#b36b00;text-align:center;">'
                       . 'Account created, but unexpected error sending verification email: '.h($e->getMessage())
                       . '</label>';
            }
        }

        // Link or create patient record
// Link or create patient record (auto-link to existing name/email stubs)
if (($error === '' || (isset($authUid) && $authUid)) && isset($authUid)) {
    $patientKeyToWrite  = null;
    $patientDataToWrite = [];
    $nowDob             = $dobFromPage1;

    // 1) Try auto-find an existing patient record to link
    $auto = findExistingPatientForLink($database, $fname, $lname, $email);

    if ($auto) {
        // ----- LINK flow -----
        $patientKeyToWrite = $auto['key'];
        $existing          = $auto['record'];

        // If existing has a DOB, keep it; else keep page1 DOB
        $dobInRecord = trim((string)($existing['dob'] ?? ''));
        if ($dobInRecord !== '') {
            $nowDob = $dobInRecord;
        }

        $age = calc_age_from_dob($nowDob);

        $patientDataToWrite = [
            'fname'        => $fname,
            'lname'        => $lname,
            'name'         => trim($fname.' '.$lname),
            'email'        => $email,               // ensure we write email
            'gender'       => $gender,
            'civil_status' => $civil,
            'province'     => $province,
            'city'         => $city,
            'barangay'     => $barangay,
            'tele'         => $tele,
            'type'         => 'p',
            'uid'          => $authUid,             // link to auth user
            'has_local_password' => true            // mark that this patient now set a password (in Auth)
        ];
        if (!empty($nowDob)) $patientDataToWrite['dob'] = $nowDob;
        if (!is_null($age))  $patientDataToWrite['age'] = $age;
        // Preserve or ensure a patient_uid
        $patientDataToWrite['patient_uid'] = ensure_patient_uid($existing);

        try {
            $database->getReference('patients/'.$patientKeyToWrite)->update($patientDataToWrite);
        } catch (Exception $e) {
            $error = '<label class="form-label" style="color:red;text-align:center;">Failed to link existing record: '.h($e->getMessage()).'</label>';
        }

    } else {
        // ----- CREATE flow -----
        $age         = calc_age_from_dob($dobFromPage1);
        $patient_uid = 'P-'.bin2hex(random_bytes(4)).'-'.substr(uniqid('', true), -6);

        $patientKeyToWrite  = $authUid; // key by auth UID
        $patientDataToWrite = [
            'uid'          => $authUid,
            'patient_uid'  => $patient_uid,
            'fname'        => $fname,
            'lname'        => $lname,
            'name'         => trim($fname.' '.$lname),
            'email'        => $email,
            'age'          => $age,
            'gender'       => $gender,
            'dob'          => $dobFromPage1,
            'province'     => $province,
            'city'         => $city,
            'barangay'     => $barangay,
            'tele'         => $tele,
            'civil_status' => $civil,
            'has_local_password' => true,
            'type'         => 'p',
            'date_created' => $todayDate
        ];

        try {
            $database->getReference('patients/'.$patientKeyToWrite)->set($patientDataToWrite);
        } catch (Exception $e) {
            $error = '<label class="form-label" style="color:red;text-align:center;">Unable to create patient record: '.h($e->getMessage()).'</label>';
        }
    }

    // 2) users mapping + logs (if patient write OK or we only have a mail warning)
    if ($error === '' || strpos($error, 'Account created, but') === 0) {
        try {
            $finalPatient = $database->getReference('patients/'.$patientKeyToWrite)->getValue();

            $usersPayload = [
                'uid'         => $authUid,
                'email'       => $email,
                'type'        => 'p',
                'patient_key' => $patientKeyToWrite,
                'patient_uid' => $finalPatient['patient_uid'] ?? null,
            ];
            $database->getReference('users/'.$authUid)->set($usersPayload);

            $database->getReference('logs')->push([
                'email'     => $email,
                'type'      => 'p',
                'status'    => ($auto ? 'Linked Existing Patient' : 'Account Created'),
                'timestamp' => date('H:i:s'),
                'datestamp' => date('Y-m-d'),
                'ip'        => $_SERVER['REMOTE_ADDR'] ?? '',
                'ua'        => $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);

            // Cleanup & PRG
            $_SESSION['signup_success_email'] = $email;
            header("Location: ".$_SERVER['REQUEST_URI']);
            exit;
        } catch (Exception $e) {
            $error = '<label class="form-label" style="color:red;text-align:center;">Unable to finalize account mapping: '.h($e->getMessage()).'</label>';
        }
    }
}

    }
}

// After PRG redirect, show success modal if present
$success = false;
$lastEmail = $lastEmail ?: ($_SESSION['signup_success_email'] ?? '');
if (!empty($_SESSION['signup_success_email'])) {
    $success = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Account</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/animations.css">  
    <link rel="stylesheet" href="css/main.css">  
    <link rel="stylesheet" href="css/signup.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        .container { animation: transitionIn-X 0.5s; }
        body {
            background-image: url('<?php echo h($backgroundURL); ?>');
            background-repeat: no-repeat;
            background-attachment: fixed;
            background-size: cover;
            min-height: 100vh;
        }
        .modal {
            display: none; position: fixed; z-index: 1000; left: 0; top: 0;
            width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5);
        }
        .modal-content {
            background-color: #fff; margin: 5% auto; padding: 20px; border-radius: 8px;
            width: 90%; max-width: 800px; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            text-align: left; overflow-wrap: break-word;
        }
        .close { float:right; font-size: 24px; cursor: pointer; color:#888; }
        .close:hover { color:#000; }
        .ok-btn {
            padding: 10px 20px; font-weight: 600; border: 0; border-radius: 6px;
            background:#00a65a; color:#fff; cursor:pointer;
        }
        .terms-row { display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; justify-content:center; }
        .terms-row a { color:#4CAF50; text-decoration:none; }
        .terms-row a:hover { text-decoration:underline; }

        .toggle-password { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #888; }
        .password-container { position: relative; width: 100%; }
        .password-container input { width: 100%; padding-right: 35px; }
        .form-label { display:block; }
    </style>
</head>
<body>
<center>
<div class="container">
    <form action="" method="POST" autocomplete="off">
        <!-- CSRF -->
        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">

        <table border="0" style="width: 69%; max-width: 820px;">
            <tr>
                <td colspan="2">
                    <div style="display:flex; align-items:center; justify-content:space-between; width:100%;">
                        <button type="button" onclick="history.back()" class="login-btn btn-secondary-soft btn"
                                style="width: 100px; padding: 6px 0; font-size: 14px;">
                            ← Back
                        </button>
                        <div style="flex-grow:1; text-align:center;">
                            <p class="sub-text" style="font-weight:bold; font-size:22px; margin:0 0 0 30px;">Create User Account</p>
                        </div>
                        <div style="width:150px;"></div>
                    </div>
                </td>
            </tr>

            <tr><td class="label-td" colspan="2"><label for="newemail" class="form-label">Email:</label></td></tr>
            <tr><td class="label-td" colspan="2"><input type="email" name="newemail" class="input-text" placeholder="Email Address" required></td></tr>

            <tr><td class="label-td" colspan="2"><label for="tele" class="form-label">Mobile Number:</label></td></tr>
            <tr>
                <td class="label-td" colspan="2">
                    <input type="tel" name="tele" class="input-text" placeholder="ex: 09123456789"
                        pattern="^09\d{9}$" maxlength="11"
                        onkeypress="if (!/[0-9]/.test(event.key)) event.preventDefault();">
                </td>
            </tr>

            <tr><td class="label-td" colspan="2"><label for="newpassword" class="form-label">Create New Password:</label></td></tr>
            <tr>
                <td class="label-td" colspan="2">
                    <div class="password-container">
                        <input type="password" name="newpassword" id="newpassword" class="input-text" placeholder="New Password" required>
                        <i class="fas fa-eye toggle-password" toggle="#newpassword"></i>
                    </div>
                    <ul id="password-requirements" style="font-size:12px;color:#555;margin:5px 0 0 0; padding-left:18px; list-style-type:disc;">
                        <li>At least 8 characters long</li>
                        <li>Contains one uppercase letter (A-Z)</li>
                        <li>Contains one lowercase letter (a-z)</li>
                        <li>Contains one number (0-9)</li>
                        <li>Contains one special character (!@#$%^&*.,)</li>
                    </ul>
                </td>
            </tr>

            <tr><td class="label-td" colspan="2"><label for="cpassword" class="form-label">Confirm Password:</label></td></tr>
            <tr>
                <td class="label-td" colspan="2">
                    <div class="password-container">
                        <input type="password" name="cpassword" id="cpassword" class="input-text" placeholder="Confirm Password" required>
                        <i class="fas fa-eye toggle-password" toggle="#cpassword"></i>
                    </div>
                </td>
            </tr>

            <tr>
                <td class="label-td" colspan="2">
                    <div class="terms-row">
                        <input type="checkbox" id="terms" name="terms" value="accepted" required>
                        <label for="terms" class="form-label">I agree to the 
                            <a href="#" id="openTerms">Terms and Agreement</a>
                        </label>
                    </div>
                </td>
            </tr>

            <tr><td colspan="2"><?php echo $error; ?></td></tr>

            <tr>
                <td><input type="reset" value="Reset" class="login-btn btn-primary-soft btn"></td>
                <td><input type="submit" value="Sign Up" class="login-btn btn-primary btn"></td>
            </tr>
        </table>
    </form>
</div>
</center>

<?php if ($success): ?>
<!-- Success Modal with verify + resend -->
<div id="successModal" class="modal" style="display:block;">
  <div class="modal-content" style="text-align:center;">
    <p style="font-size:18px;color:green;margin-bottom:10px;">✅ Account created!</p>
    <?php if ($lastEmail): ?>
      <p style="margin:0 0 14px;">We sent a verification link to <strong><?php echo h($lastEmail); ?></strong>.</p>
      <p style="font-size:14px; color:#555; margin:0 0 18px;">
        Please verify your email to continue. After verifying, you can log in.
      </p>
      <form method="post" style="margin-bottom:10px;">
        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="resend_email" value="<?php echo h($lastEmail); ?>">
        <button class="ok-btn" type="submit" name="action" value="resend_verification">Resend verification</button>
      </form>
    <?php else: ?>
      <p style="font-size:14px; color:#555; margin:0 0 18px;">
        Please check your email inbox for a verification link.
      </p>
    <?php endif; ?>
    <button class="ok-btn" onclick="redirectToLogin()">Go to Login</button>
  </div>
</div>
<?php endif; ?>

<!-- Terms Modal -->
<div id="termsModal" class="modal">
  <div class="modal-content">
    <span class="close" id="closeModal">&times;</span>
    <h1>Terms and Agreement</h1>
    <p><strong>Last Updated:</strong> <?php echo h(date('F d, Y')); ?></p>

    <h2>Welcome</h2>
    <p>Welcome to <strong>GentriMed Hospital</strong>. This system was created to make your hospital experience safer, faster, and more convenient. 
    By using it, you agree to follow these simple guidelines to help us protect your health information.</p>

    <h2>Your Responsibilities as a Patient</h2>
    <ul>
      <li>Keep your login details private to protect your medical information.</li>
      <li>Inform us immediately if you notice suspicious activity in your account.</li>
      <li>Use the system only for your personal hospital and health-related needs.</li>
    </ul>

    <h2>Your Privacy</h2>
    <p>Your privacy and trust are very important to us. All medical and personal information is protected by Philippine data privacy laws. We will never share your records without your permission, unless required by law or for your treatment.</p>

    <h2>What You Should Not Do</h2>
    <ul>
      <li>Do not try to access another patient’s records.</li>
      <li>Do not edit or falsify your own medical data.</li>
      <li>Do not share hospital information without proper authorization.</li>
    </ul>

    <h2>System Availability</h2>
    <p>We do our best to keep the system running 24/7. However, there may be rare times when it is unavailable. If that happens, hospital staff will assist you directly.</p>

    <h2>Role of Doctors and Staff</h2>
    <p>This system supports your care, but it does not replace your doctor’s medical advice. Always follow your healthcare provider’s professional guidance.</p>

    <h2>Contact Us</h2>
    <p id="contactInfo">
      Phone: <?php echo h($contactInfo['number'] ?? 'N/A'); ?><br>
      Email: <?php echo h($contactInfo['email'] ?? 'N/A'); ?>
    </p>

    <h2>Agreement</h2>
    <p>By using this system, you agree to these terms. Thank you for trusting <strong>GentriMed Hospital</strong> with your healthcare.</p>
  </div>
</div>

<script>
function redirectToLogin(){ window.location.href = "login.php"; }

// After rendering the success modal once, clear the session flag so it won't show again on refresh.
<?php if ($success): ?>
(function(){ try { fetch(location.href, {method:'GET', cache:'no-store'}); } catch(e){} })();
<?php endif; ?>

// Password requirement live check
document.addEventListener('DOMContentLoaded', function () {
  const passwordInput = document.getElementById('newpassword');
  const reqList = document.getElementById('password-requirements');
  if (passwordInput && reqList) {
    const reqItems = Array.from(reqList.querySelectorAll('li'));
    function render() {
      const v = passwordInput.value;
      const checks = [ v.length >= 8, /[A-Z]/.test(v), /[a-z]/.test(v), /\d/.test(v), /[\W_]/.test(v) ];
      let all = true;
      reqItems.forEach((li, i) => { const ok = checks[i]; li.style.display = ok ? 'none' : 'list-item'; if(!ok) all = false; });
      reqList.style.display = all ? 'none' : 'block';
    }
    passwordInput.addEventListener('input', render);
    render();
  }

  const openTerms  = document.getElementById('openTerms');
  const termsModal = document.getElementById('termsModal');
  const closeTerms = document.getElementById('closeModal');
  if (openTerms && termsModal) openTerms.addEventListener('click', e => { e.preventDefault(); termsModal.style.display = 'block'; });
  if (closeTerms) closeTerms.addEventListener('click', () => termsModal.style.display = 'none');
  window.addEventListener('click', e => { if (e.target === termsModal) termsModal.style.display = 'none'; });
});

// Toggle reveal password
document.querySelectorAll(".toggle-password").forEach(function(icon) {
  icon.addEventListener("click", function() {
    const sel = this.getAttribute("toggle");
    let input = document.querySelector(sel);
    if (!input) return;
    if (input.type === "password") {
      input.type = "text";
      this.classList.remove("fa-eye"); this.classList.add("fa-eye-slash");
    } else {
      input.type = "password";
      this.classList.remove("fa-eye-slash"); this.classList.add("fa-eye");
    }
  });
});
</script>

</body>
</html>
<?php
// Clear only the success flag after first paint, keep pending_verify_email for resend
if ($success) { unset($_SESSION['signup_success_email']); }
