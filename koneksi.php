<?php
// Koneksi Database untuk E-Recepsionis System
// Prioritas: konstanta di config.local.php → environment → default lokal (MAMP)

$dbHost = defined('DB_HOST') ? (string) DB_HOST : (getenv('DB_HOST') ?: 'localhost');
$dbUser = defined('DB_USER') ? (string) DB_USER : (getenv('DB_USER') ?: 'root');
$dbPass = defined('DB_PASS') ? (string) DB_PASS : (getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : 'root');
$dbName = defined('DB_NAME') ? (string) DB_NAME : (getenv('DB_NAME') ?: 'recepsionis_db');

// Catatan: config.local.php di-load dari config.php SETELAH koneksi.php.
// Agar override DB berfungsi di hosting, load config.local lebih awal di sini jika ada.
$configLocalEarly = dirname(__FILE__) . '/config.local.php';
if (is_file($configLocalEarly) && !defined('RECEPSIONIS_CONFIG_LOCAL_LOADED')) {
    require_once $configLocalEarly;
    if (!defined('RECEPSIONIS_CONFIG_LOCAL_LOADED')) {
        define('RECEPSIONIS_CONFIG_LOCAL_LOADED', true);
    }
    $dbHost = defined('DB_HOST') ? (string) DB_HOST : $dbHost;
    $dbUser = defined('DB_USER') ? (string) DB_USER : $dbUser;
    $dbPass = defined('DB_PASS') ? (string) DB_PASS : $dbPass;
    $dbName = defined('DB_NAME') ? (string) DB_NAME : $dbName;
}

$host = $dbHost;
$user = $dbUser;
$pass = $dbPass;
$dbname = $dbName;

$koneksi = @new mysqli($host, $user, $pass, $dbname);

if ($koneksi->connect_error) {
    http_response_code(503);
    die('Koneksi database gagal. Periksa config.local.php atau kredensial MySQL.');
}

$koneksi->set_charset('utf8mb4');

function esc($string)
{
    global $koneksi;
    return $koneksi->real_escape_string($string);
}

function getHariIni()
{
    $hari_en = date('l');
    $hari_id = [
        'Monday' => 'Senin',
        'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu',
        'Thursday' => 'Kamis',
        'Friday' => 'Jumat',
        'Saturday' => 'Sabtu',
        'Sunday' => 'Minggu',
    ];
    return $hari_id[$hari_en];
}

function formatTanggal($date)
{
    $hari = getHariIni();
    $tanggal = date('d', strtotime($date));
    $bulan = [
        '01' => 'Januari', '02' => 'Februari', '03' => 'Maret',
        '04' => 'April', '05' => 'Mei', '06' => 'Juni',
        '07' => 'Juli', '08' => 'Agustus', '09' => 'September',
        '10' => 'Oktober', '11' => 'November', '12' => 'Desember',
    ];
    $bulan_str = $bulan[date('m', strtotime($date))];
    $tahun = date('Y', strtotime($date));
    return "$hari, $tanggal $bulan_str $tahun";
}

function formatWaktu($time)
{
    return date('H:i', strtotime($time));
}
