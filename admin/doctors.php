<?php

session_name('sess_admin'); session_start();

if (isset($_SESSION["user"])) {
    if (($_SESSION["user"]) == "" || $_SESSION['usertype'] != 'a') {
        header("location: ../login.php");
        exit(); // ✅
    } else {
        $useremail = $_SESSION["user"];
    }
} else {
    header("location: ../login.php");
    exit(); // ✅
}

// Import database
include("../connection.php");

function render_modal(string $title, string $bodyHtml, string $actionsHtml = '', string $size = 'md'): void {
    $widthClass = ['sm'=>'modal-card--sm','md'=>'modal-card--md','lg'=>'modal-card--lg'][$size] ?? 'modal-card--md';

    echo '
    <script>openModalEffects && openModalEffects();</script>
    <div class="overlay overlay--open" id="popup1" role="dialog" aria-modal="true" aria-label="'.htmlspecialchars($title).'">
      <div class="modal-card '.$widthClass.'">
        <button class="modal-close" onclick="closeModalEffects(); window.location.href=\'doctors.php\'" aria-label="Close">&times;</button>
        <h2 class="modal-title">'.$title.'</h2>
        <div class="modal-body">'.$bodyHtml.'</div>'.
        ($actionsHtml !== '' ? '<div class="modal-actions">'.$actionsHtml.'</div>' : '').
      '</div>
    </div>';
}

// default so it exists even if query fails
$canAddDoctor = true;
$adminSpecialty = '';
$isInfoAdmin = false;
$adminSpecId = '';

try {
    $reference = $database
        ->getReference('admin')
        ->orderByChild('email')
        ->equalTo($useremail)
        ->getSnapshot();
    $userfetch = $reference->getValue();
    if ($userfetch) {
        foreach ($userfetch as $key => $value) {
            $username = $value['name'];
            if (isset($value['sname'])) {
                if (is_array($value['sname'])) {
                    $specialty = implode(", ", $value['sname']);
                } else {
                    $specialty = $value['sname'];
                }
            } else {
                $specialty = 'N/A';
            }
            $adminSpecialty = trim((string)$specialty);
            $_SESSION['specialty'] = $adminSpecialty;
            $adminSpecialty = isset($_SESSION['specialty']) ? trim((string)$_SESSION['specialty']) : '';
            $isInfoAdmin = (strcasecmp($adminSpecialty, 'information') === 0); 

            $adminSpecId = isset($value['spec_id']) ? $value['spec_id'] : ''; 
        }
    } else {
        echo "No user found.";
    }
} catch (\Kreait\Firebase\Exception\DatabaseException $e) {
    echo "Error querying the database: " . $e->getMessage();
}

/* =========================
   BLOCKED SPECIALTIES: cannot add new doctor
   ========================= */
$blockedSpecialties = [
    'Industrial Clinic',
    'Clinical Laboratory',
    'Radiology',
    'Cardio Pulmonology',
    'Blood Bank',
    'Molecular Pathology Laboratory',
    'Physical Therapy',
    'Rehabilitation Medicine Specialist',
    'Pharmacy',
    'HMO',
    'Cashier',
    'PhilHealth',
    'Information', // ✅ also block Information
];

$canAddDoctor = true;
$adminSpecLower = mb_strtolower($adminSpecialty);
foreach ($blockedSpecialties as $blocked) {
    if ($adminSpecLower === mb_strtolower($blocked)) {
        $canAddDoctor = false;
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST["id"] ?? null;
    $action = $_POST["action"] ?? null;

    if ($isInfoAdmin && in_array($action, ['edit','drop'], true)) {
        $nameget = $_POST["name"] ?? "Unknown";
        $title = 'Are you sure?';
        $body = '
            <p>You want to delete this record<br><strong>(' . htmlspecialchars(substr($nameget, 0, 40)) . ')</strong>.</p>
        ';
        $actions = '
            <form action="delete-doctor.php" method="POST" style="display:inline;">
                <input type="hidden" name="id" value="' . htmlspecialchars((string)$id, ENT_QUOTES, 'UTF-8') . '">
                <button type="submit" class="btn-primary btn">Yes</button>
            </form>
            <a href="doctors.php" class="non-style-link">
                <button type="button" class="btn-primary-soft btn">No</button>
            </a>
        ';
        render_modal($title, $body, $actions, 'sm');

    } elseif ($action == 'view') {
        $doctorRef = $database->getReference('doctor/' . $id);
        $doctor = $doctorRef->getValue();

        if ($doctor) {
            $fname        = htmlspecialchars($doctor['fname'] ?? '');
            $lname        = htmlspecialchars($doctor['lname'] ?? '');
            $email        = htmlspecialchars($doctor['email'] ?? '');
            $tele         = htmlspecialchars($doctor['tele']  ?? '');
            $specialty_id = $doctor['specialty'] ?? '';

            $specialtyRef  = $database->getReference('specialties/' . $specialty_id);
            $specialtyData = $specialtyRef->getValue();
            $spcil_name    = $specialtyData['sname'] ?? ($doctor['sname'] ?? 'Unknown');
            $spcil_name    = htmlspecialchars($spcil_name);

            $body = '
                <table width="100%" class="sub-table" border="0" style="border-spacing: 8px; ">
                    <tr><td><strong>First Name:</strong> '.$fname.'</td></tr>
                    <tr><td><strong>Last Name:</strong> '.$lname.'</td></tr>
                    <tr><td><strong>Email:</strong> '.$email.'</td></tr>
                    <tr><td><strong>Telephone:</strong> '.$tele.'</td></tr>
                    <tr><td><strong>Specialty:</strong> '.$spcil_name.'</td></tr>
                </table>
            ';
            $actions = '
                <a href="doctors.php">
                    <button type="button" class="btn-primary btn">OK</button>
                </a>
            ';
            render_modal('Doctor Details', $body, $actions, 'sm');
        } else {
            echo "Record not found or invalid ID.";
        }

    } elseif ($action == 'add') {

        // ✅ HARD BLOCK: this admin is not allowed to add doctors
        if (!$canAddDoctor) {
            $body = '
                <p style="margin:0;">
                    Your department (<strong>' . htmlspecialchars($adminSpecialty, ENT_QUOTES, 'UTF-8') . '</strong>)
                    is not allowed to add new doctor accounts on this page.
                </p>
            ';
            $actions = '
                <a href="doctors.php">
                    <button type="button" class="btn-primary btn">OK</button>
                </a>
            ';
            render_modal('Action not allowed', $body, $actions, 'sm');

        } else {

            $error_1 = $_POST["error"] ?? '0';
            $errorlist = [
                '1' => '<label class="form-label" style="color:rgb(255, 62, 62);"> An account already exists for this doctor (email or username).</label>',
                '2' => '<label class="form-label" style="color:rgb(255, 62, 62);">Password Confirmation Error! Reconfirm Password</label>',
                '3' => '<label class="form-label" style="color:rgb(255, 62, 62);">Some other error message here</label>',
                '0' => ''
            ];

            if ($error_1 != '4') {
                ob_start();
                ?>
                <form action="add-new.php" method="POST" class="add-new-form">
                    <div style="margin-bottom:10px;text-align:center;">
                        <?= $errorlist[$error_1] ?>
                    </div>
                    <table width="100%" class="sub-table" border="0" style="border-spacing: 10px;">
                        <tr>
                            <td style="width:30%;"><strong>Enter First Name:</strong></td>
                            <td><input type="text" name="fname" required style="width:100%;"></td>
                        </tr>
                        <tr>
                            <td><strong>Enter Last Name:</strong></td>
                            <td><input type="text" name="lname" required style="width:100%;"></td>
                        </tr>
                        <tr>
                            <td>
                                <strong>Username:</strong>
                            </td>
                            <td>
                                <input
                                    type="text" name="username" required style="width:100%;" autocomplete="off" placeholder="Doctor username">
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Telephone:</strong></td>
                            <td><input type="tel" name="tele" required style="width:100%;"></td>
                        </tr>
                        <tr>
                            <td><strong>Specialty:</strong></td>
                            <td>
                                <?php
                                if (isset($specialty) && !empty($specialty)) {
                                    ?>
                                    <select class="box" style="width:100%;" disabled>
                                        <option value="<?= htmlspecialchars($specialty, ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($specialty, ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    </select>
                                    <input type="hidden" name="spec" value="<?= htmlspecialchars($specialty, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="admin_spec_id" value="<?= htmlspecialchars($adminSpecId, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="admin_spec_sname" value="<?= htmlspecialchars($specialty, ENT_QUOTES, 'UTF-8') ?>">
                                    <?php
                                } else {
                                    echo '<select name="spec" class="box" style="width:100%;" required>
                                            <option value="" disabled selected>Select Specialty</option>';
                                    $specialties = $database->getReference('specialties')->getValue();
                                    if ($specialties) {
                                        foreach ($specialties as $key => $spec) {
                                            $sname = htmlspecialchars($spec['sname']);
                                            echo "<option value=\"$key\">$sname</option>";
                                        }
                                    }
                                    echo '</select>';
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Password:</strong></td>
                            <td>
                                <div class="input-with-icon">
                                    <input
                                        type="password"
                                        id="edit_password"
                                        name="password"
                                        placeholder="Enter new password if needed"
                                        style="width:100%;"
                                        autocomplete="new-password"
                                    >
                                    <button type="button" class="pw-toggle" data-target="edit_password" aria-label="Show password">
                                        <i class="fa fa-eye"></i>
                                    </button>
                                </div>

                                <!-- Live rules; will hide when all pass -->
                                <ul id="pw_rules" class="pw-rules">
                                    <li data-rule="len">At least 8 characters long</li>
                                    <li data-rule="up">Contains one uppercase letter (A-Z)</li>
                                    <li data-rule="low">Contains one lowercase letter (a-z)</li>
                                    <li data-rule="num">Contains one number (0-9)</li>
                                    <li data-rule="sp">Contains one special character (!@#$%^&*.,)</li>
                                </ul>
                            </td>
                        </tr>

                        <tr>
                            <td><strong>Confirm Password:</strong></td>
                            <td>
                                <div class="input-with-icon">
                                    <input
                                        type="password"
                                        id="edit_cpassword"
                                        name="cpassword"
                                        placeholder="Re-enter new password"
                                        style="width:100%;"
                                        autocomplete="new-password"
                                    >
                                    <button type="button" class="pw-toggle" data-target="edit_cpassword" aria-label="Show password">
                                        <i class="fa fa-eye"></i>
                                    </button>
                                </div>
                                <small id="cpw_msg" class="cpw-msg" style="display:none;">Passwords do not match.</small>
                            </td>
                        </tr>
                    </table>
                <?php
                $body = ob_get_clean();

                $actions = '
                    <button type="reset" class="btn-primary-soft btn">Reset</button>
                    <button type="submit" id="add_save_btn" class="btn-primary btn">Add</button>
                ';

                // Re-open the output buffer to wrap form + actions
                ob_start();
                echo $body;
                echo '<div class="modal-actions">'.$actions.'</div>';
                $wrapped = ob_get_clean();

                render_modal('Add Doctor', $wrapped, '', 'md');
            }
        }
    } elseif ($action == 'edit') {
        if ($id) {
            $doctorRef = $database->getReference("doctor/$id");
            $doctor = $doctorRef->getValue();

            if ($doctor) {
                $name         = htmlspecialchars($doctor["name"] ?? '');
                $email        = htmlspecialchars($doctor["email"] ?? '');
                $tele         = htmlspecialchars($doctor["tele"]  ?? '');
                $specialty_id = $doctor["specialty"] ?? '';

                ob_start();
                ?>
                <form action="edit-doc.php" method="POST" class="add-new-form" onsubmit="return confirmSave()">
                    <input type="hidden" name="id00" value="<?= htmlspecialchars((string)$id, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="oldemail" value="<?= $email ?>">
                    <table width="100%" class="sub-table" border="0" style="border-spacing:10px;">
                        <tr>
                            <td style="width:30%;"><strong>Email:</strong></td>
                            <td><input type="email" name="email" value="<?= $email ?>" style="width:100%;" required></td>
                        </tr>
                        <tr>
                            <td><strong>Password:</strong></td>
                            <td>
                                <div class="input-with-icon">
                                    <input
                                        type="password"
                                        id="edit_password"
                                        name="password"
                                        placeholder="Enter new password if needed"
                                        style="width:100%;"
                                        autocomplete="new-password"
                                    >
                                    <button type="button" class="pw-toggle" data-target="edit_password" aria-label="Show password">
                                        <i class="fa fa-eye"></i>
                                    </button>
                                </div>

                                <!-- Live rules; will hide when all pass -->
                                <ul id="pw_rules" class="pw-rules">
                                    <li data-rule="len">At least 8 characters long</li>
                                    <li data-rule="up">Contains one uppercase letter (A-Z)</li>
                                    <li data-rule="low">Contains one lowercase letter (a-z)</li>
                                    <li data-rule="num">Contains one number (0-9)</li>
                                    <li data-rule="sp">Contains one special character (!@#$%^&*.,)</li>
                                </ul>
                            </td>
                        </tr>

                        <tr>
                            <td><strong>Confirm Password:</strong></td>
                            <td>
                                <div class="input-with-icon">
                                    <input
                                        type="password"
                                        id="edit_cpassword"
                                        name="cpassword"
                                        placeholder="Re-enter new password"
                                        style="width:100%;"
                                        autocomplete="new-password"
                                    >
                                    <button type="button" class="pw-toggle" data-target="edit_cpassword" aria-label="Show password">
                                        <i class="fa fa-eye"></i>
                                    </button>
                                </div>
                                <small id="cpw_msg" class="cpw-msg" style="display:none;">Passwords do not match.</small>
                            </td>
                        </tr>

                        <tr>
                            <td><strong>Name:</strong></td>
                            <td><input type="text" name="name" value="<?= $name ?>" style="width:100%;" required></td>
                        </tr>
                        <tr>
                            <td><strong>Telephone:</strong></td>
                            <td><input type="tel" name="tele" value="<?= $tele ?>" style="width:100%;" required></td>
                        </tr>
                    </table>    
                <?php
                $body = ob_get_clean();

                $actions = '
                    <button type="submit" id="edit_save_btn" class="btn-primary btn">Save</button>
                    <a href="doctors.php"><button type="button" class="btn-primary-soft btn">Cancel</button></a>
                ';

                ob_start();
                echo $body;
                echo '<div class="modal-actions">'.$actions.'</div>';
                $wrapped = ob_get_clean();

                render_modal('Edit Doctor', $wrapped, '', 'md');
            } else {
                echo "Record not found or invalid ID.";
            }
        } else {
            echo "ID is missing.";
        }
    }
}

// Fetch admin data again for photo/name
$adminRef = $database->getReference('admin')->orderByChild('email')->equalTo($useremail);
$adminData = $adminRef->getValue();

if ($adminData) {
    $adminData = array_shift($adminData); 
    $username = isset($adminData['name']) ? $adminData['name'] : null;
} 
$photo = '../img/user.png'; 
if ($adminData) {
    $admin = reset($adminData); 
    if (!empty($admin['photo'])) {
        $photo = $admin['photo']; 
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- <link rel="stylesheet" href="../css/animations.css">   -->
    <link rel="stylesheet" href="../css/main.css">  
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="../css/hamburger.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
        
        
    <title>Doctors</title>
<style>
/* ===== MODAL / OVERLAY ===== */
.overlay{
  position:fixed; inset:0; z-index:9999;
  display:none; align-items:center; justify-content:center;
  padding:24px;
}
.overlay.overlay--open{display:flex;}

.overlay::before{
  content:"";
  position:fixed; inset:0; z-index:0;
  background:rgba(0,0,0,.45);
  backdrop-filter:blur(6px) saturate(110%);
  -webkit-backdrop-filter:blur(6px) saturate(110%);
}

.modal-card{
  position:relative; z-index:1;
  width:100%; max-height:85vh; overflow:hidden;
  border-radius:16px; background:#fff; border:1px solid #e7e7e7;
  box-shadow:0 12px 36px rgba(0,0,0,.22);
  display:flex; flex-direction:column;
}
.modal-card--sm{max-width:440px;}
.modal-card--md{max-width:720px;}
.modal-card--lg{max-width:1040px;}

.modal-title{
  margin:0; padding:18px 56px 18px 24px;
  font:700 20px/1.2 system-ui; color:#113a2b;
  border-bottom:1px solid #eef3ef;
}
.modal-body{padding:18px 24px; overflow:auto;}
.modal-actions{
  display:flex; gap:10px; justify-content:flex-end;
  padding:16px 20px; border-top:1px solid #eef3ef; background:#fafdfb;
}
.modal-close{
  position:absolute; right:10px; top:10px;
  width:38px; height:38px; border:none; border-radius:999px;
  background:transparent; font-size:26px; color:#2e2e2e; cursor:pointer;
}
.modal-close:hover{background:#f2f6f3;}

/* ===== FORM INPUTS ===== */
.add-new-form input[type="text"],
.add-new-form input[type="email"],
.add-new-form input[type="tel"],
.add-new-form input[type="password"],
.add-new-form select{
  width:100%;
  padding:10px 12px;
  font-size:15px;
  border:1px solid #ccc;
  border-radius:6px;
  height:42px;
  box-sizing:border-box;
}

/* ===== PAGE PUSH BACK (when modal open) ===== */
body.modal-open .container{
  filter:blur(1px) contrast(.98) brightness(.98);
  transform:scale(.995);
  transition:filter .2s ease, transform .2s ease;
  pointer-events:none;
}
@media (prefers-reduced-motion: reduce){
  body.modal-open .container{transition:none;}
}

/* ===== PASSWORD UI ===== */
.input-with-icon{position:relative;}
.input-with-icon input{padding-right:42px;}
.pw-toggle{
  position:absolute; right:6px; top:50%; transform:translateY(-50%);
  height:30px; width:34px; border:0; border-radius:8px;
  background:transparent; cursor:pointer; color:#2e2e2e;
}
.pw-toggle:hover{background:#f2f6f3;}

.pw-rules{margin:6px 0 0; padding-left:18px; font-size:12px; color:#666;}
.pw-rules li{margin:2px 0; list-style:disc;}
.pw-rules li.ok{color:#0abf58;}
.pw-rules li.bad{color:#c0392b;}
.pw-rules.hidden{display:none;}
.cpw-msg{color:#c0392b; font-size:12px; margin-top:4px; display:block;}

/* ===== MOBILE HEADER + DRAWER ===== */
.mobile-header{display:none;}
#menu-overlay{display:none;}

@media (min-width:769px){
  .mobile-header{display:none;}
}

@media (max-width:768px){

  /* fixed header */
  .mobile-header{
    display:flex; position:fixed; top:0; left:0; right:0;
    height:56px; background:lightgreen; z-index:1200;
    align-items:center; justify-content:space-between;
    padding:0 10px; box-shadow:0 2px 10px rgba(0,0,0,.12);
  }
  .mobile-left{width:44px; display:flex; align-items:center;}
  .mobile-center{flex:1; text-align:center; font-weight:700; font-size:16px; color:#000;}
  .mobile-right{display:flex; align-items:center; gap:10px;}
  .mobile-date{font-size:13px; font-weight:600; color:#000; white-space:nowrap;}
  .mobile-calendar-btn{
    background:rgba(255, 255, 255, 0);
    border:none; width:34px; height:34px; border-radius:8px;
    display:flex; align-items:center; justify-content:center; cursor:pointer;
  }
  .mobile-calendar-btn img{width:18px; height:18px;}

  /* hamburger */
            #hamburger-menu{
                display:flex !important;
                width:34px !important;
                height:34px !important;
                padding:8px !important;
                background:rgba(255, 255, 255, 0.48) !important;
                border-radius:10px !important;
                flex-direction:column;
                justify-content:center;
                gap:5px;
            }
             #hamburger-menu .bar{
    height:2px !important;
    width:100% !important;
    background:#111 !important;
    border-radius:2px;
    transition:.25s;
  }
  #hamburger-menu.active .bar:nth-child(1){transform:rotate(-45deg) translate(-4px,5px);}
  #hamburger-menu.active .bar:nth-child(2){opacity:0;}
  #hamburger-menu.active .bar:nth-child(3){transform:rotate(45deg) translate(-4px,-5px);}

  /* overlay + drawer */
  #menu-overlay{
    display:none; position:fixed; inset:0;
    background:rgba(0,0,0,.45); z-index:1100;
  }
  body.menu-open #menu-overlay{display:block;}
  .menu{
    position:fixed; top:56px; left:0; width:270px;
    height:calc(100vh - 56px);
    background:lightgreen;
    transform:translateX(-100%);
    transition:transform .25s ease;
    z-index:1150;
    overflow-y:auto; -webkit-overflow-scrolling:touch;
    display:block;
  }
  body.menu-open .menu{transform:translateX(0);}
  body.menu-open{overflow:hidden;}

  /* maximize spaces */
  .dash-body{
    margin-top:66px !important;
    margin-left:0 !important;
    padding:12px !important;
    width:100% !important;
  }

  /* hide desktop header items inside top table */
  .btn-icon-back,
  td[width="15%"],
  td[width="10%"],
  .btn-label{
    display:none !important;
  }
  .dash-body table[width="100%"] tr:first-child{
    gap:0 !important; margin:0 !important; padding:0 !important;
  }

  /* top controls stack */
  .dash-body table[width="100%"] tr{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    align-items:center;
  }
  .dash-body table[width="100%"] tr td{
    width:100% !important;
    padding:0 !important;
    margin:0 !important;
  }

  /* full width controls */
  .dash-body a .btn-icon-back{width:100% !important; margin-left:0 !important;}
  .header-search{width:100%; padding:0 0 8px 0;}
  .header-searchbar{width:100% !important;}
  .login-btn.button-icon{
    margin-left:0 !important;
    width:100% !important;
    justify-content:center;
  }

  /* table container full width */
  .abc.scroll, .sub-table{width:100% !important;}

  /* table -> cards */
  .sub-table thead{display:none !important;}
  .sub-table tbody tr{
    text-align:left !important;
    display:grid !important;
    grid-template-columns:1fr auto;
    grid-template-rows:auto auto auto;
    gap:4px 12px;
    padding:12px !important;
    border:1px solid #ddd;
    border-radius:12px;
    background:#fff;
    margin-bottom:12px;
  }
  .sub-table tbody td{
    display:block !important;
    padding:0 !important;
    border:none !important;
    margin:0 !important;
    text-align:left !important;
  }
  .sub-table tbody tr td:nth-child(1){grid-column:1; grid-row:1; font-weight:800;}
  .sub-table tbody tr td:nth-child(2){grid-column:1; grid-row:2; font-size:13px;}
  .sub-table tbody tr td:nth-child(3){grid-column:1; grid-row:3; font-size:13px;}

  /* actions */
  .sub-table tbody tr td:nth-child(4){
    grid-column:2;
    grid-row:1 / span 3;
    align-self:center;
    justify-self:end;
  }
  .actions-row{
    display:flex !important;
    flex-wrap:wrap;
    gap:8px;
    justify-content:flex-end;
  }
  .actions-row form{margin:0;}

  /* view button fixes */
  .sub-table tbody tr .btn-view{
    background-image:none !important;
    padding:10px 14px !important;
    min-width:88px;
    display:inline-flex !important;
    align-items:center;
    justify-content:center;
    gap:6px;
    font-weight:700;
    white-space:nowrap;
    line-height:1;
    border-radius:10px !important;
  }
  .sub-table tbody tr .btn-view i{display:inline-block !important;}
  .sub-table tbody tr .btn-view .fas{font-size:16px;}

  /* optional: hide edit/delete on mobile */
  .sub-table tbody tr .btn-edit,
  .sub-table tbody tr .btn-delete{
    display:none !important;
  }

  /* modal width on mobile */
  .modal-card{max-height:86vh;}
  .modal-card--md, .modal-card--lg, .modal-card--sm{max-width:96vw !important;}
}

/* DESKTOP: remove tiled background icon completely */
@media (min-width: 769px){
  .btn-view{
    background-image: none !important;
  }
}

@media (max-width: 768px){
  .menu .profile-subtitle{
    font-size: 13px !important;
  }
}


</style>


</head>
<body>
<?php date_default_timezone_set('Asia/Manila'); $todayHeader = date('Y-m-d'); ?>

<!-- Mobile Header -->
<div class="mobile-header">
    <div class="mobile-left">
        <div id="hamburger-menu" aria-label="Open menu" role="button" tabindex="0">
            <div class="bar"></div>
            <div class="bar"></div>
            <div class="bar"></div>
        </div>
    </div>

    <div class="mobile-center">Doctors</div>

    <div class="mobile-right">
        <div class="mobile-date"><?php echo $todayHeader; ?></div>
        <button class="mobile-calendar-btn" type="button" aria-label="Calendar">
            <img src="../img/calendar.svg" alt="">
        </button>
    </div>
</div>

<!-- overlay (tap outside to close) -->
<div id="menu-overlay"></div>
    <div class="container">
        <div class="menu">
            <table class="menu-container" border="0">
                <tr>
                    <td style="padding:10px" colspan="2">
                        <table border="0" class="profile-container">
                            <tr>
                                <td width="30%" style="padding-left:20px">
                                    <img src="<?php echo htmlspecialchars($photo); ?>" alt="Profile Picture" width="100%" style="border-radius:50%">
                                </td>
                                <td style="padding:0px;margin:0px;">
                                    <p class="profile-title"><?php echo substr($username,0,50) ?></p>
                                    <p class="profile-subtitle"><?php echo substr($useremail,0,50)  ?></p>
                                    <p class="profile-subtitle" style="margin-top: 10px">
                                        (<?php 
                                            if (isset($adminSpecialty)) {
                                                if (is_array($adminSpecialty)) {
                                                    echo substr(implode(', ', $adminSpecialty), 0, 50);
                                                } else {
                                                    echo substr($adminSpecialty, 0, 50);
                                                }
                                            } else {
                                                echo ' ';
                                            }
                                        ?>)
                                    </p>
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
                        <a href="index.php" class="non-style-link-menu">
                            <p class="menu-text">Dashboard</p>
                        </a>
                    </td>
                </tr>
                <tr class="menu-row">
                    <td class="menu-btn menu-active">
                        <a href="doctors.php" class="non-style-link-menu non-style-link-menu-active">
                            <p class="menu-text">Doctors</p>
                        </a>
                    </td>
                </tr>
                <tr class="menu-row">
                    <td class="menu-btn ">
                        <a href="schedule.php" class="non-style-link-menu">
                            <p class="menu-text">Schedule</p>
                        </a>
                    </td>
                </tr>
                <tr class="menu-row">
                    <td class="menu-btn ">
                        <a href="bed.php" class="non-style-link-menu ">
                            <p class="menu-text">Bed Occupancy</p>
                        </a>
                    </td>
                </tr>
                <tr class="menu-row">
                    <td class="menu-btn ">
                        <a href="queing.php" class="non-style-link-menu ">
                            <p class="menu-text">Queue</p>
                        </a>
                    </td>
                </tr>
                <tr class="menu-row">
                    <td class="menu-btn ">
                        <a href="patient.php" class="non-style-link-menu">
                            <p class="menu-text">Patients Health Record</p>
                        </a>
                    </td>
                </tr>
                <tr class="menu-row">
                    <td class="menu-btn ">
                        <a href="archive.php" class="non-style-link-menu">
                            <p class="menu-text">Archives</p>
                        </a>
                    </td>
                </tr>
                <tr class="menu-row">
                    <td class="menu-btn ">
                        <a href="summary.php" class="non-style-link-menu">
                            <p class="menu-text">Summary</p>
                        </a>
                    </td>
                </tr>
                <tr class="menu-row">
                    <td class="menu-btn ">
                        <a href="settings.php" class="non-style-link-menu">
                            <p class="menu-text">Settings</p>
                        </a>
                    </td>
                </tr> 
            </table>
        </div>
        <div class="dash-body">
            <table border="0" width="100%" style="border-spacing: 0; margin:0; padding:0; margin-top:25px;">
                <tr>
                    <td width="13%">
                        <a href="doctors.php">
                            <button class="login-btn btn-primary-soft btn btn-icon-back"
                                    style="padding-top:11px; padding-bottom:11px; margin-left:20px; width:125px">
                                <font class="tn-in-text">Back</font>
                            </button>
                        </a>
                    </td>
                    <td>
                        <form action="doctors.php" method="get" class="header-search">
                            <input type="search" name="search" class="input-text header-searchbar" 
                                placeholder="Search Doctor Name or Email" list="doctors" 
                                value="<?php echo htmlspecialchars($_GET["search"] ?? ''); ?>">&nbsp;&nbsp;

                            <?php
                                $doctorsReference = $database->getReference('doctor');
                                $doctorsSnapshot = $doctorsReference->getSnapshot();
                                $doctorsData = $doctorsSnapshot->getValue();

                                echo '<datalist id="doctors">';
                                if ($doctorsData) {
                                    foreach ($doctorsData as $doctor) {
                                        $docName = isset($doctor['name']) ? htmlspecialchars($doctor['name']) : '';
                                        $docEmail = isset($doctor['email']) ? htmlspecialchars($doctor['email']) : '';

                                        if (!empty($docName)) {
                                            echo "<option value='$docName'>";
                                        }
                                        if (!empty($docEmail)) {
                                            echo "<option value='$docEmail'>";
                                        }
                                    }
                                }
                                echo '</datalist>';
                            ?>
                        </form>  
                    <td width="15%">
                        <p style="font-size: 14px; color: rgb(119, 119, 119); padding: 0; margin: 0; text-align: right;">
                            Today's Date
                        </p>
                        <p class="heading-sub12" style="padding: 0; margin: 0;">
                            <?php
                            date_default_timezone_set('Asia/Manila');
                            echo date('Y-m-d');
                            ?>
                        </p>
                    </td>
                    <td width="10%">
                        <button class="btn-label" style="display: flex; justify-content: center; align-items: center;">
                            <img src="../img/calendar.svg" width="100%">
                        </button>
                    </td>
                </tr>
                <tr >
                    <td colspan="2" style="padding-top:30px;">
                        <p class="heading-main12" style="margin-left: 45px;font-size:20px;color:rgb(49, 49, 49)"></p>
                    </td>
                    <td colspan="2">
                        <?php if (!empty($canAddDoctor) && $canAddDoctor): ?>
                            <form action="doctors.php" method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="add">
                                <input type="hidden" name="id" value="none">
                                <input type="hidden" name="error" value="0">
                                <button class="login-btn btn-primary btn button-icon" 
                                        style="display: flex; justify-content: center; align-items: center; margin-left: 75px; background-image: url('../img/icons/add.svg');">
                                    Add New
                                </button>
                            </form>
                        <?php else: ?>
                            <button class="login-btn btn-primary-soft btn button-icon" 
                                    style="display: flex; justify-content: center; align-items: center; margin-left: 75px; cursor:not-allowed; opacity:.6;"
                                    disabled>
                                Cannot add doctors for this department
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td colspan="4" style="padding-top:10px;">
                        <p class="heading-main12" style="margin-left: 45px; font-size:18px; color:rgb(49, 49, 49)">
                            All Doctors (<?php
                                // Retrieve the logged-in admin's specialty from the session.
                                $adminSpecialty = isset($_SESSION['specialty']) ? trim($_SESSION['specialty']) : '';
                                $showAll = (strtolower($adminSpecialty) === 'information');

                                $doctorsReference = $database->getReference('doctor');
                                $doctorsSnapshot = $doctorsReference->getSnapshot();
                                $doctorsData = $doctorsSnapshot->getValue();

                                // ==== SPECIALTIES MAPS (id -> name) ====
                                $specTable = $database->getReference('specialties')->getValue() ?: [];
                                $SPEC_ID_TO_NAME = [];
                                foreach ($specTable as $sid => $row) {
                                    $nm = trim((string)($row['sname'] ?? ''));
                                    if ($nm !== '') $SPEC_ID_TO_NAME[$sid] = $nm;
                                }

                                function doctorSpecialtyNormalized(array $doctor, array $id2name): array {
                                    $vals = [];

                                    $collect = function($v) use (&$vals, $id2name) {
                                        if (is_array($v)) {
                                            foreach ($v as $one) {
                                                $one = trim((string)$one);
                                                if ($one === '') continue;
                                                $vals[] = $id2name[$one] ?? $one; // map id->name else keep text
                                            }
                                        } else {
                                            $one = trim((string)$v);
                                            if ($one !== '') $vals[] = $id2name[$one] ?? $one;
                                        }
                                    };

                                    if (array_key_exists('specialty', $doctor)) $collect($doctor['specialty']);
                                    if (array_key_exists('sname', $doctor))     $collect($doctor['sname']);

                                    // de-dupe case-insensitively
                                    $seen = [];
                                    $names = [];
                                    foreach ($vals as $n) {
                                        $k = mb_strtolower($n);
                                        if (!isset($seen[$k])) { $seen[$k] = true; $names[] = $n; }
                                    }

                                    if (!$names) $names = ['Unknown'];

                                    return [
                                        'display' => implode(', ', $names),
                                        'keys'    => array_map('mb_strtolower', $names),
                                    ];
                                }

                                $keyword = trim($_GET["search"] ?? '');
                                $selectedSpecialty = trim($_POST["specialty"] ?? ''); 
                                $filteredDoctors = [];

                                if ($doctorsData && is_array($doctorsData)) {
                                    foreach ($doctorsData as $doctorId => $doctor) {
                                        $docName      = isset($doctor['name']) ? $doctor['name'] : 'Unknown';
                                        $docEmail     = isset($doctor['email']) ? $doctor['email'] : 'Unknown';
                                        $specNorm        = doctorSpecialtyNormalized($doctor, $SPEC_ID_TO_NAME);
                                        $docDisplaySpec  = $specNorm['display'];              // what we will show
                                        $docSpecKeys     = $specNorm['keys'];                 // e.g. ['pediatrics','radiology']

                                        $matchesSearch = empty($keyword)
                                            || stripos($docName,  $keyword) !== false
                                            || stripos($docEmail, $keyword) !== false;

                                        // If you ever add a specialty filter UI, this will support both names and ids
                                        $selKey = mb_strtolower(trim($selectedSpecialty));
                                        $matchesSpecialty = ($selKey === '')
                                            || in_array($selKey, $docSpecKeys, true);

                                        // Admin specialty match: show all for Information; otherwise intersect
                                        $adminSpecs     = is_array($adminSpecialty) ? $adminSpecialty : [$adminSpecialty];
                                        $adminSpecKeys  = array_map(fn($s)=>mb_strtolower(trim((string)$s)), array_filter($adminSpecs, fn($s)=>trim((string)$s) !== ''));
                                        $matchesAdminSpecialty = $showAll
                                            || empty($adminSpecKeys)
                                            || count(array_intersect($adminSpecKeys, $docSpecKeys)) > 0;

                                        if ($matchesSearch && $matchesSpecialty && $matchesAdminSpecialty) {
                                            $filteredDoctors[$doctorId] = [
                                                'name'      => $docName,
                                                'email'     => $docEmail,
                                                'specialty' => $docDisplaySpec,           // ← always a readable name
                                                'sort_key'  => $docSpecKeys[0] ?? 'zzz',  // first normalized specialty for sorting
                                            ];
                                        }

                                    }
                                }
                                $displayedDoctors = count($filteredDoctors);
                                echo $displayedDoctors;
                            ?>)
                        </p>
                    </td>
                </tr>

                <tr>
                    <td colspan="4">
                        <center>
                            <div class="abc scroll">
                                <table width="93%" class="sub-table scrolldown" border="0">
                                    <thead>
                                        <tr>
                                            <th class="table-headin">Doctor Name</th>
                                            <th class="table-headin">Email</th>
                                            <th class="table-headin">Specialty</th>
                                            <th class="table-headin">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        // Sort Doctors by specialty
                                        if (!empty($filteredDoctors)) {
                                            uasort($filteredDoctors, function ($a, $b) {
                                                return strcmp($a['sort_key'] ?? '', $b['sort_key'] ?? '');
                                            });

                                            // Iterate keeping the key as $doctorId (avoid array_search)
                                            foreach ($filteredDoctors as $doctorId => $doctor) {
                                                $docName  = htmlspecialchars($doctor['name'] ?? 'Unknown',  ENT_QUOTES, 'UTF-8');
                                                $docEmail = htmlspecialchars($doctor['email'] ?? 'Unknown', ENT_QUOTES, 'UTF-8');
                                                $docSpec  = htmlspecialchars($doctor['specialty'] ?? 'Unknown', ENT_QUOTES, 'UTF-8');
                                                $docIdEsc = htmlspecialchars((string)$doctorId, ENT_QUOTES, 'UTF-8');

                                                echo '<tr class="doc-row" style="text-align:center; vertical-align:middle; border-bottom:1px solid #ddd;">
                                                        <td data-label="Doctor Name" class="doc-name" style="padding:12px; border-bottom:1px solid #ddd;">'.$docName.'</td>
                                                        <td data-label="Email" class="doc-email" style="padding:12px; border-bottom:1px solid #ddd;">'.$docEmail.'</td>
                                                        <td data-label="Specialty" class="doc-spec" style="padding:12px; border-bottom:1px solid #ddd;">'.$docSpec.'</td>
                                                        <td data-label="Actions" class="doc-actions" style="padding:12px; border-bottom:1px solid #ddd;">
                                                            <div class="actions-row">';


                                                if (!empty($isInfoAdmin) && $isInfoAdmin) {
                                                    // View-only for Information admins
                                                    echo '
                                                    <form action="doctors.php" method="POST" style="display:inline;">
                                                        <input type="hidden" name="action" value="view">
                                                        <input type="hidden" name="id" value="'.$docIdEsc.'">
                                                        <button class="btn-primary-soft btn btn-view" title="View">
                                                            <span class="fas fa-eye"></span> View
                                                            </button>

                                                    </form>';
                                                } else {
                                                    // Full controls (Edit, View, Remove)
                                                    echo '
                                                    <form action="doctors.php" method="POST" style="display:inline;">
                                                        <input type="hidden" name="action" value="edit">
                                                        <input type="hidden" name="id" value="'.$docIdEsc.'">
                                                        <button class="btn-primary-soft btn button-icon btn-edit" title="Edit">
                                                            <i></i> Edit
                                                        </button>
                                                    </form>
                                                    <form action="doctors.php" method="POST" style="display:inline;">
                                                        <input type="hidden" name="action" value="view">
                                                        <input type="hidden" name="id" value="'.$docIdEsc.'">
                                                        <button class="btn-primary-soft btn button-icon btn-view" title="View">
                                                            <i ></i> View
                                                        </button>
                                                    </form>
                                                    <form action="doctors.php" method="POST" style="display:inline;">
                                                        <input type="hidden" name="action" value="drop">
                                                        <input type="hidden" name="id" value="'.$docIdEsc.'">
                                                        <input type="hidden" name="name" value="'.$docName.'">
                                                        <button class="btn-primary-soft btn button-icon btn-delete" title="Remove">
                                                            <i ></i> Remove
                                                        </button>
                                                    </form>';
                                                }

                                                echo      '</div>
                                                        </td>
                                                      </tr>';
                                            }
                                        } else {
                                            echo '<tr>
                                                    <td colspan="4">
                                                        <center>
                                                            <img src="../img/notfound.svg" width="25%">
                                                            <p class="heading-main12">No matching doctors found!</p>
                                                        </center>
                                                    </td>
                                                  </tr>';
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </center>
                    </td>
                </tr>
            </table>
        </div>

        <script>
       document.addEventListener("DOMContentLoaded", () => {
  const hamburgerMenu = document.getElementById('hamburger-menu');
  const overlay = document.getElementById('menu-overlay');

  function openMenu(){
    document.body.classList.add('menu-open');
    hamburgerMenu.classList.add('active');
  }
  function closeMenu(){
    document.body.classList.remove('menu-open');
    hamburgerMenu.classList.remove('active');
  }

  if (hamburgerMenu) {
    hamburgerMenu.addEventListener('click', () => {
      document.body.classList.contains('menu-open') ? closeMenu() : openMenu();
    });

    // keyboard support
    hamburgerMenu.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        document.body.classList.contains('menu-open') ? closeMenu() : openMenu();
      }
    });
  }

  if (overlay) overlay.addEventListener('click', closeMenu);

  // close drawer after clicking any menu link (mobile)
  document.querySelectorAll('.menu a').forEach(link=>{
    link.addEventListener('click', () => {
      if (window.innerWidth <= 768) closeMenu();
    });
});


          // --- Confirm save (used by Edit form) ---
          window.confirmSave = () => confirm("Are you sure you want to save the changes?");

          // --- Modal page-blur helpers (called by PHP-rendered modal markup) ---
          window.openModalEffects  = () => document.body.classList.add('modal-open');
          window.closeModalEffects = () => document.body.classList.remove('modal-open');

          // =============== Password UI & Validation (Add + Edit) ===============
          /**
           * Try to find inputs & elements for whichever modal is present.
           * Supports both EDIT ids and ADD ids (fallbacks).
           */
          function findPwElements() {
            // Inputs (they share same ids in your forms)
            const pw  = document.getElementById('edit_password');
            const cpw = document.getElementById('edit_cpassword');

            // Rules & message (shared in your markup)
            const rulesEl = document.getElementById('pw_rules');
            const cpwMsg  = document.getElementById('cpw_msg');

            // Submit button (works for both modals)
            const saveBtn = document.getElementById('edit_save_btn') || document.getElementById('add_save_btn');

            return { pw, cpw, rulesEl, cpwMsg, saveBtn };
          }

          // Password tests
          const tests = {
            len: s => s.length >= 8,
            up : s => /[A-Z]/.test(s),
            low: s => /[a-z]/.test(s),
            num: s => /[0-9]/.test(s),
            sp : s => /[!@#$%^&*.,]/.test(s),
          };

          function updateRulesDisplay(rulesEl, pwd) {
            if (!rulesEl) return true;
            let allPass = true;

            ['len','up','low','num','sp'].forEach(key => {
              const li = rulesEl.querySelector('li[data-rule="'+key+'"]');
              if (!li) return;
              const pass = tests[key](pwd);
              li.classList.toggle('ok',  pass);
              li.classList.toggle('bad', !pass);
              if (!pass) allPass = false;
            });

            // Hide the rules once all pass (and user started typing)
            rulesEl.classList.toggle('hidden', allPass && pwd.length > 0);
            return allPass;
          }

          function updateConfirmDisplay(cpwMsg, pwd, cpwd) {
            if (!cpwMsg) return true;
            const match = (cpwd.length === 0) || (pwd === cpwd);
            cpwMsg.style.display = match ? 'none' : 'block';
            return match;
          }

          function setDisabled(btn, disabled) {
            if (btn) btn.disabled = !!disabled;
          }

          function updateSaveState() {
            const { pw, cpw, rulesEl, cpwMsg, saveBtn } = findPwElements();
            // If no password fields (e.g., modal not open) — nothing to validate
            if (!pw || !cpw) return;

            const p = pw.value;
            const c = cpw.value;

            // If password is empty, don't block saving (user might not be changing password)
            if (!p) {
              setDisabled(saveBtn, false);
              if (rulesEl) rulesEl.classList.add('hidden');
              if (cpwMsg)  cpwMsg.style.display = 'none';
              return;
            }

            const passRules = updateRulesDisplay(rulesEl, p);
            const passMatch = updateConfirmDisplay(cpwMsg, p, c);
            setDisabled(saveBtn, !(passRules && passMatch));
          }

          // --- Event delegation for eye toggles so it works for any modal content ---
          document.addEventListener('click', (evt) => {
            const btn = evt.target.closest('.pw-toggle');
            if (!btn) return;

            const targetId = btn.getAttribute('data-target');
            if (!targetId) return;

            const target = document.getElementById(targetId);
            if (!target) return;

            const isPw = target.type === 'password';
            target.type = isPw ? 'text' : 'password';
            btn.innerHTML = isPw ? '<i class="fa fa-eye-slash"></i>' : '<i class="fa fa-eye"></i>';
            btn.setAttribute('aria-label', isPw ? 'Hide password' : 'Show password');
          });

          // --- Wire up input listeners (runs now and whenever fields might appear) ---
          function wireIfPresent() {
            const { pw, cpw } = findPwElements();
            if (pw && !pw._wired) {
              pw.addEventListener('input', updateSaveState);
              pw._wired = true;
            }
            if (cpw && !cpw._wired) {
              cpw.addEventListener('input', updateSaveState);
              cpw._wired = true;
            }
            // Initial state
            updateSaveState();
          }

          // Run once now
          wireIfPresent();

          // In case modal fields are rendered slightly later
          setTimeout(wireIfPresent, 0);
          setTimeout(wireIfPresent, 150);

        });

        // Clean URL so it ALWAYS shows
        if (window.location.search.length > 0) {
            window.history.replaceState({}, document.title, "doctors.php");
        }

        </script>
    </div>
    <?php
    // Flash alert for all cases (success or error)
    if (isset($_GET['msg'])) {
        $msg   = $_GET['msg'];
        $error = $_GET['error'] ?? '';

        // Success if error=4, otherwise treat as failure
        $type = ($error === '4') ? 'Success' : 'Error';

        // Escape for JS
        $safeMsg = json_encode($msg, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        echo <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function () {
    alert("{$type}: " + {$safeMsg});
    // Clean the URL so the alert doesn't fire again on refresh
    window.location.replace("doctors.php");
});
</script>
HTML;
    }
    ?>


</body>
</html>
