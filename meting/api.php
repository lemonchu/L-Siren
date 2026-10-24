<?php
require_once __DIR__ . '/meting.php';

use Metowolf\Meting;

function get_param(string $name, string $default = ''): string
{
    if (!isset($_GET[$name])) {
        return $default;
    }
    return is_string($_GET[$name]) ? trim($_GET[$name]) : '';
}

function send_text(string $text, int $status = 200)
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $text;
    exit;
}

function send_json($data)
{
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function redirect_to(string $url)
{
    if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
        send_text('无效的音乐地址', 502);
    }
    header('Cache-Control: no-store');
    header('Location: ' . $url, true, 302);
    exit;
}

function lyric_time(array $tag): int
{
    return ((int) $tag[1] * 60 + (int) $tag[2]) * 1000
        + (int) substr(str_pad($tag[3] ?? '', 3, '0'), 0, 3);
}

function merge_lyrics(string $original, string $translated): string
{
    if (trim($translated) === '') {
        return $original;
    }

    $pattern = '/\[(\d+):([0-5]?\d)(?:\.(\d+))?\]/';
    $translations = [];
    foreach (preg_split('/\r\n|\r|\n/', $translated) as $line) {
        preg_match_all($pattern, $line, $tags, PREG_SET_ORDER);
        $text = trim(preg_replace($pattern, '', $line));
        if ($text !== '') {
            foreach ($tags as $tag) {
                $translations[lyric_time($tag)] = $text;
            }
        }
    }

    $lines = [];
    foreach (preg_split('/\r\n|\r|\n/', $original) as $line) {
        if (!preg_match_all($pattern, $line, $tags, PREG_SET_ORDER)) {
            $lines[] = $line;
            continue;
        }
        $text = preg_replace($pattern, '', $line);
        foreach ($tags as $tag) {
            $translation = $translations[lyric_time($tag)] ?? '';
            $lines[] = $tag[0] . $text . ($translation !== '' ? ' (' . $translation . ')' : '');
        }
    }
    return implode("\n", $lines);
}

function get_current_script_url(): string
{
    $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://';
    return $scheme . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];
}

function format_track(array $track, string $server): array
{
    $result = [
        'title' => $track['name'] ?? '',
        'author' => implode(' / ', $track['artist'] ?? []),
    ];
    foreach (['url' => 'url_id', 'pic' => 'pic_id', 'lrc' => 'lyric_id'] as $type => $field) {
        $result[$type] = get_current_script_url() . '?' . http_build_query([
            'server' => $server,
            'type' => $type,
            'id' => $track[$field] ?? '',
        ], '', '&', PHP_QUERY_RFC3986);
    }
    return $result;
}

$id = get_param('id');
$server = get_param('server', 'netease');
$type = get_param('type', 'song');
$methods = ['playlist' => 'playlist', 'song' => 'song', 'apisong' => 'song', 'url' => 'url', 'pic' => 'pic', 'lrc' => 'lyric'];
if ($id === '' || !in_array($server, ['netease', 'tencent', 'xiami', 'kugou', 'baidu', 'kuwo'], true) || !isset($methods[$type])) {
    send_text('参数错误', 400);
}

$api = new Meting($server);
$cookies = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
if (!empty($cookies[$server])) {
    $api->cookie($cookies[$server]);
}

// 第三方接口返回异常时，给播放器正常的失败响应。
try {
    $data = json_decode($api->format(true)->{$methods[$type]}($id), true);
} catch (Throwable $error) {
    send_text('音乐服务暂时不可用', 502);
}
if ($api->error || ($api->info['http_code'] ?? 0) >= 400 || !is_array($data)) {
    send_text('音乐服务暂时不可用', 502);
}

switch ($type) {
    case 'playlist':
        send_json(array_map(fn($track) => format_track($track, $server), $data));
    case 'song':
        send_json($data);
    case 'apisong':
        send_json(isset($data[0]) ? format_track($data[0], $server) : new stdClass());
    case 'url':
    case 'pic':
        if (empty($data['url'])) {
            send_text('', 404);
        }
        redirect_to($type === 'url' ? preg_replace('~^http://~i', 'https://', $data['url']) : $data['url']);
    case 'lrc':
        send_text(merge_lyrics($data['lyric'] ?? '', $data['tlyric'] ?? ''));
}
