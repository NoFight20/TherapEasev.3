<?php
return [
    'ip_restriction_enabled' => false,
    
    'allowed_ips' => [
        '', // Hospital network IP
        '::1',            // IPv6 localhost
        '127.0.0.1'       // IPv4 localhost
    ],
    'firebase' => [
        'api_key' => 'AIzaSyA6V_QllmMscDmkRfz1r__ZR7MGQ9bWXys',
        'auth_domain' => 'therapease-d9525.firebaseapp.com',
        'database_url' => 'https://therapease-d9525-default-rtdb.asia-southeast1.firebasedatabase.app',
        'project_id' => 'therapease-d9525',
        'storage_bucket' => 'therapease-d9525.appspot.com',
        'messaging_sender_id' => '364435816698',
        'app_id' => '1:364435816698:web:ad0aace7cbb55dbfb04943',
    ],
    'timezone' => 'Asia/Manila',
    'error_log_path' => 'path_to_error.log',
];
