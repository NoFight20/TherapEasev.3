<?php
// import database
include("../connection.php");

// helper: password complexity
function password_is_strong(string $p): bool {
    return strlen($p) >= 8
        && preg_match('/[A-Z]/', $p)         // uppercase
        && preg_match('/[a-z]/', $p)         // lowercase
        && preg_match('/[0-9]/', $p)         // number
        && preg_match('/[!@#$%^&*.,]/', $p); // special
}

$error = '3'; // default: generic error

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Collect & sanitize inputs
        $id        = trim($_POST['id00'] ?? $_POST['id'] ?? '');
        $name      = trim($_POST['name'] ?? '');
        $nic       = trim($_POST['nic'] ?? '');
        $oldemail  = trim($_POST['oldemail'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $tele      = trim($_POST['tele'] ?? '');
        $password  = (string)($_POST['password'] ?? '');
        $cpassword = (string)($_POST['cpassword'] ?? '');

        if ($id === '') {
            // Missing doctor id
            header("location: doctors.php?action=edit&error=3&id=" . urlencode($id));
            exit();
        }

        // Basic email format check
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            header("location: doctors.php?action=edit&error=6&id=" . urlencode($id));
            exit();
        }

        // If a new password is provided, enforce rules + confirm match
        $updatePassword = ($password !== '');
        if ($updatePassword) {
            if (!password_is_strong($password)) {
                header("location: doctors.php?action=edit&error=5&id=" . urlencode($id));
                exit();
            }
            if ($password !== $cpassword) {
                header("location: doctors.php?action=edit&error=2&id=" . urlencode($id));
                exit();
            }
        }

        // Ensure email is unique across other doctors
        $result = $database->getReference('doctor')
                           ->orderByChild('email')
                           ->equalTo($email)
                           ->getSnapshot();

        if ($result->exists()) {
            // Find found doctor's node key
            $found = $result->getValue();              // array of matches
            $foundKey = array_key_first($found);       // firebase child key
            if ($foundKey !== $id) {
                // The email belongs to a different doctor
                header("location: doctors.php?action=edit&error=1&id=" . urlencode($id));
                exit();
            }
        }

        // Build update payload (do not overwrite password if not changing)
        $doctorData = [
            'email'     => $email,
            'name'      => $name,
            'nic'       => $nic,
            'tele'      => $tele,        
        ];
        if ($updatePassword) {
            // NOTE: Consider hashing before storing in DB
            $doctorData['password'] = $password;
        }

        // Update doctor record
        $database->getReference('doctor/' . $id)->update($doctorData);

        // If email changed, move the users/{email} node
        if ($oldemail !== '' && strcasecmp($oldemail, $email) !== 0) {
            // remove old user mapping if exists
            $database->getReference('users/' . $oldemail)->remove();
            // set new mapping
            $database->getReference('users/' . $email)->set(['usertype' => 'd']);
        } else {
            // Ensure mapping exists (idempotent)
            $database->getReference('users/' . $email)->update(['usertype' => 'd']);
        }

        $error = '4'; // success
    }
} catch (Throwable $e) {
    // Log $e->getMessage() if you have logging
    $error = '3';
}

// Redirect with outcome
$idParam = urlencode($_POST['id00'] ?? $_POST['id'] ?? '');
header("location: doctors.php?action=edit&error={$error}&id={$idParam}");
exit();
