<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log.txt');

session_name('sess_it'); 
session_start();

// include your firebase connection
include("../connection.php");

date_default_timezone_set('Asia/Manila');
$today = date('Y-m-d');

// session check
if (isset($_SESSION["user"])) {
    if ($_SESSION["user"] === "" || ($_SESSION['usertype'] ?? '') !== 'it') {
        header("Location: ../login.php");
        exit;
    } else {
        $useremail = $_SESSION["user"];
    }
} else {
    header("Location: ../login.php");
    exit;
}
 try {
        $reference = $database
            ->getReference('it')
            ->orderByChild('email')  
            ->equalTo($useremail)
            ->getSnapshot();

        // Fetch the first result
        $userfetch = $reference->getValue();

        if ($userfetch) {
            // Since Firebase returns an associative array, we take the first item
            foreach($userfetch as $key => $value) {
                $username = $value['name'];
            }
        } else {
            // Handle the case where no user is found
            echo "No user found.";
        }

    } catch (\Kreait\Firebase\Exception\DatabaseException $e) {
        echo "Error querying the database: " . $e->getMessage();
    }

function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
foreach ($rooms as $room) {
    $room['beds_count'] = count($beds[$room['id']] ?? []);
}

// ---------------- POST handlers (AJAX) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];

    // Log helper
    function addLog($database, $email, $status, $type) {
        $database->getReference('logs')->push([
            'datestamp' => date('Y-m-d'),
            'email'     => $email,
            'status'    => $status,
            'timestamp' => date('H:i:s'),
            'type'      => $type
        ]);
    }

    try {
        if ($action === 'save_home_title') {
            $title = $_POST['home_title'] ?? '';
            $database->getReference('site/home')->update([
                'title'     => $title,
                'edited_by' => $useremail,
                'edited_at' => date('Y-m-d H:i:s'),
            ]);
            addLog($database, $useremail, "Updated Home: title", "update");
            echo json_encode(['status'=>'success','message'=>'Home title saved.']); exit;
        }

        if ($action === 'save_mission') {
            $mission = $_POST['mission'] ?? '';
            $database->getReference('site/home')->update([
                'mission'   => $mission,
                'edited_by' => $useremail,
                'edited_at' => date('Y-m-d H:i:s'),
            ]);
            addLog($database, $useremail, "Updated Home: mission", "update");
            echo json_encode(['status'=>'success','message'=>'Mission saved.']); exit;
        }

        if ($action === 'save_vision') {
            $vision = $_POST['vision'] ?? '';
            $database->getReference('site/home')->update([
                'vision'    => $vision,
                'edited_by' => $useremail,
                'edited_at' => date('Y-m-d H:i:s'),
            ]);
            addLog($database, $useremail, "Updated Home: vision", "update");
            echo json_encode(['status'=>'success','message'=>'Vision saved.']); exit;
        }

        if ($action === 'save_services') {
            $services = $_POST['services'] ?? '';
            $database->getReference('site/home')->update([
                'services'  => $services,
                'edited_by' => $useremail,
                'edited_at' => date('Y-m-d H:i:s'),
            ]);
            addLog($database, $useremail, "Updated Home: services", "update");
            echo json_encode(['status'=>'success','message'=>'Services saved.']); exit;
        }

       // SAVE ANNOUNCEMENTS 
if ($action === 'save_announcement_patient') {
    $patient = trim($_POST['announcement_patient'] ?? '');

    $livePath    = 'site/announcements';           
    $archivePath = 'archive_announcements';   

    $current = $database->getReference($livePath)->getValue();
    $oldText     = '';
    $oldEditedBy = null;
    $oldEditedAt = null;

    if (is_array($current)) {
        $oldText     = (string)($current['announcement'] ?? '');
        $oldEditedBy = $current['edited_by'] ?? null;
        $oldEditedAt = $current['edited_at'] ?? null;
    } elseif (is_string($current)) {
        $oldText = $current;
    }
    if ($oldText !== '' && $oldText !== $patient) {
        $archiveData = [
            'announcement' => $oldText,
            'edited_by'    => $oldEditedBy,
            'edited_at'    => $oldEditedAt,
            'archived_at'  => date('Y-m-d H:i:s'),
            'archived_by'  => $useremail,
        ];
      
        $archiveData = array_filter($archiveData, fn($v) => !(is_null($v) || $v === ''));
        $database->getReference($archivePath)->push($archiveData);
    }

    $database->getReference($livePath)->set([
        'announcement' => $patient,
        'edited_by'    => $useremail,
        'edited_at'    => date('Y-m-d H:i:s'),
    ]);

    addLog($database, $useremail, "Updated announcement: $patient", "update");
    echo json_encode(['status' => 'success', 'message' => 'Announcement saved.']);
    exit;
}


        // SAVE CONTACT
        if ($action === 'save_contact') {
            $database->getReference('site/contact')->update([
                'email' => $_POST['contact_email'] ?? '',
                'dept_emergency' => $_POST['dept_emergency'] ?? '',
                'dept_outpatient' => $_POST['dept_outpatient'] ?? '',
                'dept_customer' => $_POST['dept_customer'] ?? '',
                'dept_admitting' => $_POST['dept_admitting'] ?? '',
                'dept_radiology' => $_POST['dept_radiology'] ?? '',
                'dept_cardiopulmo' => $_POST['dept_cardiopulmo'] ?? '',
                'dept_laboratory' => $_POST['dept_laboratory'] ?? '',
                'dept_molecular' => $_POST['dept_molecular'] ?? '',
                'address' => $_POST['contact_address'] ?? '',
                'edited_by' => $useremail,
                'edited_at' => date('Y-m-d H:i:s'),
            ]);
            addLog($database, $useremail, "Updated contact information", "update");
            echo json_encode(['status'=>'success','message'=>'Contact info saved.']);
            exit;
        }

        // UPLOAD BANNER
        if ($action === 'save_banner') {
            if (!isset($_FILES['banner']) || $_FILES['banner']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['status'=>'error','message'=>'No file uploaded or upload error.']); exit;
            }
            $tmp = $_FILES['banner']['tmp_name'];
            $name = $_FILES['banner']['name'];
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $newName = 'banner_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;

            $bucket = $storage->getBucket();
            $bucket->upload(file_get_contents($tmp), ['name' => "banners/{$newName}"]);
            $object = $bucket->object("banners/{$newName}");
            $object->update(['acl' => []], ['predefinedAcl' => 'PUBLICREAD']);
            $publicUrl = "https://storage.googleapis.com/" . $bucket->name() . "/banners/{$newName}";
            $database->getReference('site/banner')->update([
              'url' => $publicUrl, 
              'uploaded_by'=>$useremail,
              'uploaded_at'=>date('Y-m-d H:i:s')
            ]);

            addLog($database, $useremail, "Uploaded new banner", "upload");
            echo json_encode([
              'status'=>'success',
              'message'=>'Banner uploaded.',
              'url'=>$publicUrl
            ]);
            exit;
        }

        // ADD ROOM
        if ($action === 'add_room') {
            $number_of_rooms = (int)($_POST['number_of_rooms'] ?? 0);
            $room_department = $_POST['room_department'] ?? '';
            $room_type = $_POST['room_type'] ?? '';
            if (!$room_department || !$room_type || $number_of_rooms <= 0) {
                echo json_encode([
                  'status'=>'error',
                  'message'=>'Invalid input.'
                ]); 
                exit;
            }
            $roomsSnapshot = $database->getReference("rooms/$room_department")->getValue();
            $existingRoomNumbers = [];
            if ($roomsSnapshot) {
                foreach ($roomsSnapshot as $k=>$r) $existingRoomNumbers[] = (int)($r['room_number'] ?? 0);
            }
            
            $startingRoomNumber = count($existingRoomNumbers) > 0 ? max($existingRoomNumbers) + 1 : 1;
            $default_beds = ['private'=>1,'semi-private'=>0,'shared'=>0,'isolation'=>1];
            for ($i=0;$i<$number_of_rooms;$i++){
                $roomNumber = $startingRoomNumber + $i;
                $roomKey = "$room_department-$roomNumber";
                $beds = ($default_beds[$room_type] > 0) ? [['bed_number'=>1,'status'=>'available','department'=>$room_department]] : [];
                $database->getReference("rooms/$room_department/$roomKey")->set([
                    'room_number'=>$roomNumber,
                    'type'=>$room_type,
                    'department'=>$room_department,
                    'beds'=>$beds
                ]);
            }

            addLog($database, $useremail, "Added $number_of_rooms room(s) to $room_department", "add");
            echo json_encode([
              'status'=>'success',
              'message'=>"Added $number_of_rooms room(s)."
            ]);
            exit;
        }

        // ADD BEDS
        if ($action === 'add_bed') {
            $room_id = $_POST['room_id'] ?? '';
            $room_department = $_POST['room_department'] ?? '';
            $number_of_beds = (int)($_POST['number_of_beds'] ?? 0);
            if (!$room_id || !$room_department || $number_of_beds <= 0) {
                echo json_encode([
                  'status'=>'error',
                  'message'=>'Invalid input.'
                ]); 
                exit;
            }
            $roomRef = $database->getReference("rooms/$room_department/$room_id");
            $room = $roomRef->getValue();
            if (!$room) { echo json_encode(['status'=>'error','message'=>'Room not found.']); exit; }
            $current_beds = is_array($room['beds'] ?? null) ? $room['beds'] : [];
            $bed_limits = ['private'=>1,'semi-private'=>2,'shared'=>6,'isolation'=>1];
            $max_beds = $bed_limits[$room['type']] ?? 6;
            if (count($current_beds) + $number_of_beds > $max_beds) {
                echo json_encode([
                  'status'=>'error',
                  'message'=>"Cannot add $number_of_beds beds. Max for ".$room['type']." is $max_beds."
                ]); 
                exit;
            }
            $existing_bed_numbers = array_map(fn($b) => (int)($b['bed_number'] ?? 0), $current_beds);
            sort($existing_bed_numbers);

            $new_beds = [];
            $next = 1;
            for ($i = 0; $i < $number_of_beds; $i++) {
                while (in_array($next, $existing_bed_numbers)) {
                    $next++;
                }
                $new_beds[] = ['bed_number' => $next, 'status' => 'available', 'department' => $room_department];
                $existing_bed_numbers[] = $next;
                sort($existing_bed_numbers); 
            }

            $updated = array_merge($current_beds, $new_beds);
            $roomRef->update(['beds'=>$updated]);
            addLog($database, $useremail, "Added $number_of_beds bed(s) to $room_id in $room_department", "add");
            echo json_encode([
              'status'=>'success',
              'message'=>"Added $number_of_beds bed(s)."
            ]);
            exit;
        }

        // REMOVE BED
                if ($action === 'remove_bed') {
            $room_id = $_POST['room_id'] ?? '';
            $bed_number = (int)($_POST['bed_number'] ?? 0);
            $department = $_POST['department_id'] ?? '';
            if (!$room_id || !$bed_number || !$department) { echo json_encode(['status'=>'error','message'=>'Invalid input.']); exit; }
            $roomRef = $database->getReference("rooms/$department/$room_id");
            $room = $roomRef->getValue();
            if (!$room || !is_array($room['beds'] ?? null)) { echo json_encode(['status'=>'error','message'=>'Room/bed not found.']); exit; }
            $updated = array_values(array_filter($room['beds'], fn($b)=> ((int)$b['bed_number'] !== $bed_number)));
            $roomRef->update(['beds'=>$updated]);
            addLog($database, $useremail, "Removed bed $bed_number in $room_id", "delete");
            echo json_encode([
              'status'=>'success',
              'message'=>'Bed removed.'
            ]);
            exit;
        }

        // REMOVE ROOM
        if ($action === 'remove_room') {
            $room_id = $_POST['room_id'] ?? '';
            $department = $_POST['department_id'] ?? '';
            if (!$room_id || !$department) { echo json_encode(['status'=>'error','message'=>'Invalid input.']); exit; }
            $roomRef = $database->getReference("rooms/$department/$room_id");
            $room = $roomRef->getValue();
            if (!$room) { echo json_encode(['status'=>'error','message'=>'Room not found.']); exit; }
            $roomRef->remove();
            addLog($database, $useremail, "Removed room $room_id from $department", "delete");
            echo json_encode([
              'status'=>'success',
              'message'=>'Room removed.'
            ]);
            exit;
        }

      if ($action === 'save_specialty') {
    $sname = trim($_POST['specialty_name'] ?? '');
    if ($sname === '') {
        echo json_encode(['status'=>'error','message'=>'Name required.']);
        exit;
    }

    // Check if specialty with same name already exists 
    $specialtiesRef = $database->getReference('specialties');
    $specialties = $specialtiesRef->getValue();

    if ($specialties) {
        foreach ($specialties as $existing) {
            if (strcasecmp($existing['sname'], $sname) === 0) {
                echo json_encode(['status' => 'error', 'message' => 'Specialty already exists.']);
                exit;
            }
        }
    }

    // Create a new reference 
    $ref = $specialtiesRef->push();
    $key = $ref->getKey();

    // Now set the data using the generated key
    $ref->set([
        'sname' => $sname,
        'created_by' => $useremail,
        'created_at' => date('Y-m-d H:i:s'),
        'id' => $key
    ]);

    // Log and respond
    addLog($database, $useremail, "Added specialty '$sname'", "add");

    echo json_encode([
        'status' => 'success',
        'message' => 'Specialty added.',
        'id' => $key
    ]);
    exit;
}


        // DELETE SPECIALTIES
        if ($action === 'delete_specialties') {
            $ids = $_POST['specialties'] ?? [];
            if (!is_array($ids) || count($ids) === 0) { echo json_encode(['status'=>'error','message'=>'No specialties selected.']); exit; }
            foreach ($ids as $id) $database->getReference("specialties/$id")->remove();
            addLog($database, $useremail, "Deleted ".count($ids)." specialties", "delete");
            echo json_encode([
              'status'=>'success',
              'message'=>'Selected specialties deleted.'
            ]);
            exit;
        }

        // UPLOAD BACKGROUND
        if ($action === 'upload_background') {
            if (!isset($_FILES['background_image']) || $_FILES['background_image']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['status'=>'error','message'=>'No file uploaded.']); exit;
            }
            $page = $_POST['page'] ?? 'login';
            $tmp = $_FILES['background_image']['tmp_name'];
            $name = $_FILES['background_image']['name'];
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $newName = 'background_'.$page.'_'.time().'_'.bin2hex(random_bytes(6)).'.'.$ext;
            $bucket = $storage->getBucket();
            $bucket->upload(file_get_contents($tmp), ['name'=>"backgrounds/{$newName}"]);
            $object = $bucket->object("backgrounds/{$newName}");
            $object->update(['acl'=>[]], ['predefinedAcl'=>'PUBLICREAD']);
            $publicUrl = "https://storage.googleapis.com/".$bucket->name()."/backgrounds/{$newName}";
            $database->getReference("backgrounds/{$page}")->update([
              'url'=>$publicUrl,
              'uploaded_by'=>$useremail,
              'uploaded_at'=>date('Y-m-d H:i:s')
            ]);
            addLog($database, $useremail, "Uploaded background for page '$page'", "upload");
            echo json_encode([
              'status'=>'success',
              'message'=>'Background uploaded.',
              'url'=>$publicUrl
            ]);
            exit;
        }

        // UPLOAD LOGO
      if ($action === 'upload_logo') {
      if (!isset($_FILES['logo_image']) || $_FILES['logo_image']['error'] !== UPLOAD_ERR_OK) {
          echo json_encode(['status' => 'error', 'message' => 'No logo uploaded.']); exit;
      }

      $tmp = $_FILES['logo_image']['tmp_name'];
      $name = $_FILES['logo_image']['name'];
      $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
      $newName = 'logo_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;

      $bucket = $storage->getBucket();
      $bucket->upload(file_get_contents($tmp), ['name' => "logos/{$newName}"]);

      $object = $bucket->object("logos/{$newName}");
      $object->update(['acl' => []], ['predefinedAcl' => 'PUBLICREAD']);

      $publicUrl = "https://storage.googleapis.com/" . $bucket->name() . "/logos/{$newName}";

      $timestamp = date('Y-m-d H:i:s');

      // Update main logo reference
      $database->getReference('site')->update([
          'logo' => $publicUrl,
          'logo_uploaded_by' => $useremail,
          'logo_uploaded_at' => $timestamp
      ]);

      // Add a new node in `logos_history`
      $database->getReference('site/logos_history')->push([
          'url' => $publicUrl,
          'uploaded_by' => $useremail,
          'uploaded_at' => $timestamp,
          'filename' => $newName
      ]);

      addLog($database, $useremail, "Uploaded site logo", "upload");

      echo json_encode([
          'status' => 'success',
          'message' => 'Logo uploaded.',
          'url' => $publicUrl
      ]);
      exit;
  }


        //UPLOAD TEMPLATE
      if ($action === 'upload_template') {
    if (!isset($_FILES['template_file']) || $_FILES['template_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status'=>'error','message'=>'No file uploaded or upload error.']);
        exit;
    }

    $tmp = $_FILES['template_file']['tmp_name'];
    $name = $_FILES['template_file']['name'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    $allowed_exts = ['doc', 'docx', 'pdf', 'jpg', 'jpeg', 'png'];
    if (!in_array($ext, $allowed_exts)) {
        echo json_encode(['status'=>'error','message'=>'Only DOC, DOCX, and PDF files are allowed.']);
        exit;
    }

    $date = date('Ymd'); 
    $newName = 'health_record_template_' . $date . '.' . $ext;
    $mime_types = [
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png'
    ];

    $contentType = $mime_types[$ext] ?? 'application/octet-stream';

    try {
        $bucket = $storage->getBucket();
        if (!$bucket) {
            echo json_encode(['status'=>'error', 'message'=>'Storage bucket not found']);
            exit;
        }

        $templatesRef = $database->getReference('site/templates');
        $templatesSnapshot = $templatesRef->getSnapshot();
        $templatesData = $templatesSnapshot->getValue();

        if (!empty($templatesData)) {
            foreach ($templatesData as $key => $template) {
                if (isset($template['url'])) {
                    
                    $url = $template['url'];
                    $parsed = parse_url($url);
                    $path = $parsed['path'] ?? '';

                 
                    $path = ltrim($path, '/');

              
                    $object = $bucket->object($path);
                    if ($object->exists()) {
                        $object->delete();
                    }

                    $database->getReference('site/templates/' . $key)->remove();
                }
            }
        }

        $uploadResponse = $bucket->upload(
            fopen($tmp, 'r'),
            [
                'name' => "templates/{$newName}",
                'metadata' => ['contentType' => $contentType]
            ]
        );

        if (!$uploadResponse) {
            echo json_encode(['status'=>'error', 'message'=>'Upload to storage failed']);
            exit;
        }

        $object = $bucket->object("templates/{$newName}");
        $object->update([], ['predefinedAcl' => 'PUBLICREAD']);

        $publicUrl = "https://storage.googleapis.com/" . $bucket->name() . "/templates/{$newName}";


        $ref = $database->getReference('site/templates')->push();
        if (!$ref) {
            echo json_encode(['status'=>'error', 'message'=>'Failed to get database reference']);
            exit;
        }

        $ref->set([
            'url' => $publicUrl,
            'uploaded_by' => $useremail,
            'uploaded_at' => date('Y-m-d H:i:s')
        ]);

        addLog($database, $useremail, "Uploaded new template", "upload");

        echo json_encode([
            'status' => 'success',
            'message' => 'Template uploaded.',
            'url' => $publicUrl
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Upload failed: ' . $e->getMessage()
        ]);
    }
    exit;
}

// ADS
if ($action === 'ads_upload') {
    if (!isset($_FILES['ad_file']) || $_FILES['ad_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status'=>'error','message'=>'No media uploaded.']); exit;
    }

    $href = trim($_POST['ad_href'] ?? '');
    $alt  = trim($_POST['ad_alt'] ?? '');

    $tmp  = $_FILES['ad_file']['tmp_name'];
    $name = $_FILES['ad_file']['name'];
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    // Whitelist for images & videos
    $image_exts = ['jpg','jpeg','png','webp','gif'];
    $video_exts = ['mp4','webm','ogg'];
    $allowed_exts = array_merge($image_exts, $video_exts);

    if (!in_array($ext, $allowed_exts, true)) {
        echo json_encode(['status'=>'error','message'=>'Only images (jpg,jpeg,png,webp,gif) or videos (mp4,webm,ogg) are allowed.']); exit;
    }

    $isVideo = in_array($ext, $video_exts, true);
    $prefix  = $isVideo ? 'adv_' : 'ad_';
    $newName = $prefix . time() . '_' . bin2hex(random_bytes(5)) . '.' . $ext;

    // Guess content type
    $mimeMap = [
        'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif',
        'mp4'=>'video/mp4','webm'=>'video/webm','ogg'=>'video/ogg'
    ];
    $contentType = $mimeMap[$ext] ?? ($isVideo ? 'video/*' : 'image/*');

    $bucket = $storage->getBucket();
    $bucket->upload(
        fopen($tmp, 'r'),
        ['name' => "ads/{$newName}", 'metadata' => ['contentType' => $contentType]]
    );

    $object = $bucket->object("ads/{$newName}");
    $object->update([], ['predefinedAcl' => 'PUBLICREAD']);
    $publicUrl = "https://storage.googleapis.com/".$bucket->name()."/ads/{$newName}";

    // Determine next order
    $items = $database->getReference('site/ads/items')->getValue() ?: [];
    $nextOrder = 1;
    if ($items) {
        $orders = [];
        foreach ($items as $it) { $orders[] = (int)($it['order'] ?? 0); }
        $nextOrder = (max($orders) + 1);
    }

    $payload = [
        'href'        => $href,
        'alt'         => $alt,
        'active'      => true,
        'order'       => $nextOrder,
        'uploaded_by' => $useremail,
        'uploaded_at' => date('Y-m-d H:i:s'),
        'storage_path'=> "ads/{$newName}",
        'media_type'  => $isVideo ? 'video' : 'image'
    ];
    if ($isVideo) { $payload['video_url'] = $publicUrl; }
    else          { $payload['image_url'] = $publicUrl; }

    $ref = $database->getReference('site/ads/items')->push();
    $ref->set($payload);

    addLog($database, $useremail, "Uploaded ad {$newName} (".$payload['media_type'].")", "upload");
    echo json_encode(['status'=>'success','message'=>'Ad uploaded.','url'=>$publicUrl, 'media_type'=>$payload['media_type']]); 
    exit;
}


// ==== ADS: save config (interval + enabled) ====
if ($action === 'ads_save_config') {
    $interval = max(1, (int)($_POST['interval_sec'] ?? 12));
    $enabled  = ($_POST['enabled'] ?? 'true') === 'true';
    $database->getReference('site/ads/config')->update([
        'interval_sec' => $interval,
        'enabled'      => $enabled,
        'edited_by'    => $useremail,
        'edited_at'    => date('Y-m-d H:i:s'),
    ]);
    addLog($database, $useremail, "Updated ads config interval={$interval} enabled=".($enabled?'1':'0'), "update");
    echo json_encode(['status'=>'success','message'=>'Ads config saved.']); exit;
}

// ==== ADS: save per-item meta (href, alt, active) ====
if ($action === 'ads_save_item') {
    $id   = $_POST['id'] ?? '';
    if ($id === '') { echo json_encode(['status'=>'error','message'=>'Missing ad id']); exit; }
    $href = trim($_POST['href'] ?? '');
    $alt  = trim($_POST['alt'] ?? '');
    $active = ($_POST['active'] ?? 'true') === 'true';

    $database->getReference("site/ads/items/{$id}")->update([
        'href'   => $href,
        'alt'    => $alt,
        'active' => $active,
        'edited_by' => $useremail,
        'edited_at' => date('Y-m-d H:i:s'),
    ]);
    addLog($database, $useremail, "Updated ad meta id={$id}", "update");
    echo json_encode(['status'=>'success','message'=>'Ad updated.']); exit;
}

// ==== ADS: delete ad (db + storage) ====
if ($action === 'ads_delete') {
    $id = $_POST['id'] ?? '';
    if ($id === '') { echo json_encode(['status'=>'error','message'=>'Missing ad id']); exit; }
    $item = $database->getReference("site/ads/items/{$id}")->getValue();
    if ($item) {
        $path = $item['storage_path'] ?? '';
        if ($path) {
            try {
                $bucket = $storage->getBucket();
                $obj = $bucket->object($path);
                if ($obj->exists()) $obj->delete();
            } catch (Exception $e) { /* ignore */ }
        }
        $database->getReference("site/ads/items/{$id}")->remove();
    }
    addLog($database, $useremail, "Deleted ad id={$id}", "delete");
    echo json_encode(['status'=>'success','message'=>'Ad deleted.']); exit;
}

// ==== ADS: reorder items ====
if ($action === 'ads_reorder') {
    // Expect order[]=<id>&order[]=<id>...
    $order = $_POST['order'] ?? [];
    if (!is_array($order) || !count($order)) {
        echo json_encode(['status'=>'error','message'=>'No order received']); exit;
    }
    $i = 1;
    foreach ($order as $id) {
        $database->getReference("site/ads/items/{$id}/order")->set($i++);
    }
    addLog($database, $useremail, "Reordered ads", "update");
    echo json_encode(['status'=>'success','message'=>'Order saved.']); exit;
}


    } catch (Exception $e) {
        echo json_encode([
          'status'=>'error',
          'message'=>$e->getMessage()
        ]);
        exit;
    }

    echo json_encode([
      'status'=>'error',
      'message'=>'Unknown action'
    ]);
    exit;
}

// ---------------- GET handlers  ----------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'];
    try {
        if ($action === 'get_rooms' && isset($_GET['department'])) {
            $dept = $_GET['department'];
            $rooms = $database->getReference("rooms/$dept")->getValue() ?: [];
            echo json_encode([
              'status'=>'success',
              'rooms'=>$rooms
            ]); 
          exit;
        }
        if ($action === 'get_beds' && isset($_GET['department']) && isset($_GET['room'])) {
            $dept = $_GET['department'];
            $roomId = $_GET['room'];
            $room = $database->getReference("rooms/$dept/$roomId")->getValue();
            $beds = ($room && isset($room['beds']) && is_array($room['beds'])) ? $room['beds'] : [];
            echo json_encode([
              'status'=>'success',
              'beds'=>$beds]); 
              exit;
        }
        if ($action === 'list_specialties') {
            $sp = $database->getReference('specialties')->getValue() ?: [];
            echo json_encode([
              'status'=>'success',
              'specialties'=>$sp
            ]); 
            exit;
        }
        if ($action === 'get_home') {
            $home = $database->getReference('site/home')->getValue() ?: [];
            echo json_encode([
              'status'=>'success',
              'home'=>$home
            ]); 
            exit;
        }
        if ($action === 'get_announcements') {
            $ann = $database->getReference('site/announcements')->getValue() ?: [];
            echo json_encode([
              'status'=>'success',
              'announcements'=>$ann
            ]); 
            exit;
        }
        if ($action === 'get_contact') {
            $con = $database->getReference('site/contact')->getValue() ?: [];
            echo json_encode([
              'status'=>'success',
              'contact'=>$con
          ]); 
            exit;
        }
        if ($action === 'get_banner') {
            $b = $database->getReference('site/banner')->getValue() ?: [];
            echo json_encode([
            'status'=>'success',
            'banner'=>$b
          ]); 
            exit;
        }
        if ($action === 'get_backgrounds') {
            $bg = $database->getReference('backgrounds')->getValue() ?: [];
            echo json_encode([
              'status'=>'success',
              'backgrounds'=>$bg
            ]); 
            exit;
        }
        if ($action === 'get_logo') {
            $logo = $database->getReference('site')->getValue() ?: [];
            echo json_encode([
              'status'=>'success',
              'site'=>$logo
            ]); 
            exit;
        }

        // list rooms root
        if ($action === 'list_rooms_root') {
            $roomsRoot = $database->getReference('rooms')->getValue() ?: [];
            echo json_encode([
              'status'=>'success',
              'rooms'=>$roomsRoot
            ]); 
            exit;
        }

        // flatten rooms for DataTable
        if ($action === 'list_rooms_flat') {
            $roomsRoot = $database->getReference('rooms')->getValue() ?: [];
            $flat = [];
            foreach ($roomsRoot as $dept => $rooms) {
                if (!is_array($rooms)) continue;
                foreach ($rooms as $key => $r) {
                    $flat[] = [
                        'key'=>$key,
                        'department'=>$dept,
                        'room_number'=>$r['room_number'] ?? '',
                        'type'=>$r['type'] ?? '',
                        'beds_count'=>is_array($r['beds'] ?? null) ? count($r['beds']) : 0
                    ];
                }
            }
            echo json_encode(['status'=>'success','rooms'=>$flat]); exit;
        }

        //TEMPLATE
if ($action === 'get_template') {
    try {
        $ref = $database->getReference('site/templates');
        $templates = $ref->getValue();
        if (!$templates) {
            echo json_encode(['status' => 'success', 'template' => null]);
            exit;
        }
        $latestTemplate = end($templates);

        echo json_encode([
            'status' => 'success',
            'template' => $latestTemplate
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to fetch template: ' . $e->getMessage()
        ]);
    }
    exit;
}
// ==== ADS: list items + config for CMS ====
if ($action === 'ads_list') {
    $items  = $database->getReference('site/ads/items')->getValue() ?: [];
    // normalize to array with ids
    $arr = [];
    foreach ($items as $k => $v) { $arr[] = ['id'=>$k] + (array)$v; }
    // sort by 'order' then uploaded_at
    usort($arr, function($a,$b){
        $oa = (int)($a['order'] ?? 0);
        $ob = (int)($b['order'] ?? 0);
        if ($oa === $ob) {
            return strcmp($a['uploaded_at'] ?? '', $b['uploaded_at'] ?? '');
        }
        return $oa <=> $ob;
    });

    $config = $database->getReference('site/ads/config')->getValue() ?: [];
    $interval = (int)($config['interval_sec'] ?? 12);
    $enabled  = (bool)($config['enabled'] ?? true);

    echo json_encode([
        'status'   => 'success',
        'items'    => $arr,
        'config'   => ['interval_sec'=>$interval, 'enabled'=>$enabled],
    ]); exit;
}

// ==== ADS: public-style JSON  ====
if ($action === 'ads_json') {
    $items  = $database->getReference('site/ads/items')->getValue() ?: [];
    $arr = [];
    foreach ($items as $k => $v) {
        if (!empty($v['active'])) {
            $arr[] = [
                'media_type' => (string)($v['media_type'] ?? (isset($v['video_url']) ? 'video' : 'image')),
                'image_url'  => (string)($v['image_url'] ?? ''),
                'video_url'  => (string)($v['video_url'] ?? ''),
                'href'       => (string)($v['href'] ?? ''),
                'alt'        => (string)($v['alt'] ?? ''),
                'order'      => (int)($v['order'] ?? 0),
            ];
        }
    }
    usort($arr, fn($a,$b) => ($a['order'] <=> $b['order']));

    $config = $database->getReference('site/ads/config')->getValue() ?: [];
    $interval = (int)($config['interval_sec'] ?? 12);
    $enabled  = (bool)($config['enabled'] ?? true);

    echo json_encode([
        'ads'          => array_map(function($x){
            // Keep minimal but include both URLs + media type
            return [
                'media_type' => $x['media_type'],
                'image_url'  => $x['image_url'],
                'video_url'  => $x['video_url'],
                'href'       => $x['href'],
                'alt'        => $x['alt']
            ];
        }, $arr),
        'interval_sec' => $interval,
        'enabled'      => $enabled,
    ]); exit;
}



    } catch (Exception $e) {
        echo json_encode([
          'status'=>'error',
          'message'=>$e->getMessage()
        ]); 
        exit;
    }
    echo json_encode([
      'status'=>'error',
      'message'=>'Unknown action'
    ]); 
    exit;
}
?>

<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CMS</title>
<link rel="stylesheet" href="../css/animations.css">  
    <link rel="stylesheet" href="../css/main.css">  
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">


<style>
/* =========================
   BASE STYLES
   ========================= */

h1, h2 {
  margin: 0 0 12px;
  font-weight: 600;
}

h1 { font-size: 1.8rem; }
h2 { font-size: 1.3rem; color: #374151; }

/* =========================
   FORM ELEMENTS
   ========================= */

label {
  display: block;
  font-weight: 500;
  margin-bottom: 6px;
  color: #374151;
}

input, select, textarea {
  width: 100%;
  padding: 10px 14px;
  border-radius: 8px;
  border: 1px solid #D1D5DB;
  background-color: #fff;
  font-size: 0.95rem;
  transition: border-color 0.3s;
}

input:focus,
select:focus,
textarea:focus {
  border-color: #4CAF50;
  outline: none;
  box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2);
}

/* =========================
   BUTTONS
   ========================= */

.save-button,
.delete-button,
.inline-btn {
  padding: 8px 16px;
  font-size: 0.9rem;
  border-radius: 6px;
  border: none;
  cursor: pointer;
}

.save-button {
  background-color: #4CAF50;
  color: #fff;
}
.save-button:hover { background-color: #3d9140; }

.delete-button {
  background-color: #ef4444;
  color: #fff;
}
.delete-button:hover { background-color: #dc2626; }

.inline-btn {
  background: #E5E7EB;
  color: #111827;
}
.inline-btn:hover { background: #D1D5DB; }

/* =========================
   LAYOUT HELPERS
   ========================= */

.form-group { margin-bottom: 16px; }

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
  gap: 20px;
}

.form-row {
  display: flex;
  flex-wrap: wrap;
  gap: 20px;
}

.form-row .col {
  flex: 1;
  min-width: 240px;
}

.section {
  background: #fff;
  border-radius: 12px;
  padding: 20px;
  margin-bottom: 24px;
  box-shadow: 0 4px 8px rgba(0,0,0,0.04);
}

.table-wrap {
  background: #fff;
  border-radius: 10px;
  padding: 12px;
  box-shadow: 0 2px 6px rgba(0,0,0,0.05);
}

/* =========================
   TABS & CMS SECTIONS
   ========================= */

.tab-button {
  background: #E5E7EB;
  border: none;
  padding: 6px 12px;
  border-radius: 4px;
  cursor: pointer;
}

.tab-button:hover { background: #ddd; }

.tab-button.active {
  background: #4CAF50;
  color: #fff;
}

.cms-section { display: none; }
.cms-section.active-section { display: block; }

/* =========================
   TOGGLE BUTTONS
   ========================= */

.toggle-btn {
  margin-left: 10px;
  padding: 6px 14px;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}

.enabled { background-color: green; }
.disabled { background-color: red; }

.disabled-section {
  opacity: 0.5;
  pointer-events: none;
  user-select: none;
}

/* =========================
   PAGE WRAPPER
   ========================= */

.page-wrapper {
  max-width: 1300px;
  padding: 0 16px;
  margin-left: 20px;
  margin-bottom: 2000px;
}

/* =========================
   DESKTOP DEFAULTS
   ========================= */

.mobile-header { display: none; }
#hamburger-menu { display: none; }
#menu-overlay { display: none; }

/* =========================
   MOBILE LAYOUT
   ========================= */

@media (max-width: 768px) {

  html, body {
    width: 100%;
    overflow-x: hidden;
  }

      /* ---- Fixed mobile header (title + date) ---- */
    .mobile-header {
      display: flex !important;
      position: fixed;
      top: 0; left: 0; right: 0;
      height: 56px;
      background: lightgreen;
      z-index: 10050;
      align-items: center;
      padding: 0 12px;
      box-sizing: border-box;
      font-weight: 800;
      color: #000;
    }

    .mobile-left { width: 34px; } /* reserved for hamburger */
    .mobile-center {
      flex: 1;
      text-align: center;
      font-size: 16px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .mobile-right {
      font-size: 12px;
      font-weight: 700;
      white-space: nowrap;
    }

  /* Hamburger */
  #hamburger-menu {
    display: flex !important;
    width: 34px;
    height: 34px;
    padding: 8px;
    border-radius: 10px;
    background: rgba(255,255,255,.48);
    cursor: pointer;
    flex-direction: column;
    justify-content: center;
    gap: 5px;
  }

  #hamburger-menu .bar {
    height: 2px;
    width: 100%;
    background: #111;
    border-radius: 2px;
    transition: .25s;
  }

  #hamburger-menu.active .bar:nth-child(1) {
    transform: rotate(-45deg) translate(-4px, 5px);
  }
  #hamburger-menu.active .bar:nth-child(2) { opacity: 0; }
  #hamburger-menu.active .bar:nth-child(3) {
    transform: rotate(45deg) translate(-4px, -5px);
  }

  /* Content spacing */
  .container {
    padding-top: 56px !important;
    width: 100% !important;
  }

  .dash-body {
    margin: 0 !important;
    padding: 12px !important;
    width: 100% !important;
  }

  /* Sidebar drawer */
  .menu {
    display: block !important;
    position: fixed !important;
    top: 56px;
    left: -280px;
    width: 280px;
    max-width: 86vw;
    height: calc(100vh - 56px);
    background: lightgreen;
    z-index: 10040;
    overflow-y: auto;
    transition: left .25s ease;
    box-shadow: 10px 0 30px rgba(0,0,0,.12);
  }

  .menu.active { left: 0; }

  /* Overlay */
  #menu-overlay {
    display: block !important;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.35);
    opacity: 0;
    pointer-events: none;
    transition: opacity .2s ease;
    z-index: 10030;
  }

  body.menu-open #menu-overlay {
    opacity: 1;
    pointer-events: auto;
  }

  body.menu-open { overflow: hidden; }

  /* Page wrapper */
  .page-wrapper {
    max-width: 100% !important;
    margin-left: 0 !important;
    padding: 0 !important;
    margin-bottom: 40px !important;
  }

  /* Hide desktop title + date */
  .dash-body p[style*="Content Management"],
  .dash-body table td:nth-child(2),
  .dash-body table td:nth-child(3) {
    display: none !important;
  }

  /* Section buttons grid */
  .dash-body > div > div[style*="margin-top:20px"] {
    display: grid !important;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
  }

  .dash-body > div > div[style*="margin-top:20px"] .save-button {
    width: 100%;
    padding: 10px 12px;
    border-radius: 10px;
    font-size: 13px;
    white-space: normal;
  }
}

/* =========================
   ADS TABLE (MOBILE)
   ========================= */

@media (max-width: 768px) {

  #ads-section .table-wrap,
  #ads-section .dataTables_wrapper {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }

  #adsTable {
    width: 100%;
    min-width: 820px;
  }

  #adsTable th,
  #adsTable td {
    font-size: 12px;
    padding: 8px;
    white-space: nowrap;
    vertical-align: middle;
  }

  #adsTable td:nth-child(2) img,
  #adsTable td:nth-child(2) video {
    max-width: 110px;
    border-radius: 8px;
  }

  #adsTable input.ads-alt { width: 180px; }
  #adsTable input.ads-href { width: 260px; }

  #adsTable .inline-btn,
  #adsTable .delete-button {
    padding: 7px 10px;
    font-size: 12px;
    border-radius: 8px;
  }
}

@media (max-width: 768px) {
  .mobile-right{
    display:flex !important;
    align-items:center !important;
    gap:8px !important;
  }

  .mh-cal{
    width:34px !important;
    height:34px !important;
    border-radius:10px !important;
    border:none !important;
    background:rgba(255, 255, 255, 0) !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    cursor:pointer !important;
    padding:0 !important;
    overflow: visible !important;
  }

  .mh-cal img{
    width:18px !important;
    height:18px !important;
    display:block !important;
    opacity:1 !important;
    visibility:visible !important;
  }
}

</style>
</head>
<body>

<!-- ✅ MOBILE HEADER -->
<div class="mobile-header" id="mobileHeader">

    <div class="mobile-left">
        <div id="hamburger-menu">
            <div class="bar"></div>
            <div class="bar"></div>
            <div class="bar"></div>
        </div>
    </div>

    <div class="mobile-center">Home</div>

    <div class="mobile-right mh-right">
        <div class="mh-date">
            <span class="mh-date-label">Date</span>
            <span class="mh-date-value"><?php echo $today; ?></span>
        </div>
        <button class="mh-cal btn-label" type="button" aria-label="Calendar">
            <img src="../img/calendar.svg" width="100%">
        </button>
    </div>
</div>

<div id="menu-overlay"></div>

<div class="container">
        <!-- Sidebar Menu -->
        <div class="menu">
            <table class="menu-container" border="0">
                <tr>
                    <td style="padding:10px" colspan="2">
                        <table border="0" class="profile-container">
                            <tr>
                                <td width="30%" style="padding-left:20px" >
                                <p class="profile-title"><?php echo substr($username,0,50) ?></p>
                                <p class="profile-subtitle"><?php echo substr($useremail,0,50)  ?></p>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="2">
                                    <a href="../logout.php"><input type="button" value="Log out" class="logout-btn btn-primary-soft btn"></a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr class="menu-row">
                        <td class="menu-btn ">
                            <a href="dashboard.php" class="non-style-link-menu ">
                                <p class="menu-text">Dashboard</p>
                            </a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn ">
                            <a href="logs.php" class="non-style-link-menu">
                                <p class="menu-text">Logs</p>
                            </a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn ">
                            <a href="clients.php" class="non-style-link-menu">
                                <p class="menu-text">Accounts</p>
                            </a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn menu-active">
                            <a href="cms.php" class="non-style-link-menu non-style-link-menu-active">
                                <p class="menu-text">CMS</p>
                            </a>
                        </td>
                    </tr>
                    
            </table>
        </div>

  <!-- Main content -->
<div class="dash-body" style="margin-top: 15px; padding-left:15px;">
  <div style="display:left;align-items:center;justify-content:space-between;margin-bottom:12px">
     <table border="0" width="100%" >
                <tr >
                    <td>
                        <p style="font-size: 30px;  text-align:left; font-weight: 600;">Content Management</p>
                                           
                    </td>

                    <td width="15%">
                        <p style="font-size: 14px;color: rgb(119, 119, 119);padding: 0;margin: 0;text-align: right;">
                            Today's Date
                        </p>
                        <p class="heading-sub12" style="padding: 0;margin: 0;">
                        <?php echo $today;?>

                        </p>
                    </td>
                    <td width="10%">
                        <button  class="btn-label"  style="display: flex;justify-content: center;align-items: center;"><img src="../img/calendar.svg" width="100%"></button>
                    </td>
                </tr>
            </table>

    <div style="margin-top:20px;">
      <button class="save-button" onclick="showSection('home-section')">Home</button>
      <button class="save-button" onclick="showSection('rooms-section')">Rooms Management</button>
      <button class="save-button" onclick="showSection('beds-section')">Beds Management</button>
      <button class="save-button" onclick="showSection('specialties-section')">Specialties</button>
      <button class="save-button" onclick="showSection('background-section')">Background</button>
      <button class="save-button" onclick="showSection('logo-section')">Logo</button>
      <button class="save-button" onclick="showSection('function-control-section')">Feature Control</button>
      <button class="save-button" onclick="showSection('template-upload-section')">Upload Template</button>
      <button class="save-button" onclick="showSection('ads-section')">Ads / Rotating Screens</button>


    </div>
  </div>

<div class="page-wrapper">
  <!-- ================= HOME SECTION ================= -->
  <section id="home-section" class="cms-section active-section">
    <h2>Home</h2>

    <!-- Home Page Content -->
    <div class="section" style="border: 1px solid black;">
      <h3>Home Page Content</h3>
      <form id="homeForm" class="cms-form">

        <label>Home Page Title</label>
        <input id="home_title" type="text">
        <div class="form-actions" style="margin-top: 10px;">
          <button type="button" class="save-button" onclick="if(confirm('Save Home Title?')) saveHomeTitle()">Save Title</button>
        </div>

        <label>Mission</label>
        <textarea id="mission" rows="3"></textarea>
        <div class="form-actions" style="margin-top: 10px;">
          <button type="button" class="save-button" onclick="if(confirm('Save Mission?')) saveMission()">Save Mission</button>
        </div>

        <label>Vision</label>
        <textarea id="vision" rows="3"></textarea>
        <div class="form-actions" style="margin-top: 10px;">
          <button type="button" class="save-button" onclick="if(confirm('Save Vision?')) saveVision()">Save Vision</button>
        </div>

        <label>Services</label>
        <textarea id="services" rows="3"></textarea>
        <div class="form-actions" style="margin-top: 10px;">
          <button type="button" class="save-button" onclick="if(confirm('Save Services?')) saveServices()">Save Services</button>
        </div>

        <div id="homeMsg"></div>
      </form>
    </div>

    <!-- Announcements -->
    <div class="section" style="border: 1px solid black;">
      <h3>Announcements</h3>
      <form id="annForm">
        <textarea id="announcement_patient" rows="3"></textarea>
        <div class="form-actions" style="margin-top: 10px;">
          <button type="button" class="save-button" onclick="if(confirm('Save Announcement?')) savePatientAnnouncement()">Announcement</button>
        </div>
        <div id="annMsg"></div>
      </form>
    </div>

    <!-- Contact Information (UNCHANGED) -->
    <div class="section" style="border: 1px solid black;" data-feature="contact">
      <h3>Contact Information</h3>
      <form id="contactForm">
        <div class="form-row">
          <div class="col">
            <label>Hospital Email</label>
            <input id="contact_email" type="email">
            
            <label>Emergency Hotline</label>
            <input id="dept_emergency" type="tel" pattern="[0-9]*" inputmode="numeric">
            
            <label>Out-Patient Dept</label>
            <input id="dept_outpatient" type="tel" pattern="[0-9]*" inputmode="numeric">

            <label>Hospital Address</label>
            <input id="contact_address" type="text">
            
            <label>Customer Care</label>
            <input id="dept_customer" type="tel" pattern="[0-9]*" inputmode="numeric">
          </div>
          <div class="col">
            <label>Admitting Dept</label>
            <input id="dept_admitting" type="tel" pattern="[0-9]*" inputmode="numeric">
            
            <label>Radiology</label>
            <input id="dept_radiology" type="tel" pattern="[0-9]*" inputmode="numeric">
            
            <label>Cardio-Pulmo</label>
            <input id="dept_cardiopulmo" type="tel" pattern="[0-9]*" inputmode="numeric">
            
            <label>Laboratory</label>
            <input id="dept_laboratory" type="tel" pattern="[0-9]*" inputmode="numeric">
            
            <label>GentriMed Molecular Lab</label>
            <input id="dept_molecular" type="tel" pattern="[0-9]*" inputmode="numeric">
          </div>
        </div>
        <div id="contactMsg"></div>
        <div class="form-actions" style="margin-top: 20px;">
          <button type="button" class="save-button" onclick="if(confirm('Save contact information?')) saveContact()">Save Contact</button>
        </div>
      </form>
    </div>

    <!-- Banner Management -->
    <div class="section" style="border: 1px solid black;" data-feature="banner">
      <h3>Banner Management</h3>
      <form id="bannerForm" enctype="multipart/form-data">
        <label>Upload Banner</label>
        <input id="banner" type="file" accept="image/*">
        <div class="form-actions" style="margin-top: 10px;">
          <button type="button" class="save-button" onclick="if(confirm('Upload this banner?')) saveBanner()">Upload Banner</button>
        </div>
        <div id="bannerMsg"></div>
      </form>
    </div>
  </section>


<!-- ================= ROOMS MANAGEMENT ================= -->
<section id="rooms-section" class="cms-section">
  <h2>Rooms Management</h2>

  <!-- ========== ADD ROOMS ========== -->
  <div class="section" style="border: 1px solid black; margin-bottom: 20px;">
    <h3>Add Rooms</h3>
    <form id="addRoomForm">
      <div class="form-row">
        <div class="col">
          <label>Department</label>
          <select id="room_department"></select>
        </div>
        <div class="col">
          <label>Room Type</label>
          <select id="room_type">
            <option value="private">Private</option>
            <option value="semi-private">Semi-Private</option>
            <option value="shared">Shared</option>
            <option value="isolation">Isolation</option>
          </select>
        </div>
        <div class="col">
          <label>Number of Rooms</label>
          <input id="number_of_rooms" type="number" min="1" value="1">
        </div>
      </div>
      <div id="addRoomMsg"></div>
      <div class="form-actions" style="margin-top: 20px;">
        <button type="button" class="save-button" onclick="if(confirm('Add new room(s)?')) addRooms()">Add Rooms</button>
      </div>
    </form>
  </div>

  <!-- ========== REMOVE ROOMS ========== -->
  <div class="section" style="border: 1px solid black; margin-bottom: 20px;">
    <h3>Remove Rooms</h3>
    <div class="form-row">
      <div class="col">
        <label>Select Department</label>
        <select id="dept_select_for_remove_room"></select>
      </div>
      <div class="col">
        <label>Select Room</label>
        <select id="room_select_for_remove_room"></select>
      </div>
    </div>
    <div id="removeRoomMsg"></div>
    <div class="form-actions" style="margin-top: 20px;">
      <button type="button" class="delete-button" onclick="if(confirm('Remove this room?')) removeRoom()">Remove Room</button>
    </div>
  </div>
   <!-- Rooms list for Rooms section -->
<div class="section" style="border: 1px solid black; margin-bottom: 20px;">
  <h3>Rooms List</h3>
  <div class="table-wrap">
    <table id="roomsTable" class="display" style="width:100%">
      <thead>
        <tr>
          <th>Department</th>
          <th>Room Key</th>
          <th>Room #</th>
          <th>Type</th>
          <th>Beds</th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>
  </div>
</div>
</section>

<!-- ================= BEDS MANAGEMENT ================= -->
<section id="beds-section" class="cms-section">
  <h2>Beds Management</h2>

  <!-- ========== ADD BEDS ========== -->
  <div class="section" style="border: 1px solid black; margin-bottom: 20px;">
    <h3>Add Beds</h3>
    <div class="form-row">
      <div class="col">
        <label>Select Department</label>
        <select id="dept_select_for_bed"></select>
      </div>
      <div class="col">
        <label>Select Room</label>
        <select id="room_select_for_bed"></select>
      </div>
      <div class="col">
        <label>Number of Beds</label>
        <input id="number_of_beds" type="number" min="1" value="1">
      </div>
    </div>
    <div id="addBedMsg"></div>
    <div class="form-actions" style="margin-top: 20px;">
      <button type="button" class="save-button" onclick="if(confirm('Add new bed(s)?')) addBeds()">Add Beds</button>
    </div>
  </div>

  <!-- ========== REMOVE BEDS ========== -->
  <div class="section" style="border: 1px solid black; margin-bottom: 20px;">
    <h3>Remove Beds</h3>
    <div class="form-row">
      <div class="col">
        <label>Department</label>
        <select id="dept_select_for_remove"></select>
      </div>
      <div class="col">
        <label>Room</label>
        <select id="room_select_for_remove"></select>
      </div>
      <div class="col">
        <label>Bed</label>
        <select id="bed_select_for_remove"></select>
      </div>
    </div>
    <div id="removeBedMsg"></div>
    <div class="form-actions" style="margin-top: 20px;">
      <button type="button" class="delete-button" onclick="if(confirm('Remove this bed?')) removeBed()">Remove Bed</button>
    </div>
  </div>

   <!-- Rooms list for Rooms section -->
<div class="section" style="border: 1px solid black; margin-bottom: 20px;">
  <h3>Rooms List</h3>
  <div class="table-wrap">
    <table id="bedsTable" class="display" style="width:100%">
      <thead>
        <tr>
          <th>Department</th>
          <th>Room Key</th>
          <th>Room #</th>
          <th>Beds</th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>
  </div>
</div>

</section>




<!-- ================= SPECIALTIES SECTION ================= -->
<section id="specialties-section" class="cms-section" data-feature="specialty">
  <h2>Specialties</h2>

  <!-- Manage Specialties -->
  <div class="section" style="border: 1px solid black;">
    <h3>Manage Specialties</h3>
    <form id="specialtyForm">
      <label>Specialty Name</label>
      <input id="specialty_name" type="text" autocomplete="off">
      <div id="specialtyMsg"></div>
       <div class="form-actions" style="margin-top: 20px;">
        <button type="button" class="save-button" onclick="if(confirm('Add this specialty?')) saveSpecialty()">Add Specialty</button>
      </div>
    </form>
  </div>

  <!-- Existing Specialties -->
  <div class="section" style="border: 1px solid black;">
    <h3>Existing Specialties</h3>
    <div class="search-bar">
      <input id="search-specialty" placeholder="Search specialties...">
      <button class="inline-btn" onclick="loadSpecialtiesTable()"><i class="fa fa-sync"></i> Refresh</button>
    </div>
    <div class="table-wrap">
      <table id="specialtiesTable" class="display" style="width:100%">
        <thead><tr><th></th><th>Specialty</th><th>Created By</th><th>Created At</th></tr></thead>
        <tbody></tbody>
      </table>
    </div>
     <div class="form-actions" style="margin-top: 20px;">
      <button class="delete-button" onclick="deleteSelectedSpecialties()">Delete Selected</button>
    </div>
  </div>
</section>

<!-- ================= BACKGROUND SECTION ================= -->
<section id="background-section" class="cms-section" data-feature="background">
  <h2>Background</h2>
  <div class="section" style="border: 1px solid black;">
    <h3>Background Management</h3>
    <form id="bgForm" enctype="multipart/form-data">
      <label>Select Page</label>
      <select id="bg_page">
        <option value="login">Login</option>
        <option value="landing">Landing</option>
      </select>
      <label>Background File</label>
      <input id="background_image" type="file" accept="image/*">
      <div id="bgMsg"></div>
       <div class="form-actions" style="margin-top: 20px;">
        <button class="save-button" type="button" onclick="if(confirm('Upload background image?')) uploadBackground()">Upload Background</button>
      </div>
    </form>
    <div id="backgroundsPreview" class="preview-area"></div>
  </div>
</section>

<!-- ================= LOGO SECTION ================= -->
<section id="logo-section" class="cms-section " data-feature="logo" >
  <h2>Logo</h2>
  <div class="section"  style="border: 1px solid black;">
    <h3>Logo Management</h3>
    <form id="logoForm" enctype="multipart/form-data">
      <label>Logo File</label>
      <input id="logo_image" type="file" accept="image/*">
      <div id="logoMsg"></div>
       <div class="form-actions" style="margin-top: 20px;">
        <button class="save-button" type="button" onclick="if(confirm('Upload logo image?')) uploadLogo()">Upload Logo</button>
      </div>
    </form>
    <div id="logoPreview" class="preview-area"></div>
  </div>
</section>

<!-- ================= FEATURE CONTROL SECTION ================= -->
<section id="function-control-section" class="cms-section">
  <h2>Feature Control</h2>
  <div class="section" style="border: 1px solid black; padding: 10px;">
    <h3>For Content Management</h3>
    <form id="functionControlForm">

      <div style="display: flex; align-items: center; margin-bottom: 10px;">
        <span style="width: 200px;">Specialty Management</span>
        <button type="button" class="toggle-btn" data-feature="specialty" onclick="toggleFeature(this)">Disable</button>
      </div>

      <div style="display: flex; align-items: center; margin-bottom: 10px;">
        <span style="width: 200px;">Banner Upload</span>
        <button type="button" class="toggle-btn" data-feature="banner" onclick="toggleFeature(this)">Disable</button>
      </div>

      <div style="display: flex; align-items: center; margin-bottom: 10px;">
        <span style="width: 200px;">Background Upload</span>
        <button type="button" class="toggle-btn" data-feature="background" onclick="toggleFeature(this)">Disable</button>
      </div>

      <div style="display: flex; align-items: center; margin-bottom: 10px;">
        <span style="width: 200px;">Logo Upload</span>
        <button type="button" class="toggle-btn" data-feature="logo" onclick="toggleFeature(this)">Disable</button>
      </div>

      <div style="display: flex; align-items: center; margin-bottom: 10px;">
        <span style="width: 200px;">Contact Info Save</span>
        <button type="button" class="toggle-btn" data-feature="contact" onclick="toggleFeature(this)">Disable</button>
      </div>

      <div id="featureToggleMsg" style="margin-top: 10px; font-weight: bold;"></div>
    </form>
  </div>
</section>


<!-- ================= TEMPLATE UPLOAD SECTION ================= -->
<section id="template-upload-section" class="cms-section">
  <h2>Template Upload</h2>

  <div class="section" style="border: 1px solid black;">
    <h3>Upload Template or Image</h3>
    <form id="templateUploadForm" method="POST" enctype="multipart/form-data">
      <label>Select File (DOC, DOCX, PDF, PNG, JPG, JPEG)</label>
      <input name="template_file" type="file" accept=".doc,.docx,.pdf,.png,.jpg,.jpeg" required>
       <div class="form-actions" style="margin-top: 20px;">
        <button type="submit" class="save-button">Upload File</button>
      </div>
    </form>
    <div id="templateUploadMsg" style="margin-top:10px;"></div>
  </div>

 <div id="templatePreview" style="margin-top:20px; border: 1px solid #ddd; padding: 10px; border-radius: 8px;">
  <h4>Current Template Preview</h4>
  <div id="templatePreviewBody" class="small">Loading template preview...</div>
</div>

</section>

<!-- ================= ADS / ROTATING SCREENS ================= -->
<section id="ads-section" class="cms-section">
  <h2>Ads / Rotating Screens</h2>

  <!-- Config -->
  <div class="section" style="border: 1px solid black;">
    <h3>Rotation Settings</h3>
    <div class="form-row">
      <div class="col">
        <label>Interval (seconds)</label>
        <input id="ads_interval_sec" type="number" min="1" value="12">
      </div>
      <div class="col">
        <label>Enabled</label>
        <select id="ads_enabled">
          <option value="true">Enabled</option>
          <option value="false">Disabled</option>
        </select>
      </div>
    </div>
    <div id="adsConfigMsg"></div>
    <div class="form-actions" style="margin-top: 10px;">
      <button type="button" class="save-button" onclick="adsSaveConfig()">Save Settings</button>
    </div>
  </div>

  <!-- Uploader -->
  <div class="section" style="border: 1px solid black;">
    <h3>Upload New Ad</h3>
<form id="adsUploadForm" enctype="multipart/form-data">
  <div class="form-row">
    <div class="col">
      <label>Media (Image or Video)</label>
      <input id="ad_file" name="ad_file" type="file" accept="image/*,video/*" required>
      <div class="small">Supported videos: MP4, WebM, Ogg</div>
    </div>
    <div class="col">
      <label>Link (href - optional)</label>
      <input id="ad_href" type="url" placeholder="https://example.com">
    </div>
    <div class="col">
      <label>Alt / Caption</label>
      <input id="ad_alt" type="text" placeholder="Health Tips">
    </div>
  </div>
  <div id="adsUploadMsg"></div>
  <div class="form-actions" style="margin-top: 10px;">
    <button type="button" class="save-button" onclick="adsUpload()">Upload Ad</button>
  </div>
</form>

  </div>

  <!-- List / Manage -->
  <div class="section" style="border: 1px solid black;">
    <h3>Manage Ads</h3>
    <div class="table-wrap">
      <table id="adsTable" class="display" style="width:100%">
        <thead>
          <tr>
            <th>Order</th>
            <th>Preview</th>
            <th>Alt</th>
            <th>Href</th>
            <th>Active</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="adsTbody"></tbody>
      </table>
    </div>
    <div class="form-actions" style="margin-top: 10px;">
      <button type="button" class="save-button" onclick="adsSaveOrder()">Save Order</button>
      <button type="button" class="inline-btn" onclick="window.open(window.location.pathname+'?action=ads_json','_blank')">Preview JSON</button>
    </div>
  </div>
</section>

</div>
  </div>
</div>

<!-- libs -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.colVis.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// ==========================
//  Small Utilities
// ==========================
function capitalizeFirstLetter(str) {
  if (!str) return '';
  return str.charAt(0).toUpperCase() + str.slice(1);
}
function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function escapeAttr(s){ return escapeHtml(s).replace(/"/g,'&quot;'); }
function loadingRow(colspan){
  return `<tr><td colspan="${colspan}"><div style="display:flex;gap:10px;align-items:center"><div class="loading"></div> Loading...</div></td></tr>`;
}
function toInt(val, def = 0) {
  const n = parseInt(val, 10);
  return Number.isFinite(n) ? n : def;
}
function toNum(val, def = 0) {
  const n = Number(val);
  return Number.isFinite(n) ? n : def;
}
function countBedsField(bedsLike) {
  if (Array.isArray(bedsLike)) return bedsLike.length;
  if (bedsLike && typeof bedsLike === 'object') return Object.keys(bedsLike).length;
  return toInt(bedsLike, 0);
}

async function apiGET(params){
  const url = window.location.pathname + '?' + new URLSearchParams(params);
  const res = await fetch(url);
  return res.json();
}
async function apiPOST(fd){
  const res = await fetch(window.location.pathname, { method:'POST', body: fd });
  return res.json();
}

// ==========================
//  Sections / Tabs
// ==========================
function showSection(sectionId) {
  document.querySelectorAll('.cms-section').forEach(section => {
    section.classList.remove('active-section');
  });
  document.getElementById(sectionId).classList.add('active-section');

  // Ensure data is loaded when switching sections
  if (sectionId === 'ads-section') adsLoad();
}

function showTab(id){
  document.querySelectorAll('.tab-content').forEach(t=>t.classList.remove('active'));
  document.getElementById(id).classList.add('active');

  if (id === 'add-room-tab') { populateDepartmentSelects(); loadRoomsTable(); }
  if (id === 'manage-specialties-tab') loadSpecialtiesTable();
  if (id === 'background-management-tab') loadBackgrounds();
  if (id === 'logo-management-tab') loadLogo();
  if (id === 'home-content') { loadHome(); loadAnnouncements(); loadContact(); loadBanner(); }
  if (id === 'beds-section') { loadBedsTable(); }
  if (id === 'rooms-section') { loadRoomsTable(); }
}

// ==========================
//  Home
// ==========================
async function loadHome(){
  const res = await apiGET({ action:'get_home' });
  if (res.status === 'success') {
    const h = res.home || {};
    $('#home_title').val(h.title||'');
    $('#mission').val(h.mission||'');
    $('#vision').val(h.vision||'');
    $('#services').val(h.services||'');
  }
}
async function saveHome(){
  const fd = new FormData();
  fd.append('action','save_home');
  fd.append('home_title', $('#home_title').val());
  fd.append('mission', $('#mission').val());
  fd.append('vision', $('#vision').val());
  fd.append('services', $('#services').val());
  const res = await apiPOST(fd);
  showMsg('homeMsg', res);
}
async function saveHomeTitle(){
  const fd = new FormData();
  fd.append('action','save_home_title');
  fd.append('home_title', $('#home_title').val());
  const res = await apiPOST(fd);
  showMsg('homeMsg', res);
  if(res.status==='success') loadHome();
}
async function saveMission(){
  const fd = new FormData();
  fd.append('action','save_mission');
  fd.append('mission', $('#mission').val());
  const res = await apiPOST(fd);
  showMsg('homeMsg', res);
  if(res.status==='success') loadHome();
}
async function saveVision(){
  const fd = new FormData();
  fd.append('action','save_vision');
  fd.append('vision', $('#vision').val());
  const res = await apiPOST(fd);
  showMsg('homeMsg', res);
  if(res.status==='success') loadHome();
}
async function saveServices(){
  const fd = new FormData();
  fd.append('action','save_services');
  fd.append('services', $('#services').val());
  const res = await apiPOST(fd);
  showMsg('homeMsg', res);
  if(res.status==='success') loadHome();
}

// ==========================
//  Announcements (fixed)
// ==========================
async function loadAnnouncements(){
  const res = await apiGET({ action:'get_announcements' });
  if (res.status === 'success') {
    const a = res.announcements || {};
    // Backend stores { announcement: "..." }
    $('#announcement_patient').val(a.announcement || '');
  }
}
async function savePatientAnnouncement(){
  const fd = new FormData();
  fd.append('action','save_announcement_patient');
  fd.append('announcement_patient', $('#announcement_patient').val());
  const res = await apiPOST(fd);
  showMsg('annMsg', res);
  if (res.status === 'success') loadAnnouncements();
}

// ==========================
//  Contact
// ==========================
async function loadContact(){
  const res = await apiGET({ action:'get_contact' });
  if (res.status === 'success') {
    const c = res.contact || {};
    $('#contact_email').val(c.email||'');
    $('#dept_emergency').val(c.dept_emergency||'');
    $('#dept_outpatient').val(c.dept_outpatient||'');
    $('#dept_customer').val(c.dept_customer||'');
    $('#dept_admitting').val(c.dept_admitting||'');
    $('#dept_radiology').val(c.dept_radiology||'');
    $('#dept_cardiopulmo').val(c.dept_cardiopulmo||'');
    $('#dept_laboratory').val(c.dept_laboratory||'');
    $('#dept_molecular').val(c.dept_molecular||'');
    $('#contact_address').val(c.address||'');
  }
}
async function saveContact(){
  const fd = new FormData();
  fd.append('action','save_contact');
  fd.append('contact_email', $('#contact_email').val());
  fd.append('dept_emergency', $('#dept_emergency').val());
  fd.append('dept_outpatient', $('#dept_outpatient').val());
  fd.append('dept_customer', $('#dept_customer').val());
  fd.append('dept_admitting', $('#dept_admitting').val());
  fd.append('dept_radiology', $('#dept_radiology').val());
  fd.append('dept_cardiopulmo', $('#dept_cardiopulmo').val());
  fd.append('dept_laboratory', $('#dept_laboratory').val());
  fd.append('dept_molecular', $('#dept_molecular').val());
  fd.append('contact_address', $('#contact_address').val());
  const res = await apiPOST(fd);
  showMsg('contactMsg', res);
}

// ==========================
//  Banner
// ==========================
async function loadBanner(){
  const res = await apiGET({ action:'get_banner' });
  if (res.status === 'success') {
    const b = res.banner || {};
    $('#bannerMsg').html(b.url ? '<div class="small">Current banner</div><img src="'+escapeAttr(b.url)+'" style="max-width:320px;border-radius:8px">' : '<div class="small">No banner</div>');
  }
}
async function saveBanner(){
  const file = $('#banner')[0].files[0];
  if (!file) return alert('Choose a banner file');
  const fd = new FormData();
  fd.append('action','save_banner');
  fd.append('banner', file);
  const res = await apiPOST(fd);
  showMsg('bannerMsg', res);
  loadBanner();
}

// ==========================
//  Template Upload + Preview
// ==========================
document.addEventListener('DOMContentLoaded', function(){
  const form = document.getElementById('templateUploadForm');
  if (form){
    form.addEventListener('submit', async function(e) {
      e.preventDefault();
      const fileInput = document.querySelector('[name="template_file"]');
      if (!fileInput || !fileInput.files.length) return alert('Please select a file');
      if (!confirm('Upload this template?')) return;
      const fd = new FormData();
      fd.append('action', 'upload_template');
      fd.append('template_file', fileInput.files[0]);
      const res = await apiPOST(fd);
      const msgBox = document.getElementById('templateUploadMsg');
      msgBox.innerHTML = `<div style="color:${res.status === 'success' ? 'green' : 'red'}">${res.message}</div>`;
      if (res.status === 'success' && res.url) {
        const button = document.createElement('button');
        button.textContent = 'View uploaded template';
        button.style.marginTop = '8px';
        button.addEventListener('click', () => window.open(res.url, '_blank'));
        msgBox.appendChild(button);
      }
    });
  }
});

async function loadTemplatePreview() {
  const body = document.getElementById('templatePreviewBody') || document.getElementById('templatePreview');
  if (!body) return;
  body.innerHTML = 'Loading template preview...';

  try {
    const res = await apiGET({ action: 'get_template' });
    if (!(res?.status === 'success' && res.template?.url)) {
      body.innerHTML = '<div class="small">No template uploaded</div>';
      return;
    }

    const url = String(res.template.url).trim();
    const ext = getExtFromUrl(url);
    const drive = parseGoogleUrl(url);
    let html = '';

    if (drive) {
      const previewSrc = googlePreviewUrl(drive);
      html = iframeOrNotice(previewSrc, url, 'Document Preview (Google)');
    } else if (['jpg','jpeg','png','gif','webp','bmp','svg'].includes(ext)) {
      html = `
        <img src="${escapeAttr(url)}" alt="Uploaded Image" style="max-width:100%;border-radius:8px;">
        <p><a href="${escapeAttr(url)}" target="_blank" rel="noopener">Open full image</a></p>`;
    } else if (ext === 'pdf') {
      html = iframeOrNotice(url, url, 'PDF Preview');
    } else if (['doc','docx','ppt','pptx','xls','xlsx'].includes(ext)) {
      const office = `https://view.officeapps.live.com/op/embed.aspx?src=${encodeURIComponent(url)}`;
      html = iframeOrNotice(office, url, 'Office Document Preview');
    } else {
      const gview = `https://docs.google.com/gview?embedded=1&url=${encodeURIComponent(url)}`;
      html = iframeOrNotice(gview, url, 'Preview');
    }

    body.innerHTML = html;

  } catch (err) {
    console.error(err);
    body.innerHTML = `
      <div style="color:#b00020">Failed to load preview.</div>
      <div class="small">${escapeHtml(String(err?.message || err))}</div>`;
  }
}

function iframeOrNotice(src, openUrl, title) {
  return `
    <div class="small" style="margin-bottom:6px">${escapeHtml(title)}</div>
    <iframe src="${escapeAttr(src)}" style="width:100%;height:500px;border:none;" title="${escapeAttr(title)}"></iframe>
    <p class="small" style="margin-top:6px">
      If the preview is blank or shows an error
      <a href="${escapeAttr(openUrl)}" target="_blank" rel="noopener">Download it here instead</a>.
    </p>`;
}
function getExtFromUrl(u) {
  try {
    const p = new URL(u).pathname.toLowerCase();
    const name = p.split('/').pop() || '';
    const i = name.lastIndexOf('.');
    return i > -1 ? name.slice(i + 1) : '';
  } catch {
    const clean = (u || '').split('?')[0].split('#')[0];
    const name = clean.split('/').pop() || '';
    const i = name.lastIndexOf('.');
    return i > -1 ? name.slice(i + 1).toLowerCase() : '';
  }
}
function parseGoogleUrl(u) {
  let m = u.match(/drive\.google\.com\/file\/d\/([^/]+)/i);
  if (m) return { kind:'drive-file', id:m[1] };
  m = u.match(/drive\.google\.com\/(?:open|uc)\?[^#]*[?&]id=([^&]+)/i);
  if (m) return { kind:'drive-file', id:m[1] };
  m = u.match(/docs\.google\.com\/document\/d\/([^/]+)/i);
  if (m) return { kind:'doc', id:m[1] };
  return null;
}
function googlePreviewUrl(info) {
  const id = info.id;
  switch (info.kind) {
    case 'drive-file': return `https://drive.google.com/file/d/${id}/preview`;
    case 'doc':        return `https://docs.google.com/document/d/${id}/preview`;
    default:           return `https://drive.google.com/file/d/${id}/preview`;
  }
}

// ==========================
//  Logo
// ==========================
async function uploadLogo(){
  const file = $('#logo_image')[0].files[0];
  if (!file) return alert('Select logo');
  const fd = new FormData();
  fd.append('action','upload_logo');
  fd.append('logo_image', file);
  const res = await apiPOST(fd);
  showMsg('logoMsg', res);
  loadLogo();
}
async function loadLogo(){
  const res = await apiGET({ action:'get_logo' });
  const cont = $('#logoPreview'); cont.html('');
  if (res.status === 'success') {
    const site = res.site || {};
    if (site.logo) cont.html('<img src="'+escapeAttr(site.logo)+'" style="max-width:250px;border-radius:8px">');
    else cont.html('<div class="small">No logo uploaded</div>');
  }
}

// ==========================
//  Rooms & Beds
// ==========================
async function populateDepartmentSelects(){
  try {
    const res = await apiGET({ action:'list_rooms_root' });
    let depts = [];
    if (res.status === 'success') depts = Object.keys(res.rooms || {});
    if (!depts.length) depts = ['emergency','general'];
    depts.sort((a,b) => a.localeCompare(b));

    ['dept_select_for_bed','dept_select_for_remove','dept_select_for_remove_room','room_department'].forEach(id=>{
      const el = document.getElementById(id);
      if (!el) return;
      const prev = el.value;
      el.innerHTML = '<option value="" disabled selected>Select a department</option>';
      depts.forEach(d => {
        const op = document.createElement('option');
        op.value = d;
        op.textContent = capitalizeFirstLetter(d);
        el.appendChild(op);
      });
      if (prev) el.value = prev;
    });

    if (depts[0]) populateRoomsForDept(depts[0]);
  } catch(e){
    console.error('populateDepartmentSelects error:', e);
  }
}
async function populateRoomsForDept(dept){
  const sels = {
    addBeds   : document.getElementById('room_select_for_bed'),
    removeBed : document.getElementById('room_select_for_remove'),
    removeRoom: document.getElementById('room_select_for_remove_room'),
  };

  Object.values(sels).forEach(sel => {
    if (!sel) return;
    sel.innerHTML = '<option value="" disabled selected>Select a room</option>';
    sel.disabled = !dept;
  });
  if (!dept) return;

  const res = await apiGET({ action:'list_rooms_root' });
  if (!(res?.status === 'success' && res.rooms && res.rooms[dept])) {
    Object.values(sels).forEach(sel => {
      if (!sel) return;
      sel.innerHTML = '<option value="" disabled selected>No rooms</option>';
      sel.disabled = true;
    });
    return;
  }

  let roomsArr = [];
  const raw = res.rooms[dept];
  if (Array.isArray(raw)) {
    roomsArr = raw;
  } else {
    roomsArr = Object.keys(raw).map(k => ({ id:k, ...(raw[k]||{}) }));
  }

  roomsArr = roomsArr.map(r => ({
    id         : r.id ?? r.key ?? r.room_id ?? r.key_id,
    key        : r.key ?? r.id ?? r.room_id,
    room_number: toInt(r.room_number ?? r.number ?? r.no, 0),
    type       : r.type ?? '',
    beds_count : countBedsField(r.beds_count ?? r.beds)
  }))
  .filter(r => r.id)
  .sort((a,b) => (a.room_number||0) - (b.room_number||0));

  function fillSelect(sel){
    if (!sel) return;
    const prev = sel.value;
    sel.innerHTML = '<option value="" disabled selected>Select a room</option>';
    roomsArr.forEach(r => {
      const op = document.createElement('option');
      op.value = r.id; 
      op.dataset.roomKey = r.key || '';
      op.textContent = `${r.room_number || r.key} · ${r.type || 'Room'} · Beds: ${toInt(r.beds_count, 0)}`;
      sel.appendChild(op);
    });
    if (prev && roomsArr.some(r => r.id == prev)) sel.value = prev;
    sel.disabled = roomsArr.length === 0;
  }

  fillSelect(sels.addBeds);
  fillSelect(sels.removeBed);
  fillSelect(sels.removeRoom);

  const deptForRemove = $('#dept_select_for_remove').val();
  const roomForRemove = $('#room_select_for_remove').val();
  if (deptForRemove && roomForRemove && deptForRemove === dept) {
    loadBedsForRemove(deptForRemove);
  }
}

$(document).on('change', '#dept_select_for_bed', function(){ populateRoomsForDept(this.value); });
$(document).on('change', '#dept_select_for_remove', function(){ populateRoomsForDept(this.value); });
$(document).on('change', '#dept_select_for_remove_room', function(){ populateRoomsForDept(this.value); });

$(document).on('change', '#room_select_for_remove', function() {
  const dept = $('#dept_select_for_remove').val();
  const room = this.value;
  if (dept && room) loadBedsForRemove(dept);
});

async function loadBedsForRemove(dept){
  const roomSel = document.getElementById('room_select_for_remove');
  const bedSel  = document.getElementById('bed_select_for_remove');

  if (!dept || !roomSel?.value) {
    if (bedSel) bedSel.innerHTML = '<option disabled selected>Select room first</option>';
    return;
  }

  const res = await apiGET({ action:'get_beds', department: dept, room: roomSel.value });

  if (res.status === 'success') {
    bedSel.innerHTML = '<option value="" disabled selected>Select a bed</option>';

    const rawBeds = res.beds || [];
    // ✅ handle both arrays and objects from Firebase
    const bedsArr = Array.isArray(rawBeds) ? rawBeds : Object.values(rawBeds);

    const sortedBeds = bedsArr.slice().sort((a, b) => {
      const numA = toInt(a.bed_number, 0);
      const numB = toInt(b.bed_number, 0);
      return numA - numB;
    });

    sortedBeds.forEach(b => {
      const num = toInt(b.bed_number, 0);
      const op = document.createElement('option');
      op.value = String(num);
      op.textContent = num + ' - ' + (b.status || '');
      bedSel.appendChild(op);
    });
  } else {
    bedSel.innerHTML = '<option disabled selected>No beds</option>';
  }
}


async function addRooms(){
  const fd = new FormData();
  fd.append('action','add_room');
  fd.append('number_of_rooms', $('#number_of_rooms').val());
  fd.append('room_department', $('#room_department').val());
  fd.append('room_type', $('#room_type').val());
  const res = await apiPOST(fd);
  showMsg('addRoomMsg', res);
  await populateDepartmentSelects();
  loadRoomsTable();
}
async function addBeds(){
  const fd = new FormData();
  fd.append('action','add_bed');
  fd.append('room_id', $('#room_select_for_bed').val());
  fd.append('room_department', $('#dept_select_for_bed').val());
  fd.append('number_of_beds', $('#number_of_beds').val());
  const res = await apiPOST(fd);
  showMsg('addBedMsg', res);
  await populateRoomsForDept($('#dept_select_for_bed').val());
  loadRoomsTable();
  loadBedsTable();
}
async function removeBed(){
  const fd = new FormData();
  fd.append('action','remove_bed');
  fd.append('room_id', $('#room_select_for_remove').val());
  fd.append('bed_number', $('#bed_select_for_remove').val()); 
  fd.append('department_id', $('#dept_select_for_remove').val());
  const res = await apiPOST(fd);
  showMsg('removeBedMsg', res);
  await populateRoomsForDept($('#dept_select_for_remove').val());
  loadRoomsTable();
  loadBedsTable();
}
async function removeRoom(){
  const fd = new FormData();
  fd.append('action','remove_room');
  fd.append('room_id', $('#room_select_for_remove_room').val());
  fd.append('department_id', $('#dept_select_for_remove_room').val());
  const res = await apiPOST(fd);
  showMsg('removeRoomMsg', res);
  await populateDepartmentSelects();
  loadRoomsTable();
  loadBedsTable();
}

function buildOrUpdateTable(selector, rows, columns, tableName){
  if ($.fn.DataTable.isDataTable(selector)) {
    const dt = $(selector).DataTable();
    dt.clear().rows.add(rows).draw(false);
    return dt;
  }
  return $(selector).DataTable({
    data: rows,
    columns,
    dom: 'Bfrtip',
    buttons: [
      { extend:'csv', text:'CSV', filename: tableName, title: tableName },
      { extend:'excel', text:'Excel', filename: tableName, title: tableName },
      { extend:'pdf', text:'PDF', filename: tableName, title: tableName },
      { extend:'print', text:'Print', title: tableName },
      { extend:'colvis', text:'Columns' }
    ],
    pageLength: 10,
    order: [[0,'asc']]
  });
}

async function loadRoomsTable(){
  const $tbody = $('#roomsTable tbody');
  $tbody.html(loadingRow(5));
  try {
    const res = await apiGET({ action:'list_rooms_flat' });
    if (res?.status === 'success') {
      const rows = (res.rooms || []).map(r => ({
        ...r,
        beds_count: countBedsField(r.beds_count ?? r.beds)
      }));
      buildOrUpdateTable('#roomsTable', rows, [
        { data:'department', render:d=>capitalizeFirstLetter(d ?? '') },
        { data:'key' },
        { data:'room_number', render:d => toInt(d, 0) },
        { data:'type' },
        { data:'beds_count', render:d => toInt(d, 0) }
      ], 'Rooms List');
    } else {
      $tbody.html('<tr><td colspan="5">No rooms found</td></tr>');
    }
  } catch (e){
    console.error(e);
    $tbody.html('<tr><td colspan="5">Error loading rooms.</td></tr>');
  }
}
async function loadBedsTable(){
  const $tbody = $('#bedsTable tbody');
  $tbody.html(loadingRow(4));   // 4 columns now

  try {
    // use the same flat room list as Rooms Management
    const res = await apiGET({ action:'list_rooms_flat' });

    if (res?.status === 'success' && Array.isArray(res.rooms)) {
      const rows = res.rooms.map(r => ({
        department : r.department,
        room_key   : r.key,
        room_number: toInt(r.room_number, 0),
        beds_count : countBedsField(r.beds_count ?? r.beds)
      }));

      buildOrUpdateTable('#bedsTable', rows, [
        { data:'department', render:d => capitalizeFirstLetter(d ?? '') },
        { data:'room_key' },
        { data:'room_number', render:d => toInt(d, 0) },
        { data:'beds_count',  render:d => toInt(d, 0) }
      ], 'Rooms List');

    } else {
      $tbody.html('<tr><td colspan="4">No rooms found</td></tr>');
    }

  } catch (e){
    console.error(e);
    $tbody.html('<tr><td colspan="4">Error loading rooms.</td></tr>');
  }
}

// ==========================
//  Specialties
// ==========================
let specialtiesTable = null;

async function loadSpecialtiesTable(){
  $('#specialtiesTable tbody').html('<tr><td colspan="4"><div style="display:flex;gap:10px;align-items:center"><div class="loading"></div> Loading...</div></td></tr>');

  const today = new Date();
  const yyyy  = today.getFullYear();
  const mm    = String(today.getMonth() + 1).padStart(2, '0');
  const dd    = String(today.getDate()).padStart(2, '0');
  const tableName = `Specialties List — ${yyyy}-${mm}-${dd}`;
  const fileName  = tableName.replace(/[^\w\-]+/g, '_');

  const res = await apiGET({ action:'list_specialties' });
  if (res.status === 'success') {
    const sp = res.specialties || {};
    const arr = Object.keys(sp).map(k => ({
      id: k,
      sname: sp[k].sname || '',
      created_by: sp[k].created_by || '',
      created_at: sp[k].created_at || ''
    }));

    if (specialtiesTable) {
      specialtiesTable.clear().rows.add(arr).draw(false);
      return;
    }

    specialtiesTable = $('#specialtiesTable').DataTable({
      data: arr,
      columns: [
        { data: 'id', render: d => '<input type="checkbox" class="spec-check" value="'+d+'">', orderable:false },
        { data: 'sname' },
        { data: 'created_by' },
        { data: 'created_at' }
      ],
      dom: 'Bfrtip',
      buttons: [
        { extend:'csv',  text:'CSV',   title: tableName, filename: fileName, exportOptions:{ columns:':visible' } },
        { extend:'excel',text:'Excel', title: tableName, filename: fileName, exportOptions:{ columns:':visible' } },
        { extend:'pdf',  text:'PDF',   title: tableName, filename: fileName, exportOptions:{ columns:':visible' } },
        { extend:'print',text:'Print', title: tableName,                  exportOptions:{ columns:':visible' } },
        { extend:'colvis', text:'Columns' }
      ],
      pageLength: 10
    });

    $('#search-specialty').off('input').on('input', function(){
      specialtiesTable.search(this.value).draw();
    });

  } else {
    $('#specialtiesTable tbody').html('<tr><td colspan="4">No specialties</td></tr>');
  }
}

async function saveSpecialty() {
  const inp = document.getElementById('specialty_name');
  const name = (inp?.value || '').trim();
  if (!name) return alert('Enter specialty name');

  const fd = new FormData();
  fd.append('action', 'save_specialty');
  fd.append('specialty_name', name);

  const res = await apiPOST(fd);
  showMsg('specialtyMsg', res);
  if (res.status === 'success') {
    if (inp) inp.value = '';
    loadSpecialtiesTable();
  }
}
async function deleteSelectedSpecialties(){
  const ids = Array.from(document.querySelectorAll('.spec-check:checked')).map(i=>i.value);
  if (!ids.length) return alert('Select specialties to delete');
  if (!confirm('Delete selected specialties?')) return;
  const fd = new FormData();
  fd.append('action','delete_specialties');
  ids.forEach(id => fd.append('specialties[]', id));
  const res = await apiPOST(fd);
  showMsg('specialtyMsg', res);
  loadSpecialtiesTable();
}

// ==========================
//  Backgrounds
// ==========================
async function uploadBackground(){
  const file = $('#background_image')[0].files[0];
  const page = $('#bg_page').val();
  if (!file) return alert('Select file');
  const fd = new FormData();
  fd.append('action','upload_background');
  fd.append('page', page);
  fd.append('background_image', file);
  const res = await apiPOST(fd);
  showMsg('bgMsg', res);
  loadBackgrounds();
}
async function loadBackgrounds(){
  const res = await apiGET({ action:'get_backgrounds' });
  const container = $('#backgroundsPreview');
  container.html('');
  if (res.status === 'success') {
    const bg = res.backgrounds || {};
    Object.keys(bg).forEach(k => {
      const url = bg[k].url;
      if (url) container.append('<div style="text-align:center"><div class="small">'+k+'</div><img src="'+escapeAttr(url)+'" style="max-width:800px;border-radius:8px"></div>');
    });
  }
}

// Specialty input capitalize
document.addEventListener('DOMContentLoaded', function(){
  const specialtyInput = document.getElementById('specialty_name');
  if (specialtyInput){
    specialtyInput.addEventListener('input', () => {
      const val = specialtyInput.value;
      if (val.length > 0) {
        specialtyInput.value = val.charAt(0).toUpperCase() + val.slice(1);
      }
    });
  }
});

// ==========================
//  Feature Toggles (local)
// ==========================
const defaultFeatureStates = { specialty:true, banner:true, background:true, logo:true, contact:true };
const featureStates = JSON.parse(localStorage.getItem('featureStates')) || { ...defaultFeatureStates };

function updateButtonState(button, isEnabled) {
  button.textContent = isEnabled ? "Disable" : "Enable";
  button.classList.remove("enabled", "disabled");
  button.classList.add(isEnabled ? "enabled" : "disabled");
}
function capitalize(str) { return str.charAt(0).toUpperCase() + str.slice(1).replace('_', ' '); }

function toggleSectionState(feature, isEnabled) {
  const section = document.querySelector(`[data-feature="${feature}"]`);
  if (!section) return;
  if (isEnabled) section.classList.remove('disabled-section');
  else section.classList.add('disabled-section');
  const elements = section.querySelectorAll('input, textarea, select, button');
  elements.forEach(el => { if (!el.classList.contains('toggle-btn')) el.disabled = !isEnabled; });
}
function toggleFeature(button) {
  const feature = button.getAttribute("data-feature");
  const isEnabled = featureStates[feature];
  featureStates[feature] = !isEnabled;
  localStorage.setItem('featureStates', JSON.stringify(featureStates));
  updateButtonState(button, featureStates[feature]);
  toggleSectionState(feature, featureStates[feature]);
  const msg = document.getElementById("featureToggleMsg");
  if (msg){
    msg.textContent = `${capitalize(feature)} ${featureStates[feature] ? 'enabled' : 'disabled'}!`;
    setTimeout(() => msg.textContent = "", 2000);
  }
}
function applyFeatureDisabling() {
  const toggles = JSON.parse(localStorage.getItem('feature_toggles')) || {};
  $('#specialtyForm button').prop('disabled', !(toggles.specialty ?? true));
  window.allowSpecialty = !!(toggles.specialty ?? true);
  $('#bannerForm button').prop('disabled', !(toggles.banner ?? true));
  window.allowBanner = !!(toggles.banner ?? true);
  $('#bgForm button').prop('disabled', !(toggles.background ?? true));
  window.allowBackground = !!(toggles.background ?? true);
  $('#logoForm button').prop('disabled', !(toggles.logo ?? true));
  window.allowLogo = !!(toggles.logo ?? true);
  $('#contactForm button').prop('disabled', !(toggles.contact ?? true));
  window.allowContact = !!(toggles.contact ?? true);
}

// ==========================
//  Feedback
// ==========================
function showMsg(id, res) {
  alert(`${res.status === 'success' ? '✅' : '❌'} ${res.message || (res.status === 'success' ? 'Success' : 'Error')}`);
}

// ==========================
//  Ads / Rotating Screens (revised)
// ==========================
async function adsLoad() {
  const res = await apiGET({ action: 'ads_list' });
  if (res?.status !== 'success') return;

  // Config
  $('#ads_interval_sec').val(res.config?.interval_sec ?? 12);
  $('#ads_enabled').val(String(!!(res.config?.enabled ?? true)));

  // Items
  const tbody = document.getElementById('adsTbody');
  tbody.innerHTML = '';
  const items = res.items || [];

  items.forEach((it, idx) => {
    // Render preview correctly for images or videos
    const previewHtml = (String(it.media_type) === 'video' && it.video_url)
      ? `<video src="${escapeAttr(it.video_url)}" style="max-width:160px;border-radius:6px" controls></video>`
      : `<img src="${escapeAttr(it.image_url || '')}" style="max-width:160px;border-radius:6px">`;

    const tr = document.createElement('tr');
    tr.dataset.id = it.id;
    tr.innerHTML = `
      <td style="white-space:nowrap;">
        <button class="inline-btn" onclick="adsMove(this,-1)">▲</button>
        <button class="inline-btn" onclick="adsMove(this, 1)">▼</button>
        <span class="small" style="margin-left:6px">${(it.order ?? (idx+1))}</span>
      </td>
      <td>${previewHtml}</td>
      <td><input class="ads-alt" type="text" value="${escapeAttr(it.alt || '')}" style="width:160px"></td>
      <td><input class="ads-href" type="url" value="${escapeAttr(it.href || '')}" style="width:240px"></td>
      <td>
        <select class="ads-active">
          <option value="true"${it.active ? ' selected':''}>Yes</option>
          <option value="false"${!it.active ? ' selected':''}>No</option>
        </select>
      </td>
      <td>
        <button class="inline-btn" onclick="adsSaveRow(this)">Save</button>
        <button class="delete-button" onclick="adsDelete('${it.id}')">Delete</button>
      </td>
    `;
    tbody.appendChild(tr);
  });

  // (Re)initialize DataTable with clearer paging
  if ($.fn.DataTable.isDataTable('#adsTable')) {
    $('#adsTable').DataTable().destroy();
  }
  $('#adsTable').DataTable({
    pageLength: 25,
    lengthMenu: [10, 25, 50, 100],
    order: [] // keep manual visual ordering
  });
}

async function adsSaveConfig() {
  const fd = new FormData();
  fd.append('action','ads_save_config');
  fd.append('interval_sec', $('#ads_interval_sec').val());
  fd.append('enabled', $('#ads_enabled').val());
  const res = await apiPOST(fd);
  showMsg('adsConfigMsg', res);
}

async function adsUpload() {
  const file = $('#ad_file')[0]?.files[0];
  if (!file) return alert('Select a media file (image or video)');

  const fd = new FormData();
  fd.append('action','ads_upload');
  fd.append('ad_file', file);
  fd.append('ad_href', $('#ad_href').val());
  fd.append('ad_alt',  $('#ad_alt').val());

  const res = await apiPOST(fd);
  showMsg('adsUploadMsg', res);
  if (res.status === 'success') {
    $('#ad_file').val(''); $('#ad_href').val(''); $('#ad_alt').val('');
    adsLoad();
  }
}

async function adsSaveRow(btn) {
  const tr = btn.closest('tr');
  const id = tr.dataset.id;
  const href = tr.querySelector('.ads-href').value;
  const alt  = tr.querySelector('.ads-alt').value;
  const active = tr.querySelector('.ads-active').value;
  const fd = new FormData();
  fd.append('action','ads_save_item');
  fd.append('id', id);
  fd.append('href', href);
  fd.append('alt', alt);
  fd.append('active', active);
  const res = await apiPOST(fd);
  showMsg(null, res);
}

async function adsDelete(id) {
  if (!confirm('Delete this ad?')) return;
  const fd = new FormData();
  fd.append('action','ads_delete');
  fd.append('id', id);
  const res = await apiPOST(fd);
  showMsg(null, res);
  if (res.status === 'success') adsLoad();
}

function adsMove(btn, dir) {
  const tr = btn.closest('tr');
  const tbody = tr.parentElement;
  const rows = Array.from(tbody.children);
  const i = rows.indexOf(tr);
  const j = i + dir;
  if (j < 0 || j >= rows.length) return;
  if (dir < 0) tbody.insertBefore(tr, rows[j]);
  else tbody.insertBefore(rows[j], tr);
}

async function adsSaveOrder() {
  const ids = Array.from(document.querySelectorAll('#adsTbody tr')).map(tr => tr.dataset.id);
  const fd = new FormData();
  fd.append('action','ads_reorder');
  ids.forEach(id => fd.append('order[]', id));
  const res = await apiPOST(fd);
  showMsg(null, res);
  if (res.status === 'success') adsLoad();
}

(function(){
  const burger  = document.getElementById('hamburger-menu');
  const menu    = document.querySelector('.menu');
  const overlay = document.getElementById('menu-overlay');

  function openMenu(){
    menu?.classList.add('active');
    burger?.classList.add('active');
    document.body.classList.add('menu-open');
  }
  function closeMenu(){
    menu?.classList.remove('active');
    burger?.classList.remove('active');
    document.body.classList.remove('menu-open');
  }

  burger?.addEventListener('click', () => {
    if (menu?.classList.contains('active')) closeMenu();
    else openMenu();
  });

  overlay?.addEventListener('click', closeMenu);
  document.addEventListener('keydown', (e)=>{ if(e.key === 'Escape') closeMenu(); });
})();

// ==========================
//  Init
// ==========================
$(document).ready(function(){
  // Feature toggle button visual state
  const buttons = document.querySelectorAll('.toggle-btn');
  buttons.forEach(button => {
    const feature = button.getAttribute('data-feature');
    const isEnabled = featureStates[feature];
    updateButtonState(button, isEnabled);
    toggleSectionState(feature, isEnabled);
  });

  // Initial data loads
  loadHome();
  loadAnnouncements();
  loadContact();
  loadBanner();
  populateDepartmentSelects();
  loadRoomsTable();
  loadBedsTable();         
  loadSpecialtiesTable();
  loadBackgrounds();
  loadLogo();
  loadTemplatePreview();
  adsLoad();
});
</script>

</body>
</html>