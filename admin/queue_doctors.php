<?php
// queue_doctors_schedule.php  (FULL REVISED)
// --------------------------------------------------
declare(strict_types=1);
header('Content-Type: application/json');

include("../connection.php");

// --- Timezone & target date ---
date_default_timezone_set('Asia/Manila');
$targetDate = (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date']))
  ? $_GET['date']
  : date('Y-m-d');

$debug = isset($_GET['debug']) && $_GET['debug'] === '1';

// --- Helpers ---
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** Accepts 'YYYY-MM-DD', or strings starting with that; accepts 'YYYY/MM/DD' */
function looksLikeDateEq(string $v, string $target): bool {
    $v = trim($v);
    if ($v === $target) return true;
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) return substr($v, 0, 10) === $target;
    if (preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $v)) return str_replace('/', '-', $v) === $target;
    return false;
}

/** lower + trim */
function s_norm(?string $v): string {
    return strtolower(trim((string)$v));
}

/** explode a possibly noisy docid into candidate tokens */
function docid_candidates(?string $raw): array {
    $raw = (string)$raw;
    if ($raw === '') return [];
    $lo = s_norm($raw);

    // split on common separators; keep alnum chunks only
    $parts = preg_split('/[^a-z0-9@.\-_]+/i', $raw) ?: [];
    $parts = array_filter(array_map('trim', $parts), fn($x) => $x !== '');

    // also add a "stripped" version (remove non-alnum except @ . _ -)
    $stripped = preg_replace('/[^a-z0-9@.\-_]/i', '', $raw);

    $cands = array_unique(array_filter(array_map('s_norm', array_merge([$raw, $lo, $stripped], $parts))));
    return array_values($cands);
}

/** Choose "now" and "next" tokens based on queue/status */
function pickNowNext(array $appts): array {
    if (empty($appts)) return [null, null];

    usort($appts, function($a, $b) {
        $qa = (int)($a['queueNumber'] ?? $a['queue_no'] ?? PHP_INT_MAX);
        $qb = (int)($b['queueNumber'] ?? $b['queue_no'] ?? PHP_INT_MAX);
        if ($qa === $qb) {
            $ta = (string)($a['time'] ?? $a['start_time'] ?? '');
            $tb = (string)($b['time'] ?? $b['start_time'] ?? '');
            return strcmp($ta, $tb);
        }
        return $qa <=> $qb;
    });

    $statusNowSet = ['serving','called','ongoing','in-progress'];
    $statusWait   = ['confirmed','waiting','checked-in','queued','pending'];

    $now = null; $next = null;
    foreach ($appts as $a) {
        $st = strtolower((string)($a['status'] ?? ''));
        if (in_array($st, $statusNowSet, true)) { $now = $a; break; }
    }
    if ($now) {
        $nowQN = (int)($now['queueNumber'] ?? $now['queue_no'] ?? -1);
        foreach ($appts as $a) {
            $st = strtolower((string)($a['status'] ?? ''));
            $qn = (int)($a['queueNumber'] ?? $a['queue_no'] ?? -1);
            if ($qn > $nowQN && (in_array($st, $statusWait, true) || $st === '')) { $next = $a; break; }
        }
    } else {
        $now  = $appts[0] ?? null;
        $next = $appts[1] ?? null;
    }
    return [$now, $next];
}

/** Format a token string for display (e.g., "#1 – Drey Cruz" or just "#1") */
function fmt_token(?array $appt): ?string {
    if (!$appt) return null;
    $q = isset($appt['queueNumber']) || isset($appt['queue_no'])
        ? '#' . (int)($appt['queueNumber'] ?? $appt['queue_no'])
        : null;
    $p = trim((string)($appt['patientName'] ?? $appt['patient_name'] ?? ''));
    return $q ? ($p ? $q . ' – ' . $p : $q) : ($p ?: null);
}

// ----------------------------------------
// 1) Load appointments for target date (resilient)
// ----------------------------------------
$debugInfo = ['phase' => 'load', 'targetDate' => $targetDate, 'counts' => []];

try {
    // Primary: orderByChild('date') == target
    $appsSnap = $database->getReference('appointments')
        ->orderByChild('date')
        ->equalTo($targetDate)
        ->getSnapshot();

    $appsRaw = $appsSnap->getValue();
    $appointments = is_array($appsRaw) ? array_values($appsRaw) : [];

    // Fallback: pull all and filter by any known date key
    if (!$appointments) {
        $fallbackSnap = $database->getReference('appointments')->getSnapshot();
        $allRaw = $fallbackSnap->getValue();
        $candidates = is_array($allRaw) ? array_values($allRaw) : [];

        $dateKeys = ['date','appointmentDate','apptDate','schedule_date'];
        $filtered = [];
        foreach ($candidates as $row) {
            foreach ($dateKeys as $k) {
                if (!empty($row[$k]) && looksLikeDateEq((string)$row[$k], $targetDate)) {
                    $filtered[] = $row;
                    break;
                }
            }
        }
        $appointments = $filtered;
        if ($debug) {
            $debugInfo['counts']['fallback_total'] = count($candidates);
            $debugInfo['counts']['fallback_matched'] = count($appointments);
        }
    }
} catch (Throwable $e) {
    echo json_encode(['schedules' => [], 'error' => 'appointments_load_failed', 'message' => $e->getMessage()]);
    exit;
}


// ----------------------------------------
// 2) Load doctor directory and email→ID map
// ----------------------------------------
try {
    $docSnap = $database->getReference('doctor')->getSnapshot();
    $docMap  = $docSnap->getValue();
    if (!is_array($docMap)) $docMap = [];
} catch (Throwable $e) {
    $docMap = [];
}

$docByEmail = [];
foreach ($docMap as $did => $drow) {
    $em = strtolower(trim((string)($drow['email'] ?? '')));
    if ($em !== '') $docByEmail[$em] = $did;
}

// ----------------------------------------
// 3) Group appointments by doctor (tolerant)
// ----------------------------------------
$byDoctor = [];
$skipReasons = [
    'no_doctor' => 0,
    'filtered_status' => 0,
];

$allowed = ['confirmed','waiting','checked-in','queued','pending','serving','called','ongoing','in-progress'];
$blocked = ['completed','done','cancelled','canceled','no-show'];

foreach ($appointments as $a) {
    // Ensure the date still matches via any known key
    $hasDate = false;
    foreach (['date','appointmentDate','apptDate','schedule_date'] as $dk) {
        if (!empty($a[$dk]) && looksLikeDateEq((string)$a[$dk], $targetDate)) { $hasDate = true; break; }
    }
    if (!$hasDate) continue;

    // Normalize/permit status
    $status = strtolower(trim((string)($a['status'] ?? 'confirmed')));
    if (in_array($status, $blocked, true)) { $skipReasons['filtered_status']++; continue; }
    if (!in_array($status, $allowed, true)) { $status = 'waiting'; $a['status'] = $status; }

    // Resolve doctorId from multiple possible fields or via email mapping
    $did = (string)($a['doctorId'] ?? $a['doctor_id'] ?? $a['doctorUID'] ?? $a['doctorUid'] ?? '');
    if ($did === '' && !empty($a['doctorEmail'])) {
        $em = strtolower(trim((string)$a['doctorEmail']));
        if ($em && isset($docByEmail[$em])) $did = $docByEmail[$em];
    }
    if ($did === '' && !empty($a['doctor_email'])) {
        $em = strtolower(trim((string)$a['doctor_email']));
        if ($em && isset($docByEmail[$em])) $did = $docByEmail[$em];
    }

    if ($did === '') { $skipReasons['no_doctor']++; continue; }
    $byDoctor[$did][] = $a;
}

// ----------------------------------------
// 3b) Merge schedules for the date so doctors show even with 0 bookings
// ----------------------------------------
try {
    $schedSnap = $database->getReference('schedule')
        ->orderByChild('scheduledate')
        ->equalTo($targetDate)
        ->getSnapshot();

    $schedRaw = $schedSnap->getValue();
    if (is_array($schedRaw)) {
        // Build indices for flexible matching
        $schedIdToDoc = [];   // scheduleId -> doctorId (via doctor.sessions.scheduleid)
        $emailToDoc   = [];   // doctor.email (lower) -> doctorId
        $nicToDoc     = [];   // doctor.nic (lower)   -> doctorId
        $keySet       = [];   // doctorKey (lower)    -> doctorId

        foreach ($docMap as $did => $drow) {
            $keySet[s_norm($did)] = $did;
            $em = s_norm($drow['email'] ?? '');
            $nc = s_norm($drow['nic'] ?? '');
            if ($em !== '') $emailToDoc[$em] = $did;
            if ($nc !== '') $nicToDoc[$nc]   = $did;

            if (!empty($drow['sessions']) && is_array($drow['sessions'])) {
                foreach ($drow['sessions'] as $sessId => $sess) {
                    $sid = (string)($sess['scheduleid'] ?? '');
                    if ($sid !== '') $schedIdToDoc[$sid] = $did;
                }
            }
        }

        foreach ($schedRaw as $scheduleId => $row) {
            $did = null;

            // A) exact link via sessions.scheduleid
            if (isset($schedIdToDoc[$scheduleId])) {
                $did = $schedIdToDoc[$scheduleId];
            }

            // B) match schedule.docid to doctor key / email / NIC (case-insensitive, tolerant)
            if (!$did && !empty($row['docid'])) {
                $cands = docid_candidates((string)$row['docid']);

                foreach ($cands as $tok) {
                    // doctor key
                    if (!$did && isset($keySet[$tok])) { $did = $keySet[$tok]; break; }
                    // email
                    if (!$did && isset($emailToDoc[$tok])) { $did = $emailToDoc[$tok]; break; }
                    // NIC
                    if (!$did && isset($nicToDoc[$tok])) { $did = $nicToDoc[$tok]; break; }
                }

                // C) heuristic: if any token length looks like a Firebase key and exists (common 28-chars+)
                if (!$did) {
                    foreach ($cands as $tok) {
                        if (strlen($tok) >= 20 && isset($keySet[$tok])) { $did = $keySet[$tok]; break; }
                    }
                }
            }

            // D) last resort: match by date/time against doctor.sessions
            if (!$did) {
                $sd = (string)($row['scheduledate'] ?? '');
                $st = (string)($row['scheduletime'] ?? '');
                foreach ($docMap as $candDid => $drow) {
                    if (empty($drow['sessions']) || !is_array($drow['sessions'])) continue;
                    foreach ($drow['sessions'] as $sessId => $sess) {
                        $sessDate = (string)($sess['scheduledate'] ?? $sess['date'] ?? '');
                        $sessTime = (string)($sess['scheduletime'] ?? $sess['time'] ?? '');
                        if ($sessDate === $sd && $sessTime === $st) { $did = $candDid; break 2; }
                    }
                }
            }

            if (!$did) continue; // can’t associate this schedule to a doctor entry

            // Ensure bucket
            if (!isset($byDoctor[$did])) $byDoctor[$did] = [];

            // Stash schedule slot so the card shows a time even with 0 appointments
            $byDoctor[$did]['__schedule_slot__'][] = [
                'time'  => (string)($row['scheduletime'] ?? ''),
                'title' => (string)($row['title'] ?? ''),
                'id'    => (string)$scheduleId,
                'docid' => (string)($row['docid'] ?? ''),
            ];
        }
    }
} catch (Throwable $e) {
    // non-fatal; skip schedule enrichment
}



if (!$byDoctor) {
    // After schedule merge, we might still have nothing; return empty gracefully
    $out = ['schedules' => [], 'date' => $targetDate];
    if ($debug) $out['debug'] = ['skip' => $skipReasons, 'note' => 'No groups after filtering (including schedules)'];
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ----------------------------------------
// 4) Build schedule cards
// ----------------------------------------
$schedules = [];
$schedules = [];
foreach ($byDoctor as $doctorId => $bucket) {
    // When we merged schedules, we may have stored a special key in the bucket,
    // so split real appointments from metadata.
    $list = [];
    $schedSlots = [];
    if (is_array($bucket)) {
        foreach ($bucket as $k => $v) {
            if ($k === '__schedule_slot__') { $schedSlots = $v; continue; }
            // real appointment rows are numeric-indexed (from array_values)
            if (is_int($k)) $list[] = $v;
        }
    }

    $doc = $docMap[$doctorId] ?? null;
    $doctorName = trim((string)($doc['name'] ?? (trim(($doc['fname'] ?? '') . ' ' . ($doc['lname'] ?? '')))));
    if ($doctorName === '') $doctorName = '—';

    // Specialty: prefer sname, then specialty
    $spec = $doc['sname'] ?? ($doc['specialty'] ?? null);
    $room = $doc['room']  ?? null;

    // now/next selection (works even if $list is empty)
    [$nowAppt, $nextAppt] = pickNowNext($list);

    // Representative slot = most common appt time; if none, fall back to schedule slot(s)
    $timeCounts = [];
    foreach ($list as $a) {
        $t = (string)($a['time'] ?? $a['start_time'] ?? $a['slot'] ?? '');
        if ($t !== '') $timeCounts[$t] = ($timeCounts[$t] ?? 0) + 1;
    }
    $slot = null;
    if ($timeCounts) { arsort($timeCounts); $slot = array_key_first($timeCounts); }

    if (!$slot && !empty($schedSlots)) {
        // Use the first schedule time for display when there are 0 appointments
        $slot = (string)($schedSlots[0]['time'] ?? '');
        if ($slot === '') $slot = null;
    }


    $schedules[] = [
        'doctor'         => $doctorName,
        'specialty'      => $spec ?: null,
        'room'           => $room ?: null,
        'slot'           => $slot ?: null,
        'now_token'      => fmt_token($nowAppt),
        'next_token'     => fmt_token($nextAppt),
        'priority_token' => null, // (optional) if your appts store PWD/Senior/Emergency
        'doctor_id'      => $doctorId,
        'count'          => count($list),
    ];
}

// Sort for stable front-end display
usort($schedules, fn($a,$b) => strcmp((string)$a['doctor'], (string)$b['doctor']));

// ----------------------------------------
// 5) Output
// ----------------------------------------
$out = [
    'date'      => $targetDate,
    'schedules' => $schedules
];
if ($debug) {
    $out['debug'] = [
        'groups' => array_map('count', $byDoctor),
        'skip'   => $skipReasons,
        'phase'  => $debugInfo,
    ];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
