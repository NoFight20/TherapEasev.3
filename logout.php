<?php
/**
 * Unified logout for multi-session (per-role) setup.
 * - ?role=p|a|d|it  → logs out just that role's session
 * - ?all=1          → logs out ALL role sessions
 * - no params       → auto-detects if exactly one role-session cookie exists and logs that out
 *                     (else falls back to public/neutral)
 */

// Map role → session name
$ROLE_TO_SESS = [
  'p'  => 'sess_patient',
  'a'  => 'sess_admin',
  'd'  => 'sess_doctor',
  'it' => 'sess_it',
];

// Helper: destroy a specific session by its cookie name
function destroy_session_by_name(string $sessName, string $path = '/'): void {
    // Start the session with that name to access & clear server-side data
    session_write_close();
    session_name($sessName);
    @session_start();

    // Clear data
    $_SESSION = [];

    // Expire cookie (common path '/'; adjust if you used role-specific paths)
    if (isset($_COOKIE[$sessName])) {
        // Best-effort: clear with common paths
        setcookie($sessName, '', time() - 3600, $path);
        // If you used role-specific paths like '/admin', you can repeat clearing with that path too.
    }

    session_destroy();
}

// 1) If all=1 → wipe everything and redirect
if (isset($_GET['all']) && $_GET['all'] == '1') {
    foreach ($ROLE_TO_SESS as $sessName) {
        destroy_session_by_name($sessName, '/');
    }
    // Also clear the neutral/public session just in case
    destroy_session_by_name('sess_public', '/');

    header('Location: login.php?action=logout_all');
    exit;
}

// 2) If an explicit role is given, use it
if (isset($_GET['role']) && array_key_exists($_GET['role'], $ROLE_TO_SESS)) {
    $sessName = $ROLE_TO_SESS[$_GET['role']];
    destroy_session_by_name($sessName, '/');

    header('Location: login.php?action=logout');
    exit;
}

// 3) Auto-detect: if exactly one role-session cookie is present, log out that one
$present = [];
foreach ($ROLE_TO_SESS as $r => $sName) {
    if (!empty($_COOKIE[$sName])) $present[] = ['role' => $r, 'sess' => $sName];
}

if (count($present) === 1) {
    destroy_session_by_name($present[0]['sess'], '/');
    header('Location: login.php?action=logout');
    exit;
}

// 4) Fallback: just clear the neutral/public session (doesn’t affect role sessions)
destroy_session_by_name('sess_public', '/');
header('Location: login.php?action=logout');
exit;
