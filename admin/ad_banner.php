<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../connection.php'; // adjust the path if needed

try {
    // Load items (uploaded by your ads_upload) and config
    $items = $database->getReference('site/ads/items')->getValue() ?: [];
    $config = $database->getReference('site/ads/config')->getValue() ?: [];

    $interval = (int)($config['interval_sec'] ?? 12);
    $enabled  = !isset($config['enabled']) ? true : (bool)$config['enabled'];

    // Normalize to array and filter
    $arr = [];
    foreach ($items as $id => $v) {
        if (!empty($v['active']) && !empty($v['image_url'])) {
            $arr[] = [
                'order'     => (int)($v['order'] ?? 0),
                'image_url' => (string)$v['image_url'],
                'href'      => (string)($v['href'] ?? ''),
                'alt'       => (string)($v['alt'] ?? 'Announcement'),
            ];
        }
    }

    // Sort by 'order'
    usort($arr, fn($a,$b) => $a['order'] <=> $b['order']);

    // Shape the public payload
    $payload = [
        'ads'          => array_map(fn($x) => [
            'image_url' => $x['image_url'],
            'href'      => $x['href'],
            'alt'       => $x['alt'],
        ], $arr),
        'interval_sec' => $interval,
        'enabled'      => $enabled,
    ];

    echo json_encode($payload);
    exit;

} catch (Throwable $e) {
    echo json_encode([
        'ads'          => [],
        'interval_sec' => 12,
        'enabled'      => false,
        'error'        => $e->getMessage(),
    ]);
    exit;
}
