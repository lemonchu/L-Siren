<?php
/** Maintenance tools and runtime configuration. */

require_once __DIR__ . '/operations-cloud-video.php';

add_action('admin_menu', function () {
    add_theme_page('L-Siren 运维仪表板', 'L-Siren 运维仪表板', 'manage_options',
        'l-siren-operations', 'siren_operations_page');
});

add_action('admin_bar_menu', function ($bar) {
    if (current_user_can('manage_options')) {
        $bar->add_node(array('id' => 'l-siren-operations', 'title' => 'L-Siren 运维仪表板',
            'href' => admin_url('themes.php?page=l-siren-operations')));
    }
}, 90);

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'appearance_page_l-siren-operations') {
        return;
    }
    foreach (array('style' => '/inc/css/operations.css', 'script' => '/js/operations.js') as $type => $file) {
        $url = add_query_arg('v', filemtime(get_template_directory() . $file), get_template_directory_uri() . $file);
        if ($type === 'style') {
            wp_enqueue_style('siren-operations', $url, array(), null);
        } else {
            wp_enqueue_script('siren-operations', $url, array(), null, true);
        }
    }
    wp_localize_script('siren-operations', 'sirenOperations', array(
        'url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('siren-update-ip-database'),
        'testNonce' => wp_create_nonce('siren-test-ip'), 'captchaNonce' => wp_create_nonce('siren-initialize-captcha'),
        'cloudNonce' => wp_create_nonce('siren-cloud-video')));
});

function siren_operations_ip_status(array $data, string $path): array
{
    clearstatcache(true, $path);
    return array('serial' => $data['source_serial'],
        'updated' => wp_date('Y-m-d H:i:s', filemtime($path)),
        'ipv4' => number_format_i18n(count($data['ipv4'])),
        'ipv6' => number_format_i18n(count($data['ipv6'])));
}

function siren_operations_page()
{
    global $wpdb;
    if (!current_user_can('manage_options')) {
        wp_die('你没有访问此页面的权限。');
    }
    $categories = array_values(array_filter(_optionsframework_options(),
        static fn($option) => $option['type'] === 'heading'));
    $tab = isset($_GET['tab']) ? absint($_GET['tab']) : 0;
    $tab = isset($categories[$tab]) ? $tab : 0;
    $icons = array('admin-generic', 'admin-home', 'admin-comments', 'heart', 'flag', 'admin-page', 'admin-users');
    ?>
    <div class="wrap siren-operations">
        <h1>L-Siren 运维仪表板</h1>
        <p>维护工具和运行配置，每项单独操作。</p>
        <nav class="nav-tab-wrapper" aria-label="运维分类">
            <?php foreach ($categories as $index => $category) : ?>
                <a class="nav-tab<?php echo $tab === $index ? ' nav-tab-active' : ''; ?>"
                    <?php if ($tab === $index) echo 'aria-current="page"'; ?>
                    href="<?php echo esc_url(add_query_arg(array('page' => 'l-siren-operations', 'tab' => $index), admin_url('themes.php'))); ?>"
                ><span class="dashicons dashicons-<?php echo esc_attr($icons[$index] ?? 'admin-tools'); ?>" aria-hidden="true"></span><?php echo esc_html($category['name']); ?></a>
            <?php endforeach; ?>
        </nav>
        <section class="siren-operations-panel">
            <?php if ($tab === 0) :
                $path = get_template_directory() . '/iplib2/cn-ranges.json';
                $data = siren_ip_database($path);
                $status = $data ? siren_operations_ip_status($data, $path) :
                    array('serial' => '尚未生成', 'updated' => '—', 'ipv4' => '—', 'ipv6' => '—');
                ?>
                <h2>中国 IP 地址库</h2>
                <p>从 APNIC 下载最新数据，整理中国 IPv4、IPv6 地址范围，供主题判断访客来源使用。</p>
                <dl class="siren-ip-status">
                    <?php foreach (array('serial' => 'APNIC 数据日期', 'updated' => '本地更新时间',
                        'ipv4' => 'IPv4 网段', 'ipv6' => 'IPv6 网段') as $key => $label) : ?>
                        <div><dt><?php echo esc_html($label); ?></dt>
                            <dd data-ip-status="<?php echo esc_attr($key); ?>"><?php echo esc_html($status[$key]); ?></dd></div>
                    <?php endforeach; ?>
                </dl>
                <div class="siren-download-proxy">
                    <label for="siren-ip-proxy">下载代理 <span>（选填，仅本次使用）</span></label>
                    <input type="text" id="siren-ip-proxy" placeholder="http://127.0.0.1:7890"
                        autocomplete="off" spellcheck="false" aria-describedby="siren-ip-proxy-help">
                    <p class="description" id="siren-ip-proxy-help">填写服务器能连接的 HTTP、HTTPS 或 SOCKS5 代理地址，留空不指定代理。这里的 127.0.0.1 指网站服务器。</p>
                </div>
                <button type="button" class="button button-primary" id="siren-update-ip">更新 IP 库</button>
                <p class="description">只在点击时更新。更新期间继续使用旧库，成功后自动替换；也可以通过 SSH 运行 get_latest.sh。</p>
                <div id="siren-ip-result" class="siren-operation-result" hidden>
                    <div class="siren-operation-message" role="status" aria-live="polite" aria-atomic="true">
                        <span class="spinner" aria-hidden="true"></span><span id="siren-ip-message"></span>
                    </div>
                    <p id="siren-ip-details" hidden></p>
                </div>
                <form id="siren-test-ip-form" class="siren-ip-test">
                    <h2><label for="siren-test-ip-address">测试 IP</label></h2>
                    <p class="description" id="siren-test-ip-help">查询当前本地库，支持 IPv4、IPv6。这里只判断 IP 范围，不检查浏览器语言。</p>
                    <div class="siren-ip-test-controls">
                        <input type="text" id="siren-test-ip-address" name="ip" placeholder="例如：223.5.5.5 或 2400:3200::1"
                            required autocomplete="off" spellcheck="false" aria-describedby="siren-test-ip-help">
                        <button type="submit" class="button button-primary">测试 IP</button>
                    </div>
                    <div id="siren-test-ip-result" class="siren-operation-result" role="status" aria-live="polite" aria-atomic="true" hidden></div>
                </form>
            <?php elseif ($tab === 1) : ?>
                <?php siren_operations_cloud_video(); ?>
            <?php elseif ($tab === 2) : $table_exists = siren_captcha_table_exists(); ?>
                <h2>评论邮箱验证码</h2>
                <p>首次使用时，创建验证码所需的数据表。已有表不会清空或修改。</p>
                <dl class="siren-ip-status">
                    <div><dt>数据表</dt><dd><?php echo esc_html($wpdb->prefix . 'captcha'); ?></dd></div>
                    <div><dt>状态</dt><dd id="siren-captcha-table-status"><?php echo $table_exists ? '已存在' : '尚未创建'; ?></dd></div>
                </dl>
                <button type="button" class="button button-primary" id="siren-initialize-captcha" <?php disabled($table_exists); ?>>初始化验证码表</button>
                <p class="description"><?php echo $table_exists ? '验证码表已存在，无需初始化。' : '建表后还需要配置邮件服务，访客才能收到验证码。'; ?></p>
                <div id="siren-captcha-result" class="siren-operation-result" role="status" aria-live="polite" aria-atomic="true" hidden></div>
            <?php else : ?>
                <h2><?php echo esc_html($categories[$tab]['name']); ?></h2>
                <p>这里暂时没有运维操作。</p>
            <?php endif; ?>
        </section>
    </div>
    <?php
}

add_action('wp_ajax_siren_update_ip_database', 'siren_operations_update_ip');
add_action('wp_ajax_siren_test_ip', 'siren_operations_test_ip');
add_action('wp_ajax_siren_initialize_captcha', 'siren_operations_initialize_captcha');

function siren_operations_initialize_captcha()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => '你没有初始化验证码表的权限。'), 403);
    }
    if (!check_ajax_referer('siren-initialize-captcha', 'nonce', false)) {
        wp_send_json_error(array('message' => '页面验证已过期，请刷新后重试。'), 403);
    }
    $result = siren_captcha_initialize_table();
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()), 500);
    }
    wp_send_json_success(array('message' => '验证码表已就绪，已有记录保持不变。'));
}

function siren_operations_test_ip()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => '你没有测试 IP 的权限。'), 403);
    }
    if (!check_ajax_referer('siren-test-ip', 'nonce', false)) {
        wp_send_json_error(array('message' => '页面验证已过期，请刷新后重试。'), 403);
    }
    $packed = siren_ip_pack(wp_unslash($_POST['ip'] ?? ''));
    if ($packed === false) {
        wp_send_json_error(array('message' => '请输入有效的 IPv4 或 IPv6 地址。'), 400);
    }
    $path = get_template_directory() . '/iplib2/cn-ranges.json';
    $data = siren_ip_database($path);
    if ($data === false) {
        wp_send_json_error(array('message' => '当前 IP 库不可用，请先更新 IP 库。'), 500);
    }
    $ip = inet_ntop($packed);
    $china = siren_ip_is_china($ip, $path);
    wp_send_json_success(array('china' => $china, 'message' => $ip . '：' .
        ($china ? '在当前库的中国 IP 范围内。' : '未命中当前库的中国 IP 范围。') .
        '（APNIC ' . $data['source_serial'] . '）'));
}

function siren_operations_download_ip(string $source, string $proxy)
{
    if ($proxy !== '') {
        $parts = wp_parse_url($proxy);
        if (!$parts || empty($parts['host']) ||
            !in_array($parts['scheme'] ?? '', array('http', 'https', 'socks5', 'socks5h'), true) ||
            !in_array($parts['path'] ?? '', array('', '/'), true) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('代理地址格式错误，请填写 http://、https:// 或 socks5:// 开头的地址。');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('代理下载需要 PHP cURL 扩展。');
        }
    }
    // Apply only to this download; do not change WordPress's global proxy settings.
    $configure_proxy = static function ($handle) use ($proxy) {
        curl_setopt($handle, CURLOPT_PROXY, $proxy);
    };
    if ($proxy !== '') {
        add_action('http_api_curl', $configure_proxy);
    }
    try {
        $response = wp_remote_get('https://ftp.apnic.net/stats/apnic/delegated-apnic-latest', array(
            'timeout' => 60, 'stream' => true, 'filename' => $source,
            'limit_response_size' => 32 * 1024 * 1024));
    } finally {
        if ($proxy !== '') {
            remove_action('http_api_curl', $configure_proxy);
        }
    }
    if (is_wp_error($response)) {
        throw new RuntimeException('下载失败：' . $response->get_error_message());
    }
    if (wp_remote_retrieve_response_code($response) !== 200) {
        throw new RuntimeException('APNIC 下载返回 HTTP ' . wp_remote_retrieve_response_code($response) . '。');
    }
}

function siren_operations_update_ip()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => '你没有更新 IP 库的权限。'), 403);
    }
    if (!check_ajax_referer('siren-update-ip-database', 'nonce', false)) {
        wp_send_json_error(array('message' => '页面验证已过期，请刷新后重试。'), 403);
    }
    require_once get_template_directory() . '/iplib2/fetch.php';
    $source = wp_tempnam('siren-apnic');
    if (!$source) {
        wp_send_json_error(array('message' => '无法创建下载临时文件，请检查目录权限。'), 500);
    }
    $path = get_template_directory() . '/iplib2/cn-ranges.json';
    $proxy = isset($_POST['proxy']) ? trim(wp_unslash($_POST['proxy'])) : '';
    try {
        $data = siren_apnic_update($path, static function () use ($source, $proxy) {
            siren_operations_download_ip($source, $proxy);
            return $source;
        });
        $result = array('message' => 'IP 库更新完成。', 'status' => siren_operations_ip_status($data, $path));
    } catch (RuntimeException $error) {
        $failure = $error->getMessage();
    } finally {
        unlink($source);
    }
    if (isset($failure)) {
        wp_send_json_error(array('message' => $failure . ' 当前 IP 库未被替换。'), 500);
    }
    wp_send_json_success($result);
}
