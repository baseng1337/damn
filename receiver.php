<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$secret = 'wtf';
$file   = 'result.json';

if (isset($_POST['action']) && $_POST['action'] === 'register_site') {
    if ($_POST['secret'] !== $secret) exit;

    $domain = filter_var($_POST['domain'], FILTER_SANITIZE_URL);
    $key    = parse_url($domain, PHP_URL_HOST) ?: $domain;
    
    // Tangkap data input baru
    $input_user = isset($_POST['api_user']) ? trim($_POST['api_user']) : '';
    $input_pass = isset($_POST['api_pass']) ? trim($_POST['api_pass']) : '';
    
    // Load database lama
    $data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    if (!is_array($data)) $data = [];

    // LOGIKA PERBAIKAN: Jaga data lama jika input baru kosong
    $final_user = $input_user;
    $final_pass = $input_pass;
    $old_date   = date('Y-m-d H:i:s');

    if (isset($data[$key])) {
        // Jika input kosong tapi di database sudah ada, pakai yang lama
        if (empty($final_user) && !empty($data[$key]['api_user'])) {
            $final_user = $data[$key]['api_user'];
        }
        if (empty($final_pass) && !empty($data[$key]['api_pass'])) {
            $final_pass = $data[$key]['api_pass'];
        }
        // Pertahankan tanggal lama jika ada
        if (isset($data[$key]['date_added'])) {
            $old_date = $data[$key]['date_added'];
        }
    }

    // Update data
    $data[$key] = [
        'domain'   => $domain,
        'api_user' => $final_user,
        'api_pass' => $final_pass,
        'date_added' => $old_date // Tanggal tidak berubah-ubah saat di-ping
    ];
    
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}
?>
