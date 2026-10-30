<?php
/** APNIC conversion and atomic publication, without external programs. */

function siren_apnic_number(string $value, int $maximum = 4294967296): int
{
    $digits = ltrim($value, '0');
    $limit = (string) $maximum;
    if (!ctype_digit($value) || strlen($digits) > strlen($limit) ||
        (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
        throw new RuntimeException('数值超出允许范围。');
    }
    return (int) $digits;
}

function siren_apnic_range(string $family, string $start, string $value): array
{
    $first = @inet_pton($start);
    $length = $family === 'ipv4' ? 4 : 16;
    if ($first === false || strlen($first) !== $length) {
        throw new RuntimeException('IP 地址格式错误。');
    }
    if ($family === 'ipv4') {
        $count = siren_apnic_number($value);
        $end = unpack('N', $first)[1] + $count - 1;
        if ($count === 0 || $end > 4294967295) {
            throw new RuntimeException('IPv4 地址范围越界。');
        }
        return array($first, pack('N', $end));
    }
    $prefix = siren_apnic_number($value, 128);
    $last = $first;
    for ($i = 0; $i < 16; ++$i) {
        $bits = max(0, min(8, $prefix - $i * 8));
        $mask = $bits ? (0xff << (8 - $bits)) & 0xff : 0;
        $first[$i] = chr(ord($first[$i]) & $mask);
        $last[$i] = chr(ord($last[$i]) | (0xff ^ $mask));
    }
    return array($first, $last);
}

function siren_apnic_next(string $address): ?string
{
    for ($i = strlen($address) - 1; $i >= 0; --$i) {
        $byte = ord($address[$i]);
        $address[$i] = chr(($byte + 1) & 0xff);
        if ($byte < 255) {
            return $address;
        }
    }
    return null;
}

function siren_apnic_merge(array $ranges): array
{
    usort($ranges, static fn($a, $b) => strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]));
    $merged = array();
    foreach ($ranges as $range) {
        $index = count($merged) - 1;
        if ($index >= 0 && (strcmp($range[0], $merged[$index][1]) <= 0 ||
            siren_apnic_next($merged[$index][1]) === $range[0])) {
            if (strcmp($range[1], $merged[$index][1]) > 0) {
                $merged[$index][1] = $range[1];
            }
        } else {
            $merged[] = $range;
        }
    }
    return array_map(static fn($range) => array(bin2hex($range[0]), bin2hex($range[1])), $merged);
}

function siren_apnic_parse(string $source): array
{
    $input = @fopen($source, 'rb');
    if ($input === false) {
        throw new RuntimeException('无法读取 APNIC 数据。');
    }
    $serial = null;
    $expected = $records = $line_number = 0;
    $ranges = array('ipv4' => array(), 'ipv6' => array());
    try {
        while (($line = fgets($input)) !== false) {
            ++$line_number;
            $line = rtrim($line, "\r\n");
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = explode('|', $line);
            if ($serial === null) {
                if (count($parts) < 7 || $parts[0] !== '2' || $parts[1] !== 'apnic' ||
                    !preg_match('/\A[0-9]{8}\z/', $parts[2]) || (int) $parts[2] === 0) {
                    throw new RuntimeException('缺少 APNIC 版本 2 文件头。');
                }
                $serial = $parts[2];
                $expected = siren_apnic_number($parts[3]);
                continue;
            }
            if (count($parts) === 6 && $parts[0] === 'apnic' && $parts[1] === '*' && $parts[5] === 'summary') {
                continue;
            }
            if (count($parts) < 7 || $parts[0] !== 'apnic') {
                throw new RuntimeException('APNIC 记录格式错误。');
            }
            ++$records;
            if ($parts[1] === 'CN' && in_array($parts[6], array('allocated', 'assigned'), true) &&
                isset($ranges[$parts[2]])) {
                $ranges[$parts[2]][] = siren_apnic_range($parts[2], $parts[3], $parts[4]);
            }
        }
        if (!feof($input) || $expected === 0 || $records !== $expected) {
            throw new RuntimeException('记录总数与文件头不符，下载文件可能不完整。');
        }
        if (!$ranges['ipv4'] || !$ranges['ipv6']) {
            throw new RuntimeException('缺少中国 IPv4 或 IPv6 数据。');
        }
    } catch (RuntimeException $error) {
        throw new RuntimeException('APNIC 第 ' . $line_number . ' 行：' . $error->getMessage(), 0, $error);
    } finally {
        fclose($input);
    }
    return array('version' => 1, 'source_serial' => $serial,
        'ipv4' => siren_apnic_merge($ranges['ipv4']), 'ipv6' => siren_apnic_merge($ranges['ipv6']));
}

/** The source callback runs under the same lock used by get_latest.sh. */
function siren_apnic_update(string $output, callable $source): array
{
    $lock = @fopen($output . '.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('无法创建更新锁，请检查 IP 库目录权限。');
    }
    $temporary = null;
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('已有 IP 库更新正在进行，请稍后再试。');
        }
        $data = siren_apnic_parse($source());
        $json = json_encode($data, JSON_THROW_ON_ERROR) . "\n";
        $temporary = tempnam(dirname($output), '.apnic-update-');
        if ($temporary === false || file_put_contents($temporary, $json) !== strlen($json) ||
            !chmod($temporary, 0664) || !rename($temporary, $output)) {
            throw new RuntimeException('无法写入 IP 库，请检查目录权限。');
        }
        $temporary = null;
        return $data;
    } finally {
        if ($temporary !== null && $temporary !== false) {
            unlink($temporary);
        }
        // Closing releases only this handle's lock, including on exceptions.
        fclose($lock);
    }
}
