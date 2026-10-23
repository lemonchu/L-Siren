<?php
/**
 * Registration challenge and comment email verification.
 * Existing captcha table and rules: 6 digits, 30-second resend, 10 attempts.
 */
defined('ABSPATH') || exit;

function siren_captcha_table_exists()
{
    global $wpdb;
    $table = $wpdb->prefix . 'captcha';
    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
}

/** Manual setup only. Existing tables and verification records are left intact. */
function siren_captcha_initialize_table()
{
    global $wpdb;
    if (siren_captcha_table_exists()) {
        return true;
    }
    $collation = $wpdb->get_charset_collate();
    $created = $wpdb->query($wpdb->prepare("CREATE TABLE IF NOT EXISTS %i (
        mailuser varchar(255) NOT NULL,
        lasttime bigint(20) NOT NULL,
        captcha int(11) NOT NULL,
        trial int(11) NOT NULL,
        banned tinyint(1) NOT NULL,
        UNIQUE KEY mail (mailuser)
    ) ENGINE=InnoDB $collation", $wpdb->prefix . 'captcha'));
    return $created === false
        ? new WP_Error('captcha_initialize', '验证码表创建失败，请检查数据库权限或错误日志。') : true;
}

function siren_guest_comment_captcha_enabled()
{
    // Preserve the existing behavior until this new option has been saved.
    return akina_option('guest_comment_captcha', 'yes') === 'yes';
}

function siren_guest_comment_captcha_required()
{
    return !is_user_logged_in() && siren_guest_comment_captcha_enabled();
}

function siren_captcha_input($source, $key)
{
    return isset($source[$key]) && is_string($source[$key])
        ? trim(wp_unslash($source[$key])) : '';
}

function siren_captcha_email($value)
{
    // Reject malformed input instead of silently changing the destination address.
    if (!is_string($value) || strlen($value) > 254 ||
        preg_match('/[\r\n#"]/', $value) || !is_email($value)) {
        return new WP_Error('invalid_email', '无效的邮箱地址');
    }
    return $value;
}

function add_security_question_fields()
{
    $num1 = random_int(0, 9999);
    $num2 = random_int(0, 9999);
    $nonce = wp_create_nonce('siren_registration_math_' . $num1 . '_' . $num2);
    echo '<p><label for="math" class="small">验证码：' . $num1 . ' + ' . $num2 . ' = ? </label>';
    echo '<input id="math" type="text" name="sum" class="input" inputmode="numeric" size="25">';
    echo '<input type="hidden" name="num1" value="' . $num1 . '">';
    echo '<input type="hidden" name="num2" value="' . $num2 . '">';
    echo '<input type="hidden" name="math_nonce" value="' . esc_attr($nonce) . '"></p>';
}

function add_security_question_validate($sanitized_user_login, $user_email, $errors)
{
    $sum = siren_captcha_input($_POST, 'sum');
    $num1 = siren_captcha_input($_POST, 'num1');
    $num2 = siren_captcha_input($_POST, 'num2');
    $nonce = siren_captcha_input($_POST, 'math_nonce');
    if ($sum === '') {
        $errors->add('math_required', '错误：请输入验证码！');
    } elseif (!preg_match('/\A[0-9]{1,4}\z/', $num1) ||
        !preg_match('/\A[0-9]{1,4}\z/', $num2) ||
        !wp_verify_nonce($nonce, 'siren_registration_math_' . $num1 . '_' . $num2) ||
        !preg_match('/\A[0-9]{1,5}\z/', $sum) || (int) $sum !== (int) $num1 + (int) $num2) {
        $errors->add('math_invalid', '错误：验证码错误或已过期，请重试！');
    }
}
add_action('register_form', 'add_security_question_fields');
add_action('register_post', 'add_security_question_validate', 10, 3);

/**
 * Serialize issuance and verification for one mailbox, including its first request.
 * Named locks also work before a row exists; always release on return/exception.
 */
function siren_captcha_locked($email, $callback)
{
    global $wpdb;
    $lock = 'siren_captcha_' . substr(hash('sha256', $wpdb->prefix . ':' . $email), 0, 48);
    if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 2)', $lock))) {
        return new WP_Error('captcha_busy', '请求处理中，请稍后重试');
    }
    try {
        return call_user_func($callback);
    } finally {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
    }
}

function siren_captcha_record($email)
{
    global $wpdb;
    $record = $wpdb->get_row($wpdb->prepare(
        'SELECT mailuser, lasttime, captcha, trial, banned FROM %i WHERE mailuser = %s LIMIT 1',
        $wpdb->prefix . 'captcha', $email
    ));
    return $wpdb->last_error
        ? new WP_Error('captcha_database', '验证码服务暂不可用，请稍后重试') : $record;
}

function siren_captcha_issue($email)
{
    if (!siren_guest_comment_captcha_enabled()) {
        return new WP_Error('captcha_disabled', '访客评论验证码已关闭');
    }
    $email = siren_captcha_email($email);
    if (is_wp_error($email)) {
        return $email;
    }
    return siren_captcha_locked($email, function () use ($email) {
        global $wpdb;
        $record = siren_captcha_record($email);
        if (is_wp_error($record)) {
            return $record;
        }
        $now = time();
        if ($record && (int) $record->banned !== 0) {
            return new WP_Error('captcha_banned', '该邮箱验证码功能已被禁用');
        }
        if ($record && $now - (int) $record->lasttime < 30) {
            return new WP_Error('captcha_rate_limit', '请求过于频繁，请等待 ' .
                (30 - ($now - (int) $record->lasttime)) . ' 秒后重新获取');
        }
        try {
            $code = random_int(100000, 999999);
        } catch (Exception $error) {
            return new WP_Error('captcha_random', '验证码服务暂不可用，请稍后重试');
        }
        $table = $wpdb->prefix . 'captcha';
        // Activate only after successful mail delivery; failures stay unusable.
        $data = array('lasttime' => $now, 'captcha' => $code, 'trial' => -1);
        if ($record) {
            $saved = $wpdb->update($table, $data, array('mailuser' => $email),
                array('%d', '%d', '%d'), array('%s'));
        } else {
            $data['mailuser'] = $email;
            $data['banned'] = 0;
            $saved = $wpdb->insert($table, $data, array('%d', '%d', '%d', '%s', '%d'));
        }
        if (1 !== $saved) {
            return new WP_Error('captcha_database', '验证码服务暂不可用，请稍后重试');
        }
        try {
            $sent = send_captcha($email, $code);
        } catch (Exception $error) {
            $sent = false;
        }
        if (!$sent) {
            return new WP_Error('captcha_mail', '邮件发送失败');
        }
        $activated = $wpdb->update($table, array('trial' => 10),
            array('mailuser' => $email, 'captcha' => $code, 'lasttime' => $now, 'trial' => -1, 'banned' => 0),
            array('%d'), array('%s', '%d', '%d', '%d', '%d'));
        if (1 !== $activated) {
            return new WP_Error('captcha_database', '验证码服务暂不可用，请稍后重试');
        }
        return true;
    });
}

/** Return true on successful one-time consumption; all failures are WP_Error. */
function siren_captcha_verify($email, $code)
{
    $email = siren_captcha_email($email);
    if (is_wp_error($email)) {
        return $email;
    }
    if (!is_string($code) || $code === '') {
        return new WP_Error('captcha_required', '未填写验证码');
    }
    return siren_captcha_locked($email, function () use ($email, $code) {
        global $wpdb;
        $record = siren_captcha_record($email);
        if (is_wp_error($record)) {
            return $record;
        }
        if (!$record) {
            return new WP_Error('captcha_missing', '此邮箱地址未获取验证码');
        }
        if ((int) $record->banned !== 0) {
            return new WP_Error('captcha_banned', '错误:该邮箱验证码功能被封禁');
        }
        // Check consumed state before incorrect codes can decrement a negative counter.
        if ((int) $record->trial < 0) {
            return new WP_Error('captcha_used', '验证码已失效，请重新获取验证码');
        }
        if ((int) $record->trial === 0) {
            return new WP_Error('captcha_exhausted', '验证码尝试机会用尽，请重新获取验证码');
        }
        $matches = preg_match('/\A[0-9]{6}\z/', $code) && hash_equals((string) $record->captcha, $code);
        $remaining = $matches ? -1 : (int) $record->trial - 1;
        $saved = $wpdb->update($wpdb->prefix . 'captcha', array('trial' => $remaining),
            array('mailuser' => $email, 'captcha' => $record->captcha, 'trial' => $record->trial, 'banned' => 0),
            array('%d'), array('%s', '%d', '%d', '%d'));
        if (1 !== $saved) {
            return new WP_Error('captcha_database', '验证码服务暂不可用，请稍后重试');
        }
        return $matches ? true : new WP_Error('captcha_incorrect', '验证码错误，剩余' . $remaining . '次机会');
    });
}

function comment_captcha()
{
    if (!siren_guest_comment_captcha_required()) {
        return;
    }
    $result = siren_captcha_verify(siren_captcha_input($_POST, 'email'), siren_captcha_input($_POST, 'captcha'));
    if (is_wp_error($result)) {
        siren_ajax_comment_err($result->get_error_message());
    }
}
add_action('pre_comment_on_post', 'comment_captcha');

/** AJAX adapter: keep the frontend's existing plain-text response format. */
function captcha()
{
    nocache_headers();
    header('Content-Type: text/plain; charset=UTF-8');
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' ||
        !wp_verify_nonce(siren_captcha_input($_POST, 'nonce'), 'siren_email_captcha')) {
        echo '请求验证失败，请刷新页面后重试';
        wp_die();
    }
    $result = siren_captcha_issue(siren_captcha_input($_POST, 'mail'));
    echo is_wp_error($result) ? $result->get_error_message() : '验证码已发送成功';
    wp_die();
}
add_action('wp_ajax_nopriv_captcha', 'captcha');
add_action('wp_ajax_captcha', 'captcha');

/** Mail transport only; never echo or use a request Host header as sender. */
function send_captcha($mailuser, $captcha)
{
    $site_name = get_option('blogname');
    $domain = wp_parse_url(home_url('/'), PHP_URL_HOST);
    $sender = sanitize_email(get_option('mail_user_name', 'Poi') . '@' . preg_replace('/^www\./i', '', (string) $domain));
    $headers = array('Content-Type: text/html; charset=UTF-8');
    if (is_email($sender)) {
        $sender_name = preg_replace('/[\r\n<>"\\\\]/', '', wp_specialchars_decode($site_name, ENT_QUOTES));
        $headers[] = 'From: "' . $sender_name . '" <' . $sender . '>';
    }
    $name = esc_html($site_name);
    $url = esc_url(home_url('/'));
    $recipient = esc_html($mailuser);
    $code = esc_html((string) $captcha);
    $message = "<table border='1' cellpadding='0' cellspacing='0' width='600' align='center' style='border-collapse:collapse;border-color:#ddd'><tbody>
        <tr><td><table align='center' width='600' height='48'><tr><td width='100' align='center' style='border-right:1px solid #ddd'>
        <a href='$url' target='_blank'>$name</a></td></tr></table></td></tr>
        <tr><td style='padding:20px 40px 0'><p><strong>$recipient</strong>, 你好!</p><p>验证码为:<strong>$code</strong></p></td></tr>
        <tr><td align='center' height='38' style='font-size:0.8rem;color:#999'>© $name</td></tr></tbody></table>";
    return wp_mail($mailuser, '验证码', $message, $headers);
}
