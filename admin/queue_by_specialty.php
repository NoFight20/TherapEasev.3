<?php
// queue_by_specialty.php  (only helpers changed + a tiny sort tweak)

session_name('sess_admin');
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!isset($_SESSION['user']) || $_SESSION['user'] === '' || ($_SESSION['usertype'] ?? '') !== 'a') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$useremail = $_SESSION['user'];

require_once '../connection.php';
date_default_timezone_set('Asia/Manila');

function norm($s) {
    $s = trim((string)$s);
    if ($s === '') return '';
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

/* ---------- UPDATED STATUS/PRIORITY RECOGNIZERS ---------- */

function is_serving_status($st) {
    $s = norm($st);
    // cover: Serving, Now Serving, In Progress, Processing, Attending, On-going
    return str_contains($s,'serve') || str_contains($s,'progress') || str_contains($s,'process') || str_contains($s,'attend') || str_contains($s,'ongo');
}
function is_waiting_status($st) {
    $s = norm($st);
    // cover: Waiting, Queued, Queue, Pending, On Queue, Next
    return str_contains($s,'wait') || str_contains($s,'queue') || str_contains($s,'queued') || str_contains($s,'pending') || str_contains($s,'next');
}
function is_priority_value($p) {
    $s = norm($p);
    // anything not normal is priority; also common keywords:
    if ($s === '' || $s === 'normal' || $s === 'n/a' || $s === 'na' || $s === 'none') return false;
    return true; // covers PWD, Senior, Pregnant, Emergency, etc.
}

/* picks */

function pick_now_serving(array $rows) {
    foreach ($rows as $r) {
        if (is_serving_status($r['queue_status'] ?? '')) return $r;
    }
    return null;
}
function pick_next(array $rows) {
    $waiting = array_filter($rows, function($r){ return is_waiting_status($r['queue_status'] ?? ''); });
    if (!$waiting) return null;
    usort($waiting, function($a,$b){
        return (int)($a['token_number'] ?? PHP_INT_MAX) <=> (int)($b['token_number'] ?? PHP_INT_MAX);
    });
    return $waiting[0] ?? null;
}
function pick_priority(array $rows) {
    $pri = array_filter($rows, function($r){
        return is_waiting_status($r['queue_status'] ?? '') && is_priority_value($r['priority'] ?? 'normal');
    });
    if (!$pri) return null;
    usort($pri, function($a,$b){
        return (int)($a['token_number'] ?? PHP_INT_MAX) <=> (int)($b['token_number'] ?? PHP_INT_MAX);
    });
    return $pri[0] ?? null;
}

/* token-only */
function token_only($row) {
    if (!$row) return 'N/A';
    $ft = (string)($row['formatted_token'] ?? '');
    if (trim($ft) !== '') return $ft;
    if (isset($row['token_number'])) return (string)$row['token_number'];
    return 'N/A';
}

try {
    // 1) Get admin profile -> specialty
    $adminSnap = $database->getReference('admin')->orderByChild('email')->equalTo($useremail)->getSnapshot();
    $adminData = $adminSnap->getValue();
    if (!$adminData) throw new RuntimeException('Admin not found for email: '.$useremail);
    $first = reset($adminData);
    $snameRaw = $first['sname'] ?? 'N/A';
    $specialtyName = is_array($snameRaw) ? ($snameRaw[0] ?? 'N/A') : $snameRaw;

    // 2) Overrides
    $overrideDept = trim($_GET['s'] ?? '');
    $showAll = isset($_GET['all']) && ($_GET['all'] === '1' || norm($_GET['all']) === 'true');
    if (norm($specialtyName) === 'information') { $showAll = true; }

    // 3) Collect rows by department
    $rowsByDept = [];

    if ($showAll && $overrideDept === '') {
        $rootVal = $database->getReference('queue')->getValue();
        if ($rootVal && is_array($rootVal)) {
            foreach ($rootVal as $department => $entries) {
                if (!is_array($entries)) continue;
                foreach ($entries as $pushId => $item) {
                    $rowsByDept[$department][] = [
                        'formatted_token' => $item['formatted_token'] ?? '',
                        'token_number'    => (int)($item['token_number'] ?? 0),
                        'priority'        => $item['priority'] ?? 'Normal',
                        'queue_status'    => $item['queue_status'] ?? 'Waiting',
                    ];
                }
            }
        }
        $responseMeta = ['specialty' => 'ALL (Information)', 'mode' => 'all'];
    } else {
        $dept = $overrideDept !== '' ? $overrideDept : $specialtyName;
        if ($dept === 'N/A' || $dept === '') {
            echo json_encode(['specialty' => null, 'mode' => 'single', 'departments' => []]);
            exit();
        }
        $qVal = $database->getReference('queue/'.$dept)->getValue();
        if ($qVal && is_array($qVal)) {
            foreach ($qVal as $pushId => $item) {
                $rowsByDept[$dept][] = [
                    'formatted_token' => $item['formatted_token'] ?? '',
                    'token_number'    => (int)($item['token_number'] ?? 0),
                    'priority'        => $item['priority'] ?? 'Normal',
                    'queue_status'    => $item['queue_status'] ?? 'Waiting',
                ];
            }
        }
        $responseMeta = ['specialty' => $dept, 'mode' => 'single'];
    }

    // 4) Reduce to tokens per department
    ksort($rowsByDept, SORT_NATURAL | SORT_FLAG_CASE);
    $departments = [];
    foreach ($rowsByDept as $dept => $rows) {
        // stable sort (serving -> waiting -> others), then token asc
        usort($rows, function($a, $b){
            $ra = is_serving_status($a['queue_status'] ?? '') ? 0 : (is_waiting_status($a['queue_status'] ?? '') ? 1 : 2);
            $rb = is_serving_status($b['queue_status'] ?? '') ? 0 : (is_waiting_status($b['queue_status'] ?? '') ? 1 : 2);
            if ($ra === $rb) {
                return (int)($a['token_number'] ?? PHP_INT_MAX) <=> (int)($b['token_number'] ?? PHP_INT_MAX);
            }
            return $ra <=> $rb;
        });

        $now = pick_now_serving($rows);
        $next = pick_next($rows);
        $pri  = pick_priority($rows);

        $departments[] = [
            'department'     => (string)$dept,
            'now_token'      => token_only($now),
            'next_token'     => token_only($next),
            'priority_token' => token_only($pri),
        ];
    }

    echo json_encode(array_merge($responseMeta, ['departments' => $departments]), JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
