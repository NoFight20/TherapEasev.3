<?php
session_name('sess_admin'); session_start();

// Guard: only logged-in admins
if (!isset($_SESSION['user']) || ($_SESSION['usertype'] ?? '') !== 'a') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['items' => [], 'count' => 0]);
    exit();
}

header('Content-Type: application/json; charset=utf-8');

require_once '../connection.php'; // adjust path if needed

$q      = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$limit  = isset($_GET['limit']) ? (int)$_GET['limit'] : 300;
$limit  = max(1, min(1000, $limit)); // clamp for safety

try {
    $patientsRef = $database->getReference('patients');
    $patients    = $patientsRef->getValue() ?: [];

    $items = [];
    $qLower = mb_strtolower($q, 'UTF-8');

    foreach ($patients as $pid => $p) {
        $fname    = (string)($p['fname'] ?? '');
        $lname    = (string)($p['lname'] ?? '');
        $name     = (string)($p['name'] ?? trim($fname . ' ' . $lname));
        $age      = (string)($p['age'] ?? 'N/A');
        $email    = (string)($p['email'] ?? 'No Account Yet');
        $gender   = (string)($p['gender'] ?? 'N/A');
        $civil    = (string)($p['civil_status'] ?? 'N/A');
        $dob      = (string)($p['dob'] ?? 'N/A');
        $tele     = (string)($p['tele'] ?? 'N/A');
        $barangay = (string)($p['barangay'] ?? 'N/A');
        if ($barangay === '0') $barangay = 'N/A';
        $city     = (string)($p['city'] ?? 'N/A');
        $province = (string)($p['province'] ?? 'N/A');
        $type     = (string)($p['type'] ?? 'N/A');

        // If q is empty -> include all
        $include = ($qLower === '');

        if (!$include) {
            // Case-insensitive contains match on any of these fields
            $fields = [
                $name, $fname, $lname, $email, $tele, $dob, $gender, $civil,
                trim($barangay . ' ' . $city . ' ' . $province)
            ];
            foreach ($fields as $f) {
                if ($f !== '' && mb_stripos($f, $qLower, 0, 'UTF-8') !== false) {
                    $include = true;
                    break;
                }
            }
        }

        if ($include) {
            $items[] = [
                'id'           => $pid,
                'fname'        => $fname,
                'lname'        => $lname,
                'name'         => $name,
                'age'          => $age,
                'email'        => $email,
                'gender'       => $gender,
                'civil_status' => $civil,
                'dob'          => $dob,
                'tele'         => $tele,
                'barangay'     => $barangay,
                'city'         => $city,
                'province'     => $province,
                'type'         => $type,
            ];
            if (count($items) >= $limit) break;
        }
    }

    echo json_encode(['count' => count($items), 'items' => $items], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['count' => 0, 'items' => [], 'error' => $e->getMessage()]);
}
