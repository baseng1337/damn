<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$secret = 'wtf';
$file   = 'result.json';

if (isset($_POST['action']) && $_POST['action'] === 'register_site') {
    if ($_POST['secret'] !== $secret) exit;

    $domain = filter_var($_POST['domain'], FILTER_SANITIZE_URL);
    $key    = parse_url($domain, PHP_URL_HOST) ?: $domain;
    
    $data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    
    if (!is_array($data)) $data = [];

    // Jika domain belum ada, tambahkan.
    if (!isset($data[$key])) {
        $data[$key] = [
            'domain'   => $domain,
            'api_user' => '',
            'api_pass' => '',
            'date_added' => date('Y-m-d H:i:s')
        ];
        // Tambahkan JSON_PRETTY_PRINT agar rapi
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    }
}
?>
