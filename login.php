<?php
// ---------- CONFIG & SESSION BOOTSTRAP ----------
declare(strict_types=1);

// ------------ Sessions (must be before any $_SESSION use) ------------
$secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_name('sess_public');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'secure'   => $secureCookie,
    'samesite' => 'Lax'
]);
session_start();

// ------------ Runtime toggles ------------
$DEV_DEBUG = true;               // set false in production
$RATE_LIMIT_MAX_ATTEMPTS = 5;    // attempts before lock
$RATE_LIMIT_WINDOW_SEC   = 15 * 60; // window for counting attempts (15 min)
$LOCKOUT_DURATION_SEC    = 10 * 60; // lockout period after max attempts (10 min)
$RESEND_COOLDOWN_SEC     = 60;      // resend verification cooldown
$PWRESET_COOLDOWN_SEC    = 60;      // password reset email cooldown

// ------------ Flash buckets ------------
$error = '';
$info  = '';
$flash = '';

// From password reset / other flows
if (!empty($_SESSION['flash_info'])) {
    $info = $_SESSION['flash_info'];
    unset($_SESSION['flash_info']);
}
if (!empty($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// From login hints / redirects
if (!empty($_SESSION['login_hint'])) {
    $flash = $_SESSION['login_hint'];
    unset($_SESSION['login_hint']);
} elseif (isset($_GET['msg']) && $_GET['msg'] === 'existing') {
    $flash = "We found an existing hospital record already linked with an email. Please log in or use Forgot Password.";
}

// ------------ Error visibility ------------
if ($DEV_DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');
if (!ini_get('error_log')) {
    ini_set('error_log', __DIR__ . '/php_errors.log');
}

// ------------ Security headers ------------
$nonce = base64_encode(random_bytes(16)); // for inline scripts
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: no-referrer-when-downgrade");
header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
header("Cache-Control: no-store, max-age=0");
// CSP allows inline scripts with a nonce; keep minimal to fit the current page
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline' https:; script-src 'self' 'nonce-{$nonce}' https:; font-src 'self' https: data:; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; connect-src 'self' https:;");

// ------------ Sessions helper ------------
function sessionNameForType($t) {
    switch ($t) {
        case 'p':  return 'sess_patient';
        case 'a':  return 'sess_admin';
        case 'd':  return 'sess_doctor';
        case 'it': return 'sess_it';
        default:   return 'sess_public';
    }
}

// ------------ CSRF ------------
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_check(?string $token): bool {
    return is_string($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

// ------------ Utilities ------------
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function norm_email(string $e): string { return strtolower(trim($e)); }

function getUserIP(): string {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return (string)$_SERVER['HTTP_CLIENT_IP'];
    } else {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }
}
$userIP = getUserIP();
error_log("Detected IP: " . $userIP);

function restrictedRole(string $role): bool {
    return in_array($role, ['a','d','it'], true);
}

// ------------ Firebase connection ------------
include("connection.php"); 

// ------------ Login-only audit ------------
const LOGIN_AUDIT_EVENTS = [
  'login_success','login_failed',
  'redirect','login_blocked_unverified_patient','network_denied',
  'rate_lock','login_throttled','csrf_failed'
];

function audit(string $evt, array $meta = [], ?string $email = null, ?string $role = null): void {
    if (!in_array($evt, LOGIN_AUDIT_EVENTS, true)) {
        return; // ignore non-login events (page_view, bg_loaded, pwreset, etc.)
    }

    global $database;
    if (!isset($database) || !$database) return;

    // Map event to status text shown in RTDB
    $status = $evt === 'login_success' ? 'Login Success' : 'Login Failed';

    $payload = [
        'datestamp' => date('Y-m-d'),
        'timestamp' => date('H:i:s'),
        'email'     => $email ?? ($_SESSION['user'] ?? null),
        'status'    => $status,
        'type'      => $role  ?? ($_SESSION['usertype'] ?? 'unknown'),
    ];

    try { $database->getReference('logs')->push($payload); } catch (Throwable $e) { /* no-op */ }
}

// Optional: keep old helper as an alias so existing calls still work
function writeSimpleLoginLog(string $email, string $role, string $status): void {
    audit($status === 'Login Success' ? 'login_success' : 'login_failed', [], $email, $role);
}

// ------------ Background (safe) ------------
$defaultBackground = "img/GentriMed.jpg";
$backgroundURL = $defaultBackground;
try {
    if (isset($database)) {
        $backgroundData = $database->getReference("backgrounds/login")->getValue();
        if (is_array($backgroundData) && !empty($backgroundData['url'])) {
            $backgroundURL = (string)$backgroundData['url'];
            audit('bg_loaded', ['url' => $backgroundURL]);
        } else {
            audit('bg_default', ['reason' => 'missing_or_empty']);
        }
    } else {
        audit('bg_default', ['reason' => 'no_db']);
    }
} catch (Throwable $e) {
    audit('bg_default', ['reason' => 'exception']);
}

// ------------ IP allow list ------------
$allowedIPs = [];
$ipRestrictionEnabled = false; // default: OFF unless enabled in config.php

try {
    $config = @include __DIR__ . '/config.php';

    if (is_array($config)) {
        if (isset($config['allowed_ips']) && is_array($config['allowed_ips'])) {
            $allowedIPs = $config['allowed_ips'];
        }

        // If 'ip_restriction_enabled' exists and is truthy, enable restriction
        if (!empty($config['ip_restriction_enabled'])) {
            $ipRestrictionEnabled = true;
        }
    }
} catch (Throwable $e) {
    // If config fails, keep restriction OFF so you don't get locked out accidentally
}

// ------------ State ------------
date_default_timezone_set('Asia/Manila');
$_SESSION["date"] = date('Y-m-d');

// page view audit (ignored by LOGIN_AUDIT_EVENTS, but harmless)
audit('page_view', ['page' => 'login']);

// ------------ Rate limit helpers ------------
function rl_key(string $ip, string $email): string { return 'rl:' . sha1($ip . '|' . $email); }
function rl_touch(string $key): void {
    $_SESSION[$key]['attempts'][] = time();
    audit('rate_bucket_touch', ['rl_key' => $key]);
}
function rl_is_locked(string $key, int $max, int $window, int $lockout): bool {
    $now = time();
    $bucket = $_SESSION[$key]['attempts'] ?? [];
    // trash old entries (outside window + lockout span)
    $bucket = array_values(array_filter($bucket, fn($t) => ($now - $t) <= max($window, $lockout)));
    $_SESSION[$key]['attempts'] = $bucket;

    // if we previously locked, respect lockout duration
    $lockedAt = $_SESSION[$key]['locked_at'] ?? 0;
    if ($lockedAt && ($now - $lockedAt) < $lockout) {
        return true;
    }
    if ($lockedAt && ($now - $lockedAt) >= $lockout) {
        $_SESSION[$key]['locked_at'] = 0; // unlock
    }

    // count attempts within window
    $recent = array_values(array_filter($bucket, fn($t) => ($now - $t) <= $window));
    if (count($recent) >= $max) {
        $_SESSION[$key]['locked_at'] = $now;
        audit('rate_lock', ['rl_key' => $key, 'lockout_sec' => $lockout, 'attempts' => count($recent)]);
        return true;
    }
    return false;
}

function findUserByUidAcrossRoles($database, string $uid, string $emailFromAuth = ''): ?array {
    if (!$database || $uid === '') return null;
    $found = [];

    // staff by uid
    foreach ([['a','admin'], ['d','doctor'], ['it','it']] as [$r,$tbl]) {
        try {
            $row = $database->getReference($tbl.'/'.$uid)->getValue();
            if (is_array($row) && !empty($row['type'])) {
                $found[] = ['role'=>$r,'uid'=>$uid,'row'=>$row,'table'=>$tbl];
            }
        } catch (Throwable $e) {}
    }

    // patient by uid, then email fallback
    try {
        $p = $database->getReference('patients')
            ->orderByChild('patient_uid')->equalTo($uid)->getValue();

        if ((!$p || !is_array($p)) && $emailFromAuth !== '') {
            $p = $database->getReference('patients')
                ->orderByChild('email')->equalTo(strtolower($emailFromAuth))->getValue();
        }

        if ($p && is_array($p)) {
            $pid = array_key_first($p);
            if ($pid !== null) {
                $found[] = ['role'=>'p','uid'=>$uid,'row'=>$p[$pid],'table'=>'patients','extra'=>['pid'=>$pid]];
            }
        }
    } catch (Throwable $e) {}

    if (count($found) === 1) return $found[0];
    if (count($found) > 1)   return ['role'=>'conflict','row'=>$found];
    return null;
}


function findStaffEmailByIdentifier($database, string $identifier): ?array {
    if (!$database || $identifier === '') return null;

    $identifier = trim($identifier);
    $staffTables = [
        ['a',  'admin'],
        ['d',  'doctor'],
        ['it', 'it'],
    ];

    foreach ($staffTables as [$role, $tbl]) {
        try {
            // First, try 'username'
            $snap = $database->getReference($tbl)
                ->orderByChild('username')
                ->equalTo($identifier)
                ->getSnapshot();

            if ($snap->exists()) {
                $data = $snap->getValue();
                $row  = is_array($data) ? reset($data) : null;

                if (is_array($row) && !empty($row['email'])) {
                    return [
                        'role'  => $role,
                        'email' => strtolower(trim((string)$row['email'])),
                        'table' => $tbl,
                    ];
                }
            }

            // Optional fallback: if you use 'uname' or a different field:
            $snap2 = $database->getReference($tbl)
                ->orderByChild('uname')
                ->equalTo($identifier)
                ->getSnapshot();

            if ($snap2->exists()) {
                $data2 = $snap2->getValue();
                $row2  = is_array($data2) ? reset($data2) : null;

                if (is_array($row2) && !empty($row2['email'])) {
                    return [
                        'role'  => $role,
                        'email' => strtolower(trim((string)$row2['email'])),
                        'table' => $tbl,
                    ];
                }
            }
        } catch (Throwable $e) {

        }
    }

    return null;
}

// ---------- ACTION: RESEND EMAIL VERIFICATION ----------
if (($_POST['action'] ?? '') === 'resend_verification') {
    audit('resend_verification_hit');
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $error = '<div style="color:#ff3e3e;text-align:center;">Invalid request (CSRF). Please refresh and try again.</div>';
        audit('csrf_failed', ['form' => 'resend_verification']);
    } else {
        $resendEmail = norm_email((string)($_POST['resend_email'] ?? ''));
        if ($resendEmail === '' || !filter_var($resendEmail, FILTER_VALIDATE_EMAIL)) {
            $error = '<div style="color:#ff3e3e;text-align:center;">Missing or invalid email for verification.</div>';
            audit('resend_verification_fail', ['reason'=>'invalid_email']);
        } else {
            $now = time();
            $last = $_SESSION['last_resend_at'] ?? 0;
            if ($now - $last < $RESEND_COOLDOWN_SEC) {
                $error = '<div style="color:#b36b00;text-align:center;">Please wait a moment before requesting another verification email.</div>';
                audit('resend_verification_blocked', ['cooldown_sec' => $RESEND_COOLDOWN_SEC, 'since' => ($now - $last)], $resendEmail);
            } else {
                try {
                    $auth->sendEmailVerificationLink($resendEmail);
                    $_SESSION['last_resend_at'] = $now;
                    $info = '<div style="color:#2e7d32;text-align:center;">Verification email re-sent to <strong>'
                          . h($resendEmail) . '</strong>.</div>';
                    audit('resend_verification_sent', [], $resendEmail);
                } catch (\Kreait\Firebase\Exception\AuthException $e) {
                    $error = '<div style="color:#ff3e3e;text-align:center;">Could not resend verification: '
                           . h($e->getMessage()) . '</div>';
                    audit('resend_verification_error', ['message' => $e->getMessage()], $resendEmail);
                } catch (Throwable $e) {
                    $error = '<div style="color:#ff3e3e;text-align:center;">Unexpected error while resending verification.</div>';
                    audit('resend_verification_error', ['message' => 'Throwable'], $resendEmail);
                }
            }
        }
    }
}

// Best-effort role resolver by email (for password reset flows)
function resolveRoleByEmail($database, string $email): string {
    if (!$database || $email === '') return 'unknown';
    try {
        foreach ([['a','admin'], ['d','doctor'], ['it','it']] as [$r,$tbl]) {
            $snap = $database->getReference($tbl)
                ->orderByChild('email')->equalTo($email)->getSnapshot();
            if ($snap->exists()) return $r;
        }
        // Patients
        $pSnap = $database->getReference('patients')
            ->orderByChild('email')->equalTo($email)->getSnapshot();
        if ($pSnap->exists()) return 'p';
    } catch (Throwable $e) { /* ignore */ }
    return 'unknown';
}

// ---------- ACTION: SEND PASSWORD RESET EMAIL ----------
if (($_POST['fp_action'] ?? '') === 'send_reset_link') {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash_error'] = '<div style="color:#ff3e3e;text-align:center;">Invalid request (CSRF). Please refresh and try again.</div>';
        audit('csrf_failed', ['form' => 'pwreset']);
        header('Location: ' . $_SERVER['PHP_SELF']); exit();
    }

    $resetEmail = norm_email((string)($_POST['fp_email'] ?? ''));
    if ($resetEmail === '' || !filter_var($resetEmail, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['flash_error'] = '<div style="color:#ff3e3e;text-align:center;">Please enter a valid email.</div>';
        audit('pwreset_fail', ['reason'=>'invalid_email']);
        header('Location: ' . $_SERVER['PHP_SELF']); exit();
    }

    $now  = time();
    $last = $_SESSION['last_pwreset_at'] ?? 0;
    if ($now - $last < $PWRESET_COOLDOWN_SEC) {
        $_SESSION['flash_error'] =
            '<div style="color:#b36b00;text-align:center;">Please wait a moment before requesting another reset email.</div>';
        audit('pwreset_blocked', ['cooldown_sec'=>$PWRESET_COOLDOWN_SEC,'since'=>($now-$last)], $resetEmail);
        header('Location: ' . $_SERVER['PHP_SELF']); exit();
    }

    try {
        // Send reset link (Kreait)
        $auth->sendPasswordResetLink($resetEmail);
        $_SESSION['last_pwreset_at'] = $now;

        // Show a generic, non-enumerating success once
        $_SESSION['flash_info'] =
            '<div style="color:#2e7d32;text-align:center;">If an account exists for <strong>'
            . h($resetEmail) . '</strong>, a password reset email has been sent.</div>';

        audit('pwreset_sent', [], $resetEmail);
        $roleForLog = resolveRoleByEmail($database ?? null, $resetEmail);
        writeSimpleLoginLog($resetEmail, $roleForLog, 'Password Reset Sent');

    } catch (\Kreait\Firebase\Exception\AuthException $e) {
        error_log('PWRESET AuthException: '.$e->getMessage());
        $_SESSION['flash_info'] =
            '<div style="color:#2e7d32;text-align:center;">If an account exists for <strong>'
            . h($resetEmail) . '</strong>, a password reset email has been sent.</div>';
        audit('pwreset_error', ['message'=>$e->getMessage()], $resetEmail);
        $roleForLog = resolveRoleByEmail($database ?? null, $resetEmail);
        writeSimpleLoginLog($resetEmail, $roleForLog, 'Password Reset Requested');
    } catch (\Throwable $e) {
        error_log('PWRESET Throwable: '.$e->getMessage());
        $_SESSION['flash_info'] =
            '<div style="color:#2e7d32;text-align:center;">If an account exists for <strong>'
            . h($resetEmail) . '</strong>, a password reset email has been sent.</div>';
        audit('pwreset_error', ['message'=>'Throwable: '.$e->getMessage()], $resetEmail);
        $roleForLog = resolveRoleByEmail($database ?? null, $resetEmail);
        writeSimpleLoginLog($resetEmail, $roleForLog, 'Password Reset Requested');
    }

    // PRG: clear POST and show message once
    header('Location: ' . $_SERVER['PHP_SELF']); exit();
}

// ---------- AUTH HANDLER (Firebase Auth for all roles) ----------
if ($_POST && empty($_POST['fp_action']) && ($_POST['action'] ?? '') !== 'resend_verification') {
  // single-iteration block so we can 'break;' on any handled error and still render HTML
  do {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $error = '<div style="color:#ff3e3e;text-align:center;">Invalid request (CSRF). Please refresh and try again.</div>';
        audit('csrf_failed', ['form' => 'login']);
        break;
    }

    // User can type EMAIL (all roles) or USERNAME (admin / doctor / IT only)
    $identifierRaw = trim((string)($_POST['useremail'] ?? ''));
    $password      = (string)($_POST['userpassword'] ?? '');
    $timestamp     = date('H:i:s');
    $datestamp     = date('Y-m-d');

    if ($identifierRaw === '' || $password === '') {
        $error = '<div style="color:#ff3e3e;text-align:center;">Please enter your email/username and password.</div>';
        audit('login_failed', ['reason'=>'missing_identifier_or_password']);
        break;
    }

    $email = '';
    $identifierForRate = $identifierRaw;

    if (filter_var($identifierRaw, FILTER_VALIDATE_EMAIL)) {
        // Patient or staff logging in with their email
        $email = norm_email($identifierRaw);
    } else {
        // Treat as USERNAME → only staff (admin/doctor/IT) are allowed to use username
        $lookup = findStaffEmailByIdentifier($database ?? null, $identifierRaw);

        if ($lookup === null) {
            $error = '<div style="color:#ff3e3e;text-align:center;">No account found for that email or username.</div>';
            audit('login_failed', [
                'reason'     => 'identifier_not_found',
                'identifier' => $identifierRaw
            ]);
            // Mark this as a failed attempt for rate-limit based on the identifier
            $keyTmp = rl_key($userIP, $identifierRaw);
            rl_touch($keyTmp);
            break;
        }

        $email = norm_email($lookup['email']);
    }

    // Rate limit per IP + (resolved) email/identifier
    $key = rl_key($userIP, $email !== '' ? $email : $identifierForRate);
    if (rl_is_locked($key, $RATE_LIMIT_MAX_ATTEMPTS, $RATE_LIMIT_WINDOW_SEC, $LOCKOUT_DURATION_SEC)) {
        $error = '<div style="color:#ff3e3e;text-align:center;">Too many attempts. Please try again later.</div>';
        audit('login_throttled', ['rl_key' => $key], $email ?: $identifierForRate);
        break;
    }

    try {
        // Sign in with Firebase Auth (email/password)
        $signInResult  = $auth->signInWithEmailAndPassword($email, $password);
        $idToken       = $signInResult->idToken();
        $refreshToken  = $signInResult->refreshToken();
        $decoded       = $auth->verifyIdToken($idToken);
        $claims        = $decoded->claims()->all();
        $uid           = (string)($claims['sub'] ?? '');
        $emailVerified = (bool)($claims['email_verified'] ?? false);

        // Resolve role by UID (with email fallback already added in your function)
        $match = findUserByUidAcrossRoles($database ?? null, $uid, $claims['email'] ?? $email);


        if ($match === null) {
            // No role found in RTDB for this Auth user
            rl_touch($key);
            $error = '<div style="color:#ff3e3e;text-align:center;">Your account is not set up yet. Please contact hospital IT.</div>';
            audit('login_failed', ['reason'=>'no_role_for_uid','uid'=>$uid], $email);
            break;
        }

        if (($match['role'] ?? '') === 'conflict') {
            rl_touch($key);
            $error = '<div style="color:#ff3e3e;text-align:center;">This account maps to multiple roles. Please contact hospital IT.</div>';
            audit('login_failed', ['reason'=>'multi_roles_uid','uid'=>$uid], $email);
            break;
        }

        $role   = $match['role'];
        $userRow = $match['row'] ?? [];

        // 🔒 Email verification REQUIRED for patients only
        if ($role === 'p' && !$emailVerified) {
            $error = '<div style="text-align:center;">'
                   . '<div style="color:#ff3e3e;font-weight:700;margin-bottom:8px;">Please verify your email before signing in.</div>'
                   . '<div style="color:#444;margin-bottom:14px;">We sent a verification link to '
                   . '<strong>' . h($email) . '</strong>. Didn’t get it?</div>'
                   . '<form method="post" style="display:inline-block;">'
                   . '<input type="hidden" name="csrf_token" value="'.h(csrf_token()).'">'
                   . '<input type="hidden" name="resend_email" value="' . h($email) . '"/>'
                   . '<button class="btn" type="submit" name="action" value="resend_verification" style="width:auto;padding:10px 16px;">Resend verification</button>'
                   . '</form>'
                   . '</div>';
            audit('login_blocked_unverified_patient', ['uid'=>$uid], $email, $role);
            break;
        }

        // Enforce IP allow-list for restricted roles (admin/doctor/it)
        if ($ipRestrictionEnabled && restrictedRole($role) && !in_array($userIP, $allowedIPs, true)) {
            rl_touch($key);
            $error = '<label class="form-label" style="color:rgb(255,62,62);text-align:center;">Access denied: You must be connected to the hospital network.</label>';
            audit('network_denied', ['role'=>$role,'uid'=>$uid,'ip'=>$userIP], $email, $role);
            break;
        }

        // SUCCESS: reset rate bucket, rotate session, bind UA & IP
        unset($_SESSION[$key]);
        session_regenerate_id(true);

        // Switch to role-specific session cookie settings
        session_write_close();
        session_name(sessionNameForType($role));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'secure'   => $secureCookie,
            'samesite' => 'Lax'
        ]);
        session_start();

        // Store essentials
        $_SESSION['uid']           = $uid;
        $_SESSION['user']          = $email;
        $_SESSION['usertype']      = $role;
        $_SESSION['ua']            = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $_SESSION['ip']            = $userIP;
        $_SESSION['id_token']      = $idToken;
        $_SESSION['refresh_token'] = $refreshToken;

        audit('login_success', ['role'=>$role, 'uid'=>$uid, 'table'=>$match['table'] ?? null], $email, $role);
        writeSimpleLoginLog($email, $role, 'Login Success');

        // Admin sub-roles -> queuing redirect
        if ($role === 'a') {
            $queueSpecialties = ['hmo','cashier','blood bank','blood-bank','bloodbank','philhealth'];
            $adminSname = $userRow['sname'] ?? null;
            $goQueue = false;
            if (is_array($adminSname)) {
                foreach ($adminSname as $sn) {
                    if (in_array(strtolower(trim((string)$sn)), $queueSpecialties, true)) { $goQueue = true; break; }
                }
            } else {
                $goQueue = in_array(strtolower(trim((string)$adminSname)), $queueSpecialties, true);
            }
            if ($goQueue) {
                audit('redirect', ['to' => 'admin/queing.php', 'reason' => 'queue_specialty'], $email, $role);
                header('Location: admin/queing.php'); exit();
            }
        }

        // Final redirects (absolute paths recommended)
        $redirectPaths = [
            'p'  => 'patient/index.php',
            'a'  => 'admin/index.php',
            'd'  => 'doctor/index.php',
            'it' => 'it/dashboard.php'
        ];
        $to = $redirectPaths[$role] ?? '/index.php';
        audit('redirect', ['to' => $to], $email, $role);
        header('Location: ' . $to);
        exit();

    } catch (\Kreait\Firebase\Exception\Auth\FailedToSignIn $e) {
        rl_touch($key);
        $errors = method_exists($e, 'errors') ? $e->errors() : [];
        writeSimpleLoginLog($email, 'unknown', 'Login Failed');
        $reason = $errors['error']['message'] ?? 'UNKNOWN';
        $error  = '<label class="form-label" style="color:rgb(255,62,62);text-align:center;">Invalid email or password.</label>';
        audit('login_failed', ['reason'=>'failed_to_sign_in','firebase_reason'=>$reason,'msg'=>$e->getMessage()], $email);
        // fall through to render HTML
    } catch (\Kreait\Firebase\Exception\AuthException $e) {
        rl_touch($key);
        $error = '<label class="form-label" style="color:rgb(255,62,62);text-align:center;">Unable to sign in right now. Please try again.</label>';
        writeSimpleLoginLog($email, 'unknown', 'Login Failed');
        audit('login_failed', ['reason'=>'auth_exception','msg'=>$e->getMessage()], $email);
        // fall through to render HTML
    } catch (Throwable $e) {
        rl_touch($key);
        $error = '<label class="form-label" style="color:rgb(255,62,62);text-align:center;">Invalid email or password</label>';
        writeSimpleLoginLog($email, 'unknown', 'Login Failed');
        audit('login_failed', ['reason'=>'throwable'], $email);
        // fall through to render HTML
    }
  } while (false);
}

// ---------- CONTACT INFO (non-fatal) ----------
try {
    $contactNode = (isset($database) ? $database->getReference('contact')->getValue() : null);
    $contactInfo = $contactNode ?? ['phone' => 'No contact available'];
} catch (Throwable $e) {
    $contactInfo = ['error' => 'Unable to load contact information'];
}

// ---------- CONTACT (display fallback) ----------
$contactPhone = h((string)($contactInfo['phone'] ?? ($contactInfo['number'] ?? '')));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/animations.css">
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="css/login.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <title>Sign in</title>

    <style>
/* --------- Minimal refreshed styles for SSO ---------- */
body {
    overflow: hidden;
    background-image: url('<?php echo h($backgroundURL); ?>');
    background-repeat: no-repeat;
    background-attachment: fixed;
    background-size: cover;
    min-height: 100vh;
    margin: 0;
    font-family: Arial, sans-serif;
}

.container {
    max-width: 900px;
    width: 90%;
    margin: 200px auto;
}

.header-wrapper {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 70px;
    margin-bottom: 30px;
}

.back-btn {
    position: absolute;
    left: 0;
    text-decoration: none;
    background: #4caf50;
    color: #fff;
    padding: 7px 18px;
    border-radius: 18px;
    font-size: 16px;
    font-weight: 500;
    box-shadow: 0 2px 6px rgba(0,0,0,.08);
    display: inline-flex;
    align-items: center;
    transition: background .15s;
}

.back-btn:hover { background: #357a38; }
.back-btn i { margin-right: 8px; }

.header-text {
    font-size: 3rem;
    font-weight: 700;
    color: #333;
    text-align: center;
}

/* Labels */
.login-card .form-label,
#fpModal .form-label {
    font-weight: 700;
    font-size: 15px;
    line-height: 1.2;
}

form table {
    width: 100%;
    max-width: 520px;
    margin: 0 auto;
    border-collapse: collapse;
}

form td.label-td { padding: 8px 0; }

form input.input-text,
form input[type="email"],
form input[type="password"],
form input[type="date"] {
    width: 100%;
    padding: 12px;
    box-sizing: border-box;
    border-radius: 8px;
    border: 1px solid #ccc;
    font-size: 15px;
}

form input[type="submit"].login-btn,
.btn {
    width: 220px;
    padding: 12px;
    background: #4caf50;
    border: none;
    color: #fff;
    font-size: 16px;
    font-weight: 600;
    border-radius: 8px;
    cursor: pointer;
    transition: background-color .2s;
    display: block;
    margin: 16px auto 0;
}

form input[type="submit"].login-btn:hover,
.btn:hover { background: #357a38; }

.sub-text {
    font-size: 1.1rem;
    margin-bottom: 12px;
    text-align: center;
}

.toggle-password {
    position: absolute;
    right: 15px;
    top: 50%;
    transform: translateY(-50%);
    cursor: pointer;
    font-size: 18px;
    color: #333;
    user-select: none;
}

/* Loading screen */
.loading-screen {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(255,255,255,.8);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    flex-direction: column;
}

.spinner {
    border: 8px solid #f3f3f3;
    border-top: 8px solid #4CAF50;
    border-radius: 50%;
    width: 50px;
    height: 50px;
    margin-left: 40px;
    animation: spin 1s linear infinite;
}

.loading-text {
    margin-top: 15px;
    font-size: 18px;
    color: #4CAF50;
    font-weight: bold;
}

@keyframes spin { 0% { transform: rotate(0); } 100% { transform: rotate(360deg); } }

@media (max-width: 768px) { .header-text { font-size: 2.2rem; } }

/* =======================================================================
   Forgot Password Modal (Send reset email)
   ==================================================================== */
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 10000;
}

.modal-card {
    background: #fff;
    width: 95%;
    max-width: 520px;
    border-radius: 12px;
    box-shadow: 0 12px 28px rgba(0,0,0,.18);
    padding: 20px;
}

.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
}

.modal-title {
    font-size: 18px;
    font-weight: 700;
    color: #333;
}

.modal-close {
    border: none;
    background: transparent;
    font-size: 20px;
    cursor: pointer;
}

.modal-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 16px;
}

.btn-light {
    background: #f1f3f4;
    color: #333;
    width: auto;
}

.forgot-link { margin-top: 8px; text-align: center; }

.forgot-link a {
    color: #2f7d32;
    text-decoration: none;
    font-weight: 600;
}

.forgot-link a:hover { text-decoration: underline; }

.flash {
  background: #e8f1ff;
  color: #2f7d32;
  border: 1px solid #bcd3ff;
  padding: 10px 12px;
  border-radius: 6px;
  margin: 12px auto;
  font-size: 14px;
  max-width: 520px;
}
.flash .small { display:block; font-size:12px; color:#3c6bd1; margin-top:4px; }
    </style>
</head>
<body>
<div class="loading-screen" id="loadingScreen">
    <div class="loading-content">
        <div class="spinner"></div>
        <p class="loading-text">Signing you in…</p>
    </div>
</div>

<center>
  <div class="container">
    <div class="header-wrapper">
      <a href="index.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Back</a>
      <span class="header-text">Sign in</span>
    </div>

    <div class="login-card">
      <form action="" method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
        <?php if (!empty($flash)) : ?>
          <div class="flash">
            <?php echo h($flash); ?>
            <span class="small">Tip: If you forgot your password, click “Forgot password?”.</span>
          </div>
        <?php endif; ?>

        <?php if (!empty($error)) : ?>
          <div style="color: red; font-weight: bold; text-align: center; margin-bottom: 10px;"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if (!empty($info)) : ?>
          <div style="color: #2e7d32; font-weight: bold; text-align: center; margin-bottom: 10px;"><?php echo $info; ?></div>
        <?php endif; ?>

        <table>
         <tr><td class="label-td">
            <label class="form-label" for="useremail">Email or username:</label>
            </td></tr>
            <tr>
            <td class="label-td">
                <input id="useremail" type="text" name="useremail" class="input-text" placeholder="Email address or username" required autocomplete="username">
            </td>
            </tr>
          <tr>
          <tr><td class="label-td"><label class="form-label">Password:</label></td></tr>
          <tr>
            <td class="label-td" style="position: relative;">
              <input id="userpassword" type="password" name="userpassword" class="input-text" placeholder="Password" required autocomplete="current-password">
              <span class="toggle-password"><i class="fa-solid fa-eye" aria-hidden="true"></i></span>

              <small id="capsWarn" style="display:none;color:#b36b00;position:absolute;right:44px;top:50%;transform:translateY(-50%);">Caps Lock is ON</small>
            </td>
          </tr>

          <tr>
            <td class="label-td">
              <div class="forgot-link">
                <label class="form-label">
                  No Account Yet? <a href="signup.php" style="color: blue;">Register Here</a><br/>
                  <a href="#" id="forgotLink">Forgot password?</a>
                </label>
              </div>
            </td>
          </tr>

          <tr><td><input type="submit" value="Sign in" class="login-btn"></td></tr>
        </table>
      </form>
    </div>
  </div>
</center>

<!-- Forgot Password Modal (Send reset email) -->
<div class="modal-overlay" id="fpModal">
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="fpTitle">
    <div class="modal-header">
      <div class="modal-title" id="fpTitle"><i class="fa-solid fa-key" style="margin-right:8px;"></i>Password reset</div>
      <button type="button" class="modal-close" id="fpClose" aria-label="Close">&times;</button>
    </div>
    <p style="margin:6px 0 14px;color:#555;">Enter your registered email. We’ll send a password reset link.</p>
    <form action="" method="POST" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
      <input type="hidden" name="fp_action" value="send_reset_link">
      <table>
        <label class="form-label" for="fp_email">Email</label>
        <tr><td class="label-td"><input id="fp_email" type="email" name="fp_email" class="input-text" placeholder="your@email.com" required></td></tr>
      </table>
      <div class="modal-actions">
        <button type="button" class="btn" id="fpCancel">Cancel</button>
        <button type="submit" class="btn">Send reset link</button>
      </div>
    </form>
  </div>
</div>

<script nonce="<?php echo $nonce; ?>">
function togglePasswordVisibility(el) {
  const input = el.parentElement.querySelector('input[type="password"], input[type="text"]');
  const icon  = el.querySelector('i');
  if (!input || !icon) return;
  if (input.type === 'password') {
    input.type = 'text';
    icon.classList.replace('fa-eye', 'fa-eye-slash');
    el.setAttribute('aria-label', 'Hide password');
  } else {
    input.type = 'password';
    icon.classList.replace('fa-eye-slash', 'fa-eye');
    el.setAttribute('aria-label', 'Show password');
  }
}

function toggleSpecific(id, el){
  const input = document.getElementById(id);
  const icon  = el.querySelector('i');
  if (!input || !icon) return;
  if (input.type === 'password') {
    input.type = 'text';
    icon.classList.replace('fa-eye', 'fa-eye-slash');
  } else {
    input.type = 'password';
    icon.classList.replace('fa-eye-slash', 'fa-eye');
  }
}

// Caps Lock warning
document.querySelectorAll('input[type="password"]').forEach(p=>{
  p.addEventListener('keydown',e=>{
    const on = e.getModifierState && e.getModifierState('CapsLock');
    const w = document.getElementById('capsWarn'); if (w) w.style.display = on?'inline':'none';
  });
});

// Loading spinner for the main login form (not for modal forms)
document.querySelectorAll('form').forEach(form => {
  form.addEventListener('submit', () => {
    if (!form.querySelector('input[name="fp_action"]')) {
      const ls = document.getElementById('loadingScreen');
      if (ls) ls.style.display = 'flex';
    }
  });
});

// Bind password toggles without inline handlers (CSP-safe)
document.querySelectorAll('.toggle-password').forEach(function(el){
  el.addEventListener('click', function(){
    togglePasswordVisibility(this);
  });
});

// Open / close modal (CSP-safe bindings)
document.getElementById('forgotLink')?.addEventListener('click', function (e) {
  e.preventDefault();
  openResetModal();
});
document.getElementById('fpClose')?.addEventListener('click', closeResetModal);
document.getElementById('fpCancel')?.addEventListener('click', closeResetModal);

// Optional: close when clicking outside the card or pressing Esc
document.getElementById('fpModal')?.addEventListener('click', function(e){
  if (e.target === this) closeResetModal();
});
document.addEventListener('keydown', function(e){
  if (e.key === 'Escape') closeResetModal();
});

// Forgot Password modal controls
function openResetModal(){ const el = document.getElementById('fpModal'); if (el) el.style.display = 'flex'; }
function closeResetModal(){ const el = document.getElementById('fpModal'); if (el) el.style.display = 'none'; }
</script>
</body>
</html>
