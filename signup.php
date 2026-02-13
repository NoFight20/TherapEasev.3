<?php
// Use same session as login.php so flash messages carry over
session_name('sess_public');
session_start();

// Import Firebase configuration
include("connection.php");

// ---------- Helpers ----------
function safe_val($arr, $key, $default='') { return isset($arr[$key]) ? $arr[$key] : $default; }
function mask_name($str) {
  $str = (string)$str; $len = mb_strlen($str);
  if ($len <= 1) return $str;
  if ($len == 2) return mb_substr($str,0,1).'*';
  return mb_substr($str,0,1) . str_repeat('*', $len-2) . mb_substr($str,-1);
}
function mask_mid($str, $keepStart=0, $keepEnd=4) {
  $str = (string)$str; $len = mb_strlen($str);
  if ($len <= $keepStart + $keepEnd) return str_repeat('*', $len);
  return mb_substr($str,0,$keepStart) . str_repeat('*',$len-$keepStart-$keepEnd) . mb_substr($str,-$keepEnd);
}
function mask_city($str) {
  $str = (string)$str; if ($str === '') return '';
  $keep = min(3, mb_strlen($str));
  return mb_substr($str,0,$keep) . str_repeat('*', max(0, mb_strlen($str)-$keep));
}
if (!function_exists('mb_strtolower')) { function mb_strtolower($s){ return strtolower($s); } }
function norm_txt($s){
  $s = preg_replace('/\s+/', ' ', trim((string)$s));
  $s = preg_replace("/[^\\p{L}\\p{N} ]/u", '', $s);
  return mb_strtolower($s);
}
if (!function_exists('safe_contains')) {
  function safe_contains($haystack, $needle) {
    return $needle !== '' && mb_strpos($haystack, $needle) !== false;
  }
}

// ---------- Reset session login state for this page ----------
$_SESSION["user"] = "";
$_SESSION["usertype"] = "";

// ---------- Background ----------
$defaultBackground = "img/GentriMed.jpg";
try {
    $backgroundData = $database->getReference("backgrounds/login")->getValue();
} catch (Exception $e) {
    $backgroundData = null;
}
$backgroundURL = ($backgroundData && isset($backgroundData['url'])) ? $backgroundData['url'] : $defaultBackground;

// ---------- Timezone / Date ----------
date_default_timezone_set('Asia/Manila');
$date = date('Y-m-d');
$_SESSION["date"] = $date;

// ---------- Confirm modal POST handler (Yes/No) ----------
$confirm_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_action'])) {
    if ($_POST['confirm_action'] === 'no') {
        // User said "not me" → clear candidate and continue as new on page 2
        unset($_SESSION['candidate_patient'], $_SESSION['match_mode']);
        header("Location: create-account.php");
        exit();
    }

    if ($_POST['confirm_action'] === 'yes') {
        // Require DOB re-entry to confirm identity
        $confirmDob = trim($_POST['confirm_dob'] ?? '');
        $cand       = $_SESSION['candidate_patient'] ?? null;
        $mode       = $_SESSION['match_mode'] ?? 'name_dob';

        if (!$cand) {
            $confirm_error = "Session expired. Please try again.";
        } else {
            if ($mode === 'name_dob') {
                // We have DOB in record → must match exactly
                $recordDob = (string)($cand['record']['dob'] ?? '');
                if ($confirmDob === '' || $confirmDob !== $recordDob) {
                    $confirm_error = "Birthdate does not match our records.";
                } else {
                    $_SESSION['confirmed_candidate_ok'] = true;
                    header("Location: create-account.php");
                    exit();
                }
            } else {
                // mode === 'name_only' (record has NO DOB). We confirm against the DOB the user entered on page 1
                $pageDob = (string)($_SESSION['personal']['dob'] ?? '');
                if ($confirmDob === '' || $pageDob === '' || $confirmDob !== $pageDob) {
                    $confirm_error = "Birthdate does not match what you entered.";
                } else {
                    // Optionally: capture DOB to write later during linking
                    $_SESSION['confirmed_candidate_ok'] = true;
                    $_SESSION['new_dob_for_candidate'] = $confirmDob;
                    header("Location: create-account.php");
                    exit();
                }
            }
        }
    }
}

// ---------- Main form POST handler (Save details / check candidate) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['confirm_action'])) {
    $fname = trim($_POST['fname'] ?? '');
    $lname = trim($_POST['lname'] ?? '');
    $dob   = trim($_POST['dob'] ?? '');

    // Age gate
    try {
        $birthDate = new DateTime($dob);
    } catch (Exception $e) {
        echo "<script>alert('Please enter a valid date of birth.'); window.history.back();</script>";
        exit();
    }
    $today = new DateTime();
    $age = $today->diff($birthDate)->y;

    $province = $_POST['province'] ?? 'Cavite'; // default/map per city

    if ($age < 18) {
        echo "<script>alert('You must be at least 18 years old to create an account.'); window.history.back();</script>";
        exit();
    }

    // Persist page-1 fields
    $_SESSION["personal"] = [
      'fname'         => $fname,
      'lname'         => $lname,
      'dob'           => $dob,
      'province'      => $province,
      'city'          => $_POST['city'] ?? '',
      'barangay'      => $_POST['barangay'] ?? '',
      'gender'        => $_POST['gender'] ?? '',
      'civil_status'  => $_POST['civil'] ?? ''
    ];

    // ---------- Candidate search (prefer Name + DOB, fallback to Name-only where record has no DOB) ----------
    $foundKey  = null;
    $found     = null;
    $matchMode = null;

    $nf = norm_txt($fname);
    $nl = norm_txt($lname);

    try {
        // 1) Try DOB-indexed search, then verify names
        $byDob = $database->getReference('patients')->orderByChild('dob')->equalTo($dob)->getValue();
        if ($byDob && is_array($byDob)) {
            foreach ($byDob as $k => $p) {
                $pf    = norm_txt($p['fname'] ?? '');
                $pl    = norm_txt($p['lname'] ?? '');
                $pname = norm_txt($p['name']  ?? '');
                if (($pf === $nf && $pl === $nl) || ($pname && safe_contains($pname, $nf) && safe_contains($pname, $nl))) {
                    $foundKey  = $k;
                    $found     = $p;
                    $matchMode = 'name_dob';
                    break;
                }
            }
        }

        // 2) Fallback: full scan name-only where record has no DOB
        if (!$foundKey) {
            $all = $database->getReference('patients')->getSnapshot()->getValue();
            $nameOnlyHits = [];
            if ($all && is_array($all)) {
                foreach ($all as $k => $p) {
                    $p_dob = trim((string)($p['dob'] ?? ''));
                    if ($p_dob !== '') continue;
                    $pf = norm_txt($p['fname'] ?? '');
                    $pl = norm_txt($p['lname'] ?? '');
                    if ($pf === $nf && $pl === $nl) {
                        $nameOnlyHits[] = ['k' => $k, 'p' => $p];
                    }
                }
            }

            if (count($nameOnlyHits) === 1) {
                $foundKey  = $nameOnlyHits[0]['k'];
                $found     = $nameOnlyHits[0]['p'];
                $matchMode = 'name_only';
            } elseif (count($nameOnlyHits) > 1) {
                // Ambiguous → do NOT link automatically; continue as new registration page 2
                $_SESSION['login_hint'] = "We found multiple hospital records with the same name. Please continue with sign up; for merging, contact the hospital.";
                session_write_close();
                header("Location: create-account.php");
                exit();
            }
        }
    } catch (Exception $e) {
        error_log('[signup:lookup] '.$e->getMessage());
        // treat as no match; go to page 2
    }

    if ($foundKey && is_array($found)) {
        // If record already has an email, send them to login
        if (!empty(trim((string)($found['email'] ?? '')))) {
            $_SESSION['login_hint'] = "We found an existing hospital record already linked with an email. Please log in or use Forgot Password.";
            session_write_close();
            header("Location: login.php?msg=existing");
            exit();
        }

        // Otherwise (record has NO email) → stage confirm flow (one-time modal)
        $_SESSION['candidate_patient'] = ['key' => $foundKey, 'record' => $found];
        $_SESSION['match_mode']        = $matchMode;   // name_dob | name_only
        $_SESSION['show_confirm_once'] = true;

        // PRG: redirect to this same page so refresh won't re-POST
        $me = strtok($_SERVER['REQUEST_URI'], '?'); // strip query
        header("Location: {$me}?confirm=1");
        exit();

    } else {
        // No candidate → go to page 2
        header("Location: create-account.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/animations.css">
  <link rel="stylesheet" href="css/main.css">
  <link rel="stylesheet" href="css/signup.css">
  <title>Sign Up</title>
  <style>
      body {
          background-image: url('<?php echo htmlspecialchars($backgroundURL); ?>');
          background-repeat: no-repeat;
          background-attachment: fixed;
          background-size: cover;
          min-height: 100vh;
      }
      /* Responsive table container */
      .responsive-table {
        width: 90%;
        max-width: 700px;
        overflow-x: auto;
        margin: 0 auto;
      }
      .input-text { width: 100%; box-sizing: border-box; }
      @media screen and (max-width: 768px) {
        .responsive-table { width: 95%; }
        .container { padding: 10px; }
        .sub-text { font-size: 16px; }
        .login-btn { width: 100%; max-width: none; margin-top: 10px; }
        td[colspan="2"] { display: block; width: 100%; }
        input[type="text"], input[type="date"], 
        select { 
          width: 100% !important; 
          margin-top: 8px; 
        }
        .form-label { 
          display: block; 
          margin-top: 10px; 
        }
      }
      /* Custom select arrow */
      .dropdown {
        appearance: none; -webkit-appearance: none; -moz-appearance: none;
        background: white url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><polyline points='6 9 12 15 18 9'/></svg>") no-repeat right 10px center;
        background-size: 16px 16px;
        padding-right: 35px;
        border: 1px solid #ccc; 
        border-radius: 6px;
        height: 35px; 
        font-size: 14px; 
        cursor: pointer;
      }
      .dropdown:focus { 
        border-color: #007bff; 
        outline: none; 
      }

      /* Modal (Confirm) */
      .modal {
          display: none; 
          position: fixed; 
          z-index: 1000; 
          left: 0; 
          top: 0;
          width: 100%; 
          height: 100%; 
          overflow: auto; 
          background-color: rgba(0,0,0,0.5);
      }
      .modal-content {
          background-color: #fff; 
          margin: 5% auto; 
          padding: 20px; 
          border-radius: 8px;
          width: 90%; 
          max-width: 800px; 
          box-shadow: 0 2px 4px rgba(0,0,0,.1); 
          text-align: left; 
          overflow-wrap: break-word;
      }
      .modal-content h2 { 
        color: #4CAF50; 
        margin-top: 0; 
      }
      .modal-content p { 
        margin: 10px 0; 
      }
      .ok-btn {
          padding: 10px 20px; 
          font-weight: 600;
          border: 0; 
          border-radius: 6px;
          background:#00a65a; 
          color:#fff; 
          cursor:pointer;
      }
  </style>
</head>
<body>
  <center style="margin-top: 100px">
    <div class="container">
      <form action="" method="POST">
        <div class="responsive-table">
          <table border="0">
            <tr>
              <td colspan="2">
                <div style="display: flex; align-items: center; justify-content: space-between; width: 100%;">
                  <button type="button" onclick="goBackAndSave()" class="login-btn btn-secondary-soft btn"
                          style="width: 100px; padding: 6px 0; font-size: 14px;">
                    ← Back
                  </button>
                  <div style="flex-grow: 1; text-align: center;">
                    <p class="sub-text" style="font-weight: bold; font-size: 22px; margin: 0 0 0 30px;">Add Patient Details</p>
                  </div>
                  <div style="width: 150px;"></div>
                </div>
              </td>
            </tr>

            <!-- Province defaults to Cavite; keep hidden here -->
            <input type="hidden" name="province" id="province" value="Cavite">

            <tr>
              <td colspan="2" style="text-align: left;">
                <label for="name" class="form-label" style="margin-right: 10px;">First Name:</label>
                <input type="text" name="fname" class="input-text" placeholder="First Name" required style="margin-right: 10px;">
                <label for="name" class="form-label" style="margin-right: 10px; margin-top: 20px;">Last Name:</label>
                <input type="text" name="lname" class="input-text" placeholder="Last Name" required>
              </td>
            </tr>

            <tr>
              <td colspan="2" class="label-td">
                <label for="city" class="form-label">City:</label>
                <select id="city" name="city" class="input-text dropdown" onchange="updateBarangay()" required>
                  <option value="">Select City</option>
                </select>

                <label for="barangay" class="form-label">Barangay:</label>
                <select id="barangay" name="barangay" class="input-text dropdown" required>
                  <option value="">Select Barangay</option>
                </select>
              </td>
            </tr>

            <tr><td colspan="2" class="label-td"><label for="civil" class="form-label">Civil Status:</label></td></tr>
            <tr>
              <td colspan="2" class="label-td">
                <select id="civil" name="civil" class="input-text dropdown" required>
                  <option value="">Select your Civil Status</option>
                  <option value="Single">Single</option>
                  <option value="Married">Married</option>
                  <option value="Divorced">Divorced</option>
                </select>
              </td>
            </tr>

            <!-- Gender -->
            <tr><td colspan="2" class="label-td"><label for="gender" class="form-label">Biological Sex:</label></td></tr>
            <tr>
              <td colspan="2" class="label-td">
                <select name="gender" class="input-text dropdown" required>
                  <option value="" disabled selected>Select Gender</option>
                  <option value="Male">Male</option>
                  <option value="Female">Female</option>
                </select>
              </td>
            </tr>

            <!-- DOB -->
            <tr><td colspan="2" class="label-td"><label for="dob" class="form-label">Date of Birth:</label></td></tr>
            <tr>
              <td colspan="2" class="label-td">
                <input type="date" name="dob" id="dob" class="input-text" required>
              </td>
            </tr>

            <tr>
              <td colspan="2" style="text-align: center;">
                <div style="display: flex; justify-content: center; gap: 20px;">
                  <input type="reset" value="Reset" class="login-btn btn-primary-soft btn" style="flex: 1; max-width: 150px;">
                  <input type="submit" value="Next" class="login-btn btn-primary-soft btn" style="flex: 1; max-width: 150px;">
                </div>
              </td>
            </tr>

          </table>
        </div>
      </form>
    </div>
  </center>

<?php if (!empty($_SESSION['candidate_patient']) && !empty($_SESSION['show_confirm_once'])): 
  $cand = $_SESSION['candidate_patient'];
  $p    = safe_val($cand, 'record', []);
  $mode = $_SESSION['match_mode'] ?? 'name_dob';

  $maskedFullName = trim(mask_name(safe_val($p,'fname',''))." ".mask_name(safe_val($p,'lname','')));
  $dobRaw = safe_val($p,'dob','');
  $maskedDOB = $dobRaw ? ('****-**-'.substr($dobRaw, -2)) : '—';
  $maskedTele = mask_mid(safe_val($p,'tele',''), 0, 4);
  $maskedCity = mask_city(safe_val($p,'city',''));
?>
<!-- Confirm It's You Modal -->
<div class="modal" id="confirmModal" style="display:block;">
  <div class="modal-content">
    <h2>Confirm It’s Your Record</h2>
    <?php if (!empty($confirm_error)): ?>
      <p style="color:red;"><?php echo htmlspecialchars($confirm_error); ?></p>
    <?php endif; ?>
    <p>
      <?php if ($mode === 'name_dob'): ?>
        We found a record that may be yours (matched by name and birthdate).
      <?php else: ?>
        We found a record that matches your name but it has no birthdate on file. Please confirm your birthdate to link it.
      <?php endif; ?>
    </p>
    <ul>
      <li><strong>Name:</strong> <?php echo htmlspecialchars($maskedFullName ?: '—'); ?></li>
      <li><strong>DOB:</strong> <?php echo htmlspecialchars($maskedDOB); ?></li>
      <li><strong>Mobile:</strong> <?php echo htmlspecialchars($maskedTele ?: '—'); ?></li>
      <li><strong>City:</strong> <?php echo htmlspecialchars($maskedCity ?: '—'); ?></li>
    </ul>

    <form method="post" action="">
      <div style="margin:12px 0;">
        <label class="form-label">Re-enter your full Birthdate (YYYY-MM-DD)</label>
        <input type="date" name="confirm_dob" class="input-text" required>
      </div>
      <div style="display:flex; gap:8px; margin-top:16px;">
        <button class="login-btn btn-primary btn" type="submit" name="confirm_action" value="yes">Yes, that’s me</button>
        <button class="login-btn btn-primary-soft btn" type="submit" name="confirm_action" value="no">No, this isn’t me</button>
      </div>
    </form>
    <p class="sub-text" style="margin-top:10px;font-size:12px;color:#4CAF50;">We only show partial info for your privacy.</p>
  </div>
</div>
<?php 
  // show only once on the redirected GET
  unset($_SESSION['show_confirm_once']); 
endif; 
?>


  <script>
  // Cities/Barangays data (trim or extend as you wish)
 const barangaysByCity = {
    "Alfonso": [
      "Amuyong","Barangay I (Pob.)","Barangay II (Pob.)","Barangay III (Pob.)","Barangay IV (Pob.)","Barangay V (Pob.)","Barangay VI (Pob.)","Barangay VII (Pob.)","Barangay VIII (Pob.)",
    "Barangay IX (Pob.)","Barangay X (Pob.)","Buck Estate","Esperanza Ilaya","Esperanza Proper","Kaytitinga I","Kaytitinga II","Kaytitinga III","Kaysuyo","Luksuhin","Luksuhin Ilaya","Marahan I",
    "Marahan II","Matagbak I","Matagbak II","Palumlum","Panalok","Puti","Sulsugin","Taywanak Ilaya","Taywanak Ibaba","Upli"
  ],

    "Amadeo": [
      "Banaybanay","Barangay I (Pob.)","Barangay II (Pob.)","Barangay III (Pob.)","Barangay IV (Pob.)","Barangay V (Pob.)","Barangay VI (Pob.)","Barangay VII (Pob.)","Bucal","Dagatan","Halang","Loma",
      "Maitim I","Maymangga","Minantok Kanluran","Minantok Silangan","Salaban","Talang"
    ],

    "Bacoor": [
      "Alima","Aniban I","Aniban II","Aniban III","Aniban IV","Aniban V","Banalo","Bayanan","Campo Santo","Daang Bukid","Digman","Dulong Bayan","Habay I","Habay II","Kaingin","Ligas I","Ligas II",
      "Ligas III","Mabolo I","Mabolo II","Mabolo III","Maliksi I","Maliksi II","Maliksi III","Mambog I","Mambog II","Mambog III","Mambog IV","Mambog V","Molino I","Molino II","Molino III","Molino IV",
      "Molino V","Molino VI","Molino VII","Niog I","Niog II","Niog III","Panapaan I","Panapaan II","Panapaan III","Panapaan IV","Panapaan V","Panapaan VI","Panapaan VII","Panapaan VIII",
      "Queens Row Central","Queens Row East","Queens Row West","Real I","Real II","Salinas I","Salinas II","Salinas III","Salinas IV","San Nicolas I","San Nicolas II","San Nicolas III",
      "Sineguelasan","Tabing Dagat","Talaba I","Talaba II","Talaba III","Talaba IV","Talaba V","Talaba VI","Talaba VII","Zapote I","Zapote II","Zapote III","Zapote IV","Zapote V"
    ],

    "Carmona": [
      "Barangay I (Pob.)","Barangay II (Pob.)","Barangay III (Pob.)","Barangay IV (Pob.)","Barangay V (Pob.)","Barangay VI (Pob.)","Barangay VII (Pob.)","Barangay VIII (Pob.)","Barangay IX (Pob.)",
      "Barangay X (Pob.)","Barangay XI (Pob.)","Barangay XII (Pob.)","Cabilang Baybay","Mabuhay","Milagrosa","Poblacion"
    ],
    "Cavite City": [
      "Barangay 1 (Hen. M. Alvarez)","Barangay 2 (Caridad Proper)","Barangay 3 (Dalahican)","Barangay 4 (San Antonio)","Barangay 5 (San Roque)","Barangay 6 (Kanlurang Bagong Bayan)",
      "Barangay 7 (Kanlurang Bagong Bayan)","Barangay 8 (Kanlurang Bagong Bayan)","Barangay 9 (Kanlurang Bagong Bayan)","Barangay 10 (Kanlurang Bagong Bayan)","Barangay 11 (Kanlurang Bagong Bayan)",
      "Barangay 12 (Kanlurang Bagong Bayan)","Barangay 13 (Kanlurang Bagong Bayan)","Barangay 14 (Kanlurang Bagong Bayan)","Barangay 15 (Kanlurang Bagong Bayan)","Barangay 16 (Silangan)",
      "Barangay 17 (Silangan)","Barangay 18 (Silangan)","Barangay 19 (Silangan)","Barangay 20 (Silangan)","Barangay 21 (Silangan)","Barangay 22 (Silangan)","Barangay 23 (Silangan)",
      "Barangay 24 (Silangan)","Barangay 25 (Silangan)","Barangay 26 (Silangan)","Barangay 27 (Silangan)","Barangay 28 (Silangan)","Barangay 29 (Silangan)","Barangay 30 (Silangan)",
      "Barangay 31 (Silangan)"
    ],
    "Dasmariñas": [
      "Burol I","Burol II","Burol III","Datu Esmael","Emmanuel Bergado I","Emmanuel Bergado II","Fatima I","Fatima II","Fatima III","H-2","Langkaan I","Langkaan II","Luzviminda I",
      "Luzviminda II","Paliparan I","Paliparan II","Paliparan III","Sabang","Salawag","Salitran I","Salitran II","Salitran III","Salitran IV","San Agustin I","San Agustin II","San Agustin III",
      "San Agustin IV","San Andres I","San Andres II","San Antonio de Padua I","San Antonio de Padua II","San Antonio de Padua III","San Esteban","San Jose","San Juan","San Lorenzo Ruiz I",
      "San Lorenzo Ruiz II","San Luis I","San Luis II","San Manuel I","San Manuel II","San Manuel III","San Mateo","San Miguel","San Nicolas I","San Nicolas II","San Roque","Santa Cristina I",
      "Santa Cristina II","Santa Cruz I","Santa Cruz II","Santa Fe","Santa Lucia","Santa Maria","Santo Cristo","Santo Niño I","Santo Niño II","Santo Niño III","Victoria Reyes","Zone I","Zone II",
      "Zone III","Zone IV"
    ],
    "General Emilio Aguinaldo": [ 
      "Acle","Batas","Castanos Cerca","Castanos Lejos","Kaypaaba","Lumipa","Narvaez","Pantihan I","Pantihan II","Pantihan III","Pantihan IV", "San Agustin","San Francisco","Sulok","Tabora" 
    ],
    "General Mariano Alvarez": [ 
      "Aldiano Olaes","Bernardo Pulido","Epifanio Malia","Feliciano Subd. (Part)","Francisco de Castro","Francisco Reyes","Gabriel Abad Santos","Gavino Maderan", "Inocencio Salud",
      "Jacinto Lumbreras","Kapitan Kua","Koronel Jose P. Elises","Macario Dacon","Nicolasa Virata","Poblacion I (Bayan Luma)","Poblacion II (Bayan Luma)","Poblacion III (Bayan Luma)",
      "Poblacion IV (Bayan Luma)","Poblacion V (Bayan Luma)" 
    ],
    "General Trias": [
      "Alingaro","Arnaldo","Bacao I","Bacao II","Bagumbayan","Biclatan","Buenavista I","Buenavista II","Buenavista III","Corregidor","Dulong Bayan","Governor Ferrer North","Governor Ferrer South",
      "Javalera","Manggahan","Navarro","Panungyanan","Pasong Camachile I","Pasong Camachile II","Pasong Kawayan I","Pasong Kawayan II","Pinagtipunan","Prinza","Sampalucan","San Francisco",
      "San Gabriel","San Juan I","San Juan II","Santa Clara","Santiago","Tapia","Tejero","Vibora"
    ],
    "Imus": [
      "Alapan I-A","Alapan I-B","Alapan I-C","Alapan II-A","Alapan II-B","Alapan II-C","Anabu I-A","Anabu I-B","Anabu I-C","Anabu I-D","Anabu I-E","Anabu I-F","Anabu I-G","Anabu II-A","Anabu II-B",
      "Anabu II-C","Anabu II-D","Anabu II-E","Anabu II-F","Bagong Silang","Bayan Luma I","Bayan Luma II","Bayan Luma III","Bayan Luma IV","Bayan Luma V","Bayan Luma VI","Bayan Luma VII",
      "Bayan Luma VIII","Bayan Luma IX","Buhay na Tubig","Carsadang Bago I","Carsadang Bago II","Magdalo","Maharlika","Malagasang I-A","Malagasang I-B","Malagasang II-A","Malagasang II-B",
      "Malagasang II-C","Malagasang II-D","Malagasang II-E","Malagasang II-F","Malagasang II-G","Malagasang II-H","Medicion I-A","Medicion I-B","Medicion I-C","Medicion I-D","Medicion I-E",
      "Medicion II-A","Medicion II-B","Medicion II-C","Pag-asa I","Pag-asa II","Pag-asa III","Palico I","Palico II","Palico III","Palico IV","Pasong Buaya I","Pasong Buaya II","Poblacion I-A",
      "Poblacion I-B","Poblacion I-C","Poblacion II-A","Poblacion II-B","Poblacion III-A","Poblacion III-B","Poblacion III-C","Poblacion III-D","Tanzang Luma I","Tanzang Luma II","Tanzang Luma III",
      "Tanzang Luma IV","Tanzang Luma V","Tanzang Luma VI","Tanzang Luma VII","Toclong I-A","Toclong I-B","Toclong II-A","Toclong II-B"
    ],
    "Indang": [ 
      "Agus-os","Alulod","Banaba Cerca","Banaba Lejos","Bancod","Buna Lejos I","Buna Lejos II","Buna Cerca","Calumpang Cerca","Calumpang Lejos I","Calumpang Lejos II", 
      "Carasuchi","Daine I","Daine II","Guyam Malaki","Guyam Munti","Harasan","Kayquit I","Kayquit II","Kayquit III","Kaytapos","Limbon","Lumampong Balagbag","Lumampong Halayhay", 
      "Mahabang Kahoy Cerca","Mahabang Kahoy Lejos","Mahabang Kahoy Paño","Malabanan","Mendez Crossing","Poblacion I","Poblacion II","Poblacion III","Poblacion IV" 
    ],
    "Kawit": [ 
      "Balibago I","Balibago II","Balsahan-Bisita","Batong Dalig","Binakayan-Aplaya","Binakayan-Kanluran","Congbalay-Legaspi","Gahak","Kaingen","Magdalo","Marulas","Panamitan",
      "Poblacion","Pulvorista","San Sebastian","Santa Isabel","Tabon I","Tabon II","Tabon III","Toclong" 
    ],
    "Magallanes": [ 
      "Baliwag","Banilad","Bendita I","Bendita II","Caluangan","Kabulusan","Medina","Pacheco","Pangil","Poblacion I","Poblacion II",
      "Poblacion III","Poblacion IV","Poblacion V","San Agustin","Tua" 
    ],
    "Maragondon": [ 
      "Bucal I","Bucal II","Caingin","Garita I","Garita II","Layong Mabilog","Mabato","Pantihan I","Pantihan II","Pantihan III","Pantihan IV","Pinagsanhan I","Pinagsanhan II",
      "Poblacion I","Poblacion II","Poblacion III","San Miguel I","San Miguel II","Tulay Kanluran","Tulay Silangan" 
    ],
    "Mendez": [ 
      "Anuling Lejos I","Anuling Lejos II","Anuling Lejos III","Asis I","Asis II","Banaybanay","Bukal","Galicia I","Galicia II","Galicia III","Miguel Mojica","Palocpoc I",
      "Palocpoc II","Poblacion I","Poblacion II","Poblacion III","Poblacion IV" 
    ],
    "Naic": [ 
      "Bagong Kalsada","Bagong Silang","Balsahan","Barangay I (Pob.)","Barangay II (Pob.)","Barangay III (Pob.)","Barangay IV (Pob.)","Barangay V (Pob.)","Barangay VI (Pob.)","Barangay VII (Pob.)",
      "Barangay VIII (Pob.)", "Bucana Malaki","Bucana Sasahan","Calubcob","Capt. C. Nazareno","Halang","Humbac","Ibayo Estacion","Ibayo Silangan","Kanluran","Labac","Mabulo","Makina","Malainen Bago",
      "Malainen Luma","Molino","Munting Mapino","Munting Mapino Ibaba", "Palangue I","Palangue II","Palangue III","Sabang","San Roque","Santulan","Sapa","Tabon","Timalan Balsahan","Timalan Concepcion" 
    ],
    "Noveleta": [ 
      "Magdiwang","Poblacion","Salcedo I","Salcedo II","San Antonio I","San Antonio II","San Jose I","San Jose II","San Rafael I","San Rafael II","Santa Rosa I","Santa Rosa II" 
    ],
    "Rosario": [ 
      "Bagbag I","Bagbag II","Kanluran","Ligtong I","Ligtong II","Ligtong III","Ligtong IV","Muzon I","Muzon II","Sapa I","Sapa II","Sapa III","Sapa IV","Silangan I",
      "Silangan II","Wawa I","Wawa II","Wawa III","Wawa IV" 
    ],
    "Silang": [
      "Adlas","Anahaw I","Anahaw II","Balite I","Balite II","Balite III","Balubad I","Balubad II","Banaba","Biga I","Biga II","Buho","Bulihan","Carmen I","Carmen II","Hoyo","Iba",
      "Inchican","Ipil I","Ipil II","Kalubkob","Kaong","Lalaan I","Lalaan II","Litlit","Lucsuhin","Lumil","Maguyam","Malabag","Mataas na Burol I","Mataas na Burol II","Mataas na Burol III",
      "Munting Ilog","Paligawan","Pasong Langka","Pooc I","Pooc II","Pulong Bunga","Pulong Saging","Puting Kahoy","Sabutan","San Miguel I","San Miguel II","San Vicente I","San Vicente II",
      "Santol","Santo Domingo","Tartaria","Tibig","Toledo"
    ],
    "Tagaytay": [
      "Asisan","Bagong Tubig","Calabuso","Dapdap East","Dapdap West","Francisco","Guinhawa North","Guinhawa South","Iruhin Central","Iruhin East","Iruhin West","Kaybagal Central",
      "Kaybagal North","Kaybagal South","Mag-asawang Ilat","Maharlika East","Maharlika West","Maitim II Central","Maitim II East","Maitim II West","Mendez Crossing East","Mendez Crossing West",
      "Neogan","Patutong Malaki North","Patutong Malaki South","Sambong","San Jose","Silang Crossing East","Silang Crossing West","Sungay East","Sungay West","Tolentino East","Tolentino West","Zambal"
    ],
    "Tanza": [ 
      "Amaya I","Amaya II","Amaya III","Amaya IV","Amaya V","Amaya VI","Amaya VII","Amaya VIII","Amaya IX","Amaya X","Amaya XI","Bagtas","Biga","Bucal","Calibuyo","Capipisa East","Capipisa West",
      "Daang Amaya I","Daang Amaya II","Daang Amaya III","Halayhay","Julugan I","Julugan II","Julugan III","Julugan IV","Julugan V","Julugan VI","Julugan VII","Julugan VIII","Julugan IX","Mulawin",
      "Paradahan I","Paradahan II","Poblacion I","Poblacion II","Poblacion III","Poblacion IV","Sanja Mayor","Santol","Tanauan" 
    ],
    "Ternate": [ 
      "Bucana","San Jose I","San Jose II","San Juan I","San Juan II","San Juan III","San Juan IV","San Juan V","San Juan VI","San Juan VII","San Juan VIII","San Juan IX","San Juan X",
      "San Juan XI","San Juan XII","San Juan XIII","San Juan XIV","San Juan XV" 
    ],
    "Trece Martires": [
      "Aguado","Cabezas","Cabuco","Conchu","De Ocampo","Gregorio","Inocencio","Lallana","Lapidario","Luciano","Osorio","Perez","San Agustin"]
  };

  // Populate cities dropdown
  const citySelect = document.getElementById("city");
  Object.keys(barangaysByCity).forEach(c => {
    let option = document.createElement("option");
    option.value = c; option.textContent = c;
    citySelect.appendChild(option);
  });

  // Update barangays based on selected city
  function updateBarangay() {
    const barangaySelect = document.getElementById("barangay");
    barangaySelect.innerHTML = '<option value="">Select Barangay</option>';
    const selectedCity = citySelect.value;
    if (barangaysByCity[selectedCity]) {
      barangaysByCity[selectedCity].forEach(brgy => {
        let option = document.createElement("option");
        option.value = brgy; option.textContent = brgy;
        barangaySelect.appendChild(option);
      });
    }
  }
  window.updateBarangay = updateBarangay;

  // Set DOB max (18+ only)
  window.addEventListener('DOMContentLoaded', () => {
    const dobInput = document.getElementById('dob');
    const today = new Date();
    const maxDate = new Date(today.getFullYear() - 18, today.getMonth(), today.getDate());
    const year = maxDate.getFullYear();
    const month = String(maxDate.getMonth() + 1).padStart(2, '0');
    const day = String(maxDate.getDate()).padStart(2, '0');
    const formattedMaxDate = `${year}-${month}-${day}`;
    dobInput.max = formattedMaxDate;
  });

  /* ===== Persist form values across back/forward navigation ===== */
  const FORM_KEY = 'signup_personal_form_v1';

  function getFormData() {
    const form = document.querySelector('form');
    return {
      fname: form.querySelector('[name="fname"]')?.value || '',
      lname: form.querySelector('[name="lname"]')?.value || '',
      province: form.querySelector('[name="province"]')?.value || '',
      city: document.getElementById('city')?.value || '',
      barangay: document.getElementById('barangay')?.value || '',
      civil: document.getElementById('civil')?.value || '',
      gender: document.querySelector('[name="gender"]')?.value || '',
      dob: document.getElementById('dob')?.value || ''
    };
  }
  function setFormData(data) {
    if (!data) return;
    const form = document.querySelector('form');
    if (form.querySelector('[name="fname"]')) form.querySelector('[name="fname"]').value = data.fname || '';
    if (form.querySelector('[name="lname"]')) form.querySelector('[name="lname"]').value = data.lname || '';
    if (form.querySelector('[name="province"]')) form.querySelector('[name="province"]').value = data.province || '';

    const citySel = document.getElementById('city');
    const brgySel = document.getElementById('barangay');
    if (citySel) {
      citySel.value = data.city || '';
      if (typeof updateBarangay === 'function') updateBarangay();
    }
    if (brgySel) brgySel.value = data.barangay || '';

    const civilSel = document.getElementById('civil');
    if (civilSel) civilSel.value = data.civil || '';

    const genderSel = document.querySelector('[name="gender"]');
    if (genderSel) genderSel.value = data.gender || '';

    const dobInput = document.getElementById('dob');
    if (dobInput) dobInput.value = data.dob || '';
  }
  function wireAutosave() {
    const form = document.querySelector('form');
    form.addEventListener('input', () => {
      sessionStorage.setItem(FORM_KEY, JSON.stringify(getFormData()));
    });
    form.addEventListener('change', () => {
      sessionStorage.setItem(FORM_KEY, JSON.stringify(getFormData()));
    });
  }
  window.addEventListener('pageshow', (e) => {
    const nav = performance.getEntriesByType('navigation')[0];
    const isReload = nav && nav.type === 'reload';

    // Restore only if coming from BFCache (back/forward), not reload or fresh nav
    if (e.persisted || (!nav && e.persisted)) {
      const saved = sessionStorage.getItem(FORM_KEY);
      if (saved) setFormData(JSON.parse(saved));
    } else if (!isReload) {
      // direct nav: leave empty (fresh form)
    }
  });

  window.addEventListener('beforeunload', () => {
    sessionStorage.setItem(FORM_KEY, JSON.stringify(getFormData()));
  });
  document.querySelector('form').addEventListener('submit', () => {
    sessionStorage.removeItem(FORM_KEY);
  });
  function goBackAndSave() {
    sessionStorage.setItem(FORM_KEY, JSON.stringify(getFormData()));
    history.back();
  }
  window.goBackAndSave = goBackAndSave;

  // Initialize autosave
  wireAutosave();
  </script>
</body>
</html>
