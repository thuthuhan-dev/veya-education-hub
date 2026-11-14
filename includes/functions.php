<?php
// includes/functions.php — Shared helpers for Veya
// Auto-loaded by db_connect.php. All functions are guarded with
// function_exists() to prevent "Cannot redeclare" errors if a page
// already defined them locally.

if (!function_exists('convert_fee')) {
    function convert_fee($amount, $from, $to, $rates) {
        if ($from === $to) return $amount;
        if (isset($rates[$from][$to])) return $amount * $rates[$from][$to];
        if (isset($rates[$from]['USD']) && isset($rates['USD'][$to]))
            return $amount * $rates[$from]['USD'] * $rates['USD'][$to];
        if (isset($rates[$from]['MMK']) && isset($rates['MMK'][$to]))
            return $amount * $rates[$from]['MMK'] * $rates['MMK'][$to];
        return null;
    }
}

if (!function_exists('format_fee')) {
    function format_fee($amount, $currency, $symbols) {
        if ($amount === null) return null;
        $sym = $symbols[$currency] ?? $currency . ' ';
        if ($amount >= 1000000) return $sym . number_format($amount / 1000000, 2) . 'M';
        if ($amount >= 1000)    return $sym . number_format($amount / 1000, 1) . 'K';
        return $sym . number_format($amount, 0);
    }
}

if (!function_exists('load_exchange_rates')) {
    function load_exchange_rates($conn) {
        $rates = [];
        $res = $conn->query("SELECT from_currency, to_currency, rate FROM exchange_rates");
        while ($r = $res->fetch_assoc())
            $rates[$r['from_currency']][$r['to_currency']] = (float)$r['rate'];
        return $rates;
    }
}

if (!function_exists('load_currency_symbols')) {
    function load_currency_symbols($conn) {
        $symbols = [];
        $res = $conn->query("SELECT currency_code, symbol FROM currencies");
        while ($r = $res->fetch_assoc())
            $symbols[$r['currency_code']] = $r['symbol'];
        return $symbols;
    }
}

if (!function_exists('safe_img')) {
    function safe_img($raw, $fallback = 'https://images.unsplash.com/photo-1562774053-701939374585?auto=format&fit=crop&w=800&q=80') {
        $img = trim($raw ?? '');
        if (empty($img)) return $fallback;
        $img = str_replace('\\', '/', $img);
        return str_starts_with($img, 'http') ? $img : htmlspecialchars($img, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('country_flag')) {
    function country_flag($code) {
        return ['TH' => '🇹🇭', 'SG' => '🇸🇬', 'MY' => '🇲🇾'][$code] ?? '🌐';
    }
}

if (!function_exists('truncate')) {
    function truncate($text, $length = 120) {
        $text = strip_tags($text);
        return mb_strlen($text) > $length ? mb_substr($text, 0, $length) . '…' : $text;
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token() {
        if (empty($_SESSION['csrf_token']))
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field() {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
    }
}

if (!function_exists('csrf_verify')) {
    function csrf_verify() {
        $token = $_POST['csrf_token'] ?? '';
        return !empty($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
    }
}

if (!function_exists('time_ago')) {
    function time_ago($datetime) {
        $diff = time() - strtotime($datetime);
        if ($diff < 60)     return 'Just now';
        if ($diff < 3600)   return floor($diff / 60) . ' min ago';
        if ($diff < 86400)  return floor($diff / 3600) . ' hr ago';
        if ($diff < 604800) return floor($diff / 86400) . ' days ago';
        return date('d M Y', strtotime($datetime));
    }
}