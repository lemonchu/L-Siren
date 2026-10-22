<?php
/** Local APNIC CN membership. No HTTP requests or database writes at request time. */

/** Return packed bytes, including IPv4-mapped IPv6 and bracketed IPv6 literals. */
function siren_ip_pack($ip)
{
    if (!is_string($ip)) {
        return false;
    }
    $ip = trim($ip);
    if (strlen($ip) >= 2 && $ip[0] === '[' && substr($ip, -1) === ']') {
        $ip = substr($ip, 1, -1);
    }
    $packed = @inet_pton($ip);
    if ($packed !== false && strlen($packed) === 16 &&
        substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
        return substr($packed, 12);
    }
    return $packed;
}

/** Validate once when loading; refuse malformed or unordered databases. */
function siren_ip_valid_database($data)
{
    if (!is_array($data) || !isset($data['version']) || $data['version'] !== 1) {
        return false;
    }
    foreach (array('ipv4' => 8, 'ipv6' => 32) as $family => $length) {
        if (empty($data[$family]) || !is_array($data[$family]) || !array_is_list($data[$family])) {
            return false;
        }
        $previous_end = null;
        $pattern = '/\A[0-9a-f]{' . $length . '}\z/';
        foreach ($data[$family] as $range) {
            if (!is_array($range) || count($range) !== 2 || !isset($range[0], $range[1]) ||
                !is_string($range[0]) || !is_string($range[1]) ||
                !preg_match($pattern, $range[0]) || !preg_match($pattern, $range[1]) ||
                strcmp($range[0], $range[1]) > 0 ||
                ($previous_end !== null && strcmp($previous_end, $range[0]) >= 0)) {
                return false;
            }
            $previous_end = $range[1];
        }
    }
    return true;
}

function siren_ip_database($path)
{
    static $loaded = array();
    if (array_key_exists($path, $loaded)) {
        return $loaded[$path];
    }
    $loaded[$path] = false;
    $stat = @stat($path);
    if (!$stat || $stat['size'] > 16 * 1024 * 1024) {
        return false;
    }
    // Atomic replacement changes the inode even if size/mtime happen to match.
    $key = hash('sha256', $path . ':' . $stat['ino'] . ':' . $stat['mtime'] . ':' . $stat['size']);
    $external_cache = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
    if ($external_cache) {
        $data = wp_cache_get($key, 'siren_ip_location');
        if (is_array($data)) {
            return $loaded[$path] = $data;
        }
    }
    $json = @file_get_contents($path);
    if ($json === false) {
        return false;
    }
    $data = json_decode($json, true);
    if (!siren_ip_valid_database($data)) {
        return false;
    }
    if ($external_cache) {
        wp_cache_set($key, $data, 'siren_ip_location', 86400);
    }
    return $loaded[$path] = $data;
}

/** Binary search on fixed-width network-order hex, without integer overflow. */
function siren_ip_is_china($ip, $database_path = null)
{
    $packed = siren_ip_pack($ip);
    if ($packed === false) {
        return false;
    }
    $path = $database_path === null ? dirname(__DIR__) . '/iplib2/cn-ranges.json' : $database_path;
    $data = siren_ip_database($path);
    if ($data === false) {
        return false;
    }
    $family = strlen($packed) === 4 ? 'ipv4' : 'ipv6';
    $needle = bin2hex($packed);
    $low = 0;
    $high = count($data[$family]) - 1;
    while ($low <= $high) {
        $mid = $low + intdiv($high - $low, 2);
        $range = $data[$family][$mid];
        if (strcmp($needle, $range[0]) < 0) {
            $high = $mid - 1;
        } elseif (strcmp($needle, $range[1]) > 0) {
            $low = $mid + 1;
        } else {
            return true;
        }
    }
    return false;
}

/** Retain the original zh-C language gate; use only the connection's source IP. */
function siren_visitor_is_china($server, $database_path = null)
{
    $language = isset($server['HTTP_ACCEPT_LANGUAGE']) && is_string($server['HTTP_ACCEPT_LANGUAGE'])
        ? $server['HTTP_ACCEPT_LANGUAGE'] : '';
    if (strcasecmp(substr($language, 0, 4), 'zh-c') !== 0) {
        return false;
    }
    $ip = isset($server['REMOTE_ADDR']) ? $server['REMOTE_ADDR'] : '';
    return siren_ip_is_china($ip, $database_path);
}
