<?php
/** On-demand China Mobile Cloud video previews. */

function siren_cloud_video_settings(): array
{
    return array_merge(array('enabled' => false, 'auth' => '', 'cookie' => '',
        'host' => 'https://personal-kd-njs.yun.139.com', 'minutes' => 60, 'videos' => array()),
        get_option('siren_cloud_video', array()));
}

function siren_cloud_video_file_id(string $value): string
{
    if (preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $value)) {
        return $value;
    }
    $parts = wp_parse_url($value);
    if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'yun.139.com') {
        return '';
    }
    parse_str($parts['query'] ?? '', $query);
    parse_str(explode('?', $parts['fragment'] ?? '', 2)[1] ?? '', $fragment);
    $id = $fragment['contentID'] ?? $query['contentID'] ?? '';
    return is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $id) ? $id : '';
}

function siren_cloud_video_url(string $id): string
{
    return add_query_arg(array('action' => 'siren_cloud_video_play', 'video' => $id), admin_url('admin-ajax.php'));
}

function siren_cloud_video_cache_key(array $settings, string $id): string
{
    return 'siren_video_' . md5(wp_json_encode(array(
        $settings['host'], $settings['auth'], $settings['cookie'], $settings['minutes'])) . ':' . $id);
}

function siren_cloud_video_sign(string $body, string $timestamp, string $random): string
{
    // Match the web client's encodeURIComponent / sort / Base64 / MD5 sequence.
    $encoded = strtr(rawurlencode($body), array('%21' => '!', '%27' => "'",
        '%28' => '(', '%29' => ')', '%2A' => '*'));
    $characters = str_split($encoded);
    sort($characters, SORT_STRING);
    return strtoupper(md5(md5(base64_encode(implode('', $characters))) . md5($timestamp . ':' . $random)));
}

function siren_cloud_video_unlock(string $lock, array $owner)
{
    global $wpdb;
    if ($wpdb->delete($wpdb->options, array('option_name' => $lock, 'option_value' => maybe_serialize($owner)))) {
        wp_cache_delete($lock, 'options');
    }
}

function siren_cloud_video_request(array $settings, string $id)
{
    $body = wp_json_encode(array('fileId' => $id, 'category' => 'video'), JSON_UNESCAPED_SLASHES);
    $timestamp = wp_date('Y-m-d H:i:s', null, new DateTimeZone('Asia/Shanghai'));
    $random = wp_generate_password(16, false, false);
    $headers = array(
        'Authorization' => $settings['auth'], 'Content-Type' => 'application/json;charset=UTF-8',
        'Accept' => 'application/json, text/plain, */*', 'Caller' => 'web', 'CMS-DEVICE' => 'default',
        'mcloud-channel' => '1000101', 'mcloud-client' => '10701', 'mcloud-route' => '001',
        'mcloud-version' => '7.18.1',
        'mcloud-sign' => $timestamp . ',' . $random . ',' . siren_cloud_video_sign($body, $timestamp, $random),
        'Origin' => 'https://yun.139.com', 'Referer' => 'https://yun.139.com/',
        'X-DeviceInfo' => '||9|7.18.1|chrome|141.0.0.0|||linux||zh-CN|||',
        'x-huawei-channelSrc' => '10000034', 'x-inner-ntwk' => '2', 'x-m4c-caller' => 'PC',
        'x-m4c-src' => '10002', 'x-SvcType' => '1', 'INNER-HCY-ROUTER-HTTPS' => '1',
        'x-yun-api-version' => 'v1', 'x-yun-app-channel' => '10000034',
        'x-yun-channel-source' => '10000034', 'x-yun-module-type' => '100',
        'x-yun-client-info' => '||9|7.18.1|chrome|141.0.0.0|||linux||zh-CN|||Y2hyb21l||');
    if ($settings['cookie'] !== '') {
        $headers['Cookie'] = $settings['cookie'];
    }
    // Never forward account credentials through an upstream redirect.
    $response = wp_remote_post($settings['host'] . '/hcy/videoPreview/getPreviewInfo', array(
        'headers' => $headers, 'body' => $body, 'timeout' => 15, 'redirection' => 0,
        'limit_response_size' => 256 * 1024));
    if (is_wp_error($response)) {
        return new WP_Error('cloud_video_network', '连接移动云盘失败，请稍后重试。', array('status' => 502));
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    $url = $data['data']['previewInfo']['url'] ?? '';
    if (wp_remote_retrieve_response_code($response) !== 200 || empty($data['success']) ||
        !is_string($url) || !wp_http_validate_url($url) || wp_parse_url($url, PHP_URL_SCHEME) !== 'https') {
        return new WP_Error('cloud_video_preview', '未能取得播放地址，请检查云盘授权和文件是否可预览。', array('status' => 502));
    }
    $now = time();
    $expires = $now + $settings['minutes'] * MINUTE_IN_SECONDS;
    parse_str(wp_parse_url($url, PHP_URL_QUERY) ?? '', $query);
    if (isset($query['X-Amz-Date'], $query['X-Amz-Expires']) &&
        is_string($query['X-Amz-Date']) && is_scalar($query['X-Amz-Expires'])) {
        $date = DateTimeImmutable::createFromFormat('!Ymd\THis\Z', $query['X-Amz-Date'], new DateTimeZone('UTC'));
        if ($date) {
            $expires = min($expires, $date->getTimestamp() + (int) $query['X-Amz-Expires'] - 300);
        }
    }
    if ($expires <= $now) {
        return new WP_Error('cloud_video_expired', '云盘返回的播放地址已过期，请更新授权后重试。', array('status' => 502));
    }
    return array('url' => $url, 'updated' => $now, 'refresh_at' => $expires);
}

function siren_cloud_video_preview(string $id, bool $force = false)
{
    $settings = siren_cloud_video_settings();
    if (!in_array($id, $settings['videos'], true)) {
        return new WP_Error('cloud_video_missing', '未配置这个视频。', array('status' => 404));
    }
    if ($settings['auth'] === '') {
        return new WP_Error('cloud_video_auth', '尚未配置移动云盘授权。', array('status' => 503));
    }
    $key = siren_cloud_video_cache_key($settings, $id);
    $cached = get_transient($key);
    if (!$force && $cached !== false) {
        return $cached;
    }
    $failure = get_transient($key . '_error');
    if (!$force && $failure !== false) {
        return new WP_Error('cloud_video_retry', $failure, array('status' => 503));
    }
    // One upstream request per video, including when several visitors arrive together.
    $lock = $key . '_lock';
    $owner = array('time' => time(), 'token' => wp_generate_uuid4());
    $current = get_option($lock);
    if ($current && $current['time'] < time() - 45) {
        siren_cloud_video_unlock($lock, $current);
    }
    if (!add_option($lock, $owner, '', false)) {
        return new WP_Error('cloud_video_busy', '播放地址正在更新，请稍后重试。', array('status' => 503));
    }
    try {
        $result = siren_cloud_video_request($settings, $id);
        if (is_wp_error($result)) {
            set_transient($key . '_error', $result->get_error_message(), 30);
            return $result;
        }
        set_transient($key, $result, max(1, $result['refresh_at'] - time()));
        delete_transient($key . '_error');
        return $result;
    } finally {
        siren_cloud_video_unlock($lock, $owner);
    }
}

function siren_cloud_video_play()
{
    nocache_headers();
    if (!siren_cloud_video_settings()['enabled']) {
        wp_send_json_error(array('message' => '云盘视频未启用。'), 404);
    }
    $id = sanitize_text_field(wp_unslash($_GET['video'] ?? ''));
    $result = siren_cloud_video_preview($id);
    if (is_wp_error($result)) {
        header('Retry-After: 5');
        wp_send_json_error(array('message' => $result->get_error_message()), $result->get_error_data()['status']);
    }
    wp_redirect($result['url'], 302, 'L-Siren cloud video');
    exit;
}

add_action('wp_ajax_siren_cloud_video_play', 'siren_cloud_video_play');
add_action('wp_ajax_nopriv_siren_cloud_video_play', 'siren_cloud_video_play');

add_action('wp_ajax_siren_save_cloud_video', function () {
    if (!current_user_can('manage_options') || !check_ajax_referer('siren-cloud-video', 'nonce', false)) {
        wp_send_json_error(array('message' => '没有操作权限或页面验证已过期，请刷新后重试。'), 403);
    }
    foreach (array('host', 'videos', 'auth', 'cookie', 'minutes') as $field) {
        if (isset($_POST[$field]) && !is_string($_POST[$field])) {
            wp_send_json_error(array('message' => '配置格式错误，请刷新后重试。'), 400);
        }
    }
    $old = siren_cloud_video_settings();
    $host = rtrim(trim(wp_unslash($_POST['host'] ?? '')), '/');
    if (!preg_match('~^https://personal(?:-[a-z0-9]+)*\.yun\.139\.com$~D', $host)) {
        wp_send_json_error(array('message' => '请填写移动云盘 personal 服务器的 HTTPS 地址，不含路径。'), 400);
    }
    $videos = array();
    foreach (preg_split('/\R/', trim(wp_unslash($_POST['videos'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) as $line) {
        $id = siren_cloud_video_file_id(trim($line));
        if ($id === '') {
            wp_send_json_error(array('message' => '视频列表中有无效地址，请填写云盘播放页链接或文件 ID。'), 400);
        }
        $videos[] = $id;
    }
    $auth = trim(wp_unslash($_POST['auth'] ?? ''));
    $cookie = trim(wp_unslash($_POST['cookie'] ?? ''));
    if (($auth !== '' && !preg_match('~^Basic [A-Za-z0-9+/]+=*$~D', $auth)) || preg_match('/[\r\n]/', $cookie)) {
        wp_send_json_error(array('message' => 'Auth 需要包含 Basic 前缀，Cookie 请填写一整行。'), 400);
    }
    $settings = array('enabled' => !empty($_POST['enabled']), 'host' => $host,
        'minutes' => max(5, min(1440, absint($_POST['minutes'] ?? 60))),
        'videos' => array_values(array_unique($videos)),
        'auth' => $auth !== '' ? $auth : $old['auth'], 'cookie' => $cookie !== '' ? $cookie : $old['cookie']);
    if (!empty($_POST['clear_credentials'])) {
        $settings['auth'] = $settings['cookie'] = '';
    }
    if ($settings['enabled'] && ($settings['auth'] === '' || !$settings['videos'])) {
        wp_send_json_error(array('message' => '启用前请填写 Auth 和至少一个视频。'), 400);
    }
    update_option('siren_cloud_video', $settings, false);
    $retained = array_flip($settings['videos']);
    foreach ($old['videos'] as $id) {
        $key = siren_cloud_video_cache_key($old, $id);
        if (isset($retained[$id]) && $key === siren_cloud_video_cache_key($settings, $id)) {
            continue;
        }
        delete_transient($key);
        delete_transient($key . '_error');
    }
    wp_send_json_success(array('message' => '移动云盘配置已保存。'));
});

add_action('wp_ajax_siren_refresh_cloud_video', function () {
    if (!current_user_can('manage_options') || !check_ajax_referer('siren-cloud-video', 'nonce', false)) {
        wp_send_json_error(array('message' => '没有操作权限或页面验证已过期，请刷新后重试。'), 403);
    }
    $result = siren_cloud_video_preview(sanitize_text_field(wp_unslash($_POST['video'] ?? '')), true);
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()), $result->get_error_data()['status']);
    }
    wp_send_json_success(array('message' => '播放地址已更新，下次检查时间：' . wp_date('Y-m-d H:i:s', $result['refresh_at'])));
});
