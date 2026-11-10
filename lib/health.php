<?php
declare(strict_types=1);

/**
 * 网盘探活
 *
 * 对每个后端做两件事：
 *   1. 连通性：能否访问其站点
 *   2. 上传能力：真的传一个几十字节的探针文件，验证返回直链可下载
 *
 * 结果缓存到 _storage/health.json，避免每次刷新都真传一遍。
 */
function health_cache_path(): string {
    return dirname(tmp_dir()) . '/health.json';
}

function health_cache_load(): array {
    $p = health_cache_path();
    if (!is_file($p)) return ['at' => 0, 'items' => []];
    $d = json_decode((string)file_get_contents($p), true);
    return is_array($d) ? $d : ['at' => 0, 'items' => []];
}

function health_cache_save(array $d): void {
    $p = health_cache_path();
    $dir = dirname($p);
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    @file_put_contents($p, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/** 只测站点可达性（快） */
function health_probe_site(string $url, int $timeout = 10): array {
    $t0 = microtime(true);
    $res = http_call('GET', $url, [
        CURLOPT_RANGE => '0-1023',
    ], $timeout);
    $ms = (int)round((microtime(true) - $t0) * 1000);

    // 上传端点多为 POST-only，GET 返回 404/405/412 属正常，不算不可达
    $reachable = $res['err'] === '' && $res['code'] > 0;
    return [
        'ok' => $reachable,
        'code' => $res['code'],
        'ms' => $ms,
        'err' => $res['err'],
    ];
}

/** 真传一个探针文件，验证上传+直链 */
function health_probe_upload(string $name, array $p, int $timeout = 40): array {
    $probe = tmp_dir() . '/probe-' . bin2hex(random_bytes(6)) . '.bin';
    $payload = 'relay-probe-' . bin2hex(random_bytes(16));
    if (@file_put_contents($probe, $payload) === false) {
        return ['ok' => false, 'step' => 'write', 'err' => '无法写探针文件'];
    }

    $up = $p['upload'];
    $lim = $p['limits'];

    $sendPath = $probe;
    $sendName = $up['filename'] ?? 'probe.bin';
    $sendMime = $up['mime'] ?? 'application/octet-stream';
    $wrapped = false;

    if (!empty($lim['gzip_wrap'])) {
        $gz = @gzencode($payload, 6);
        if ($gz === false) { @unlink($probe); return ['ok' => false, 'step' => 'gzip']; }
        $wp = tmp_dir() . '/probe-' . bin2hex(random_bytes(6)) . '.tar.gz';
        @file_put_contents($wp, $gz);
        $sendPath = $wp;
        $wrapped = true;
    }

    $post = [];
    foreach (($up['extra_fields'] ?? []) as $k => $v) $post[$k] = $v;
    $post[$up['field']] = new CURLFile($sendPath, $sendMime, $sendName);

    $t0 = microtime(true);
    $res = http_call('POST', $up['url'], [CURLOPT_POSTFIELDS => $post], $timeout);
    $ms = (int)round((microtime(true) - $t0) * 1000);

    @unlink($probe);
    if ($wrapped) @unlink($sendPath);

    if ($res['code'] < 200 || $res['code'] >= 300) {
        return ['ok' => false, 'step' => 'upload', 'code' => $res['code'],
                'ms' => $ms, 'err' => substr($res['body'], 0, 120)];
    }

    $link = parse_link($p['parse'], $res['body']);
    if ($link === null) {
        return ['ok' => false, 'step' => 'parse', 'ms' => $ms, 'err' => substr($res['body'], 0, 120)];
    }

    $dl = http_call('GET', $link, [], $timeout);
    $body = $dl['body'];
    if ($wrapped && $body !== '') {
        $un = @gzdecode($body);
        if ($un !== false) $body = $un;
    }
    $match = ($body === $payload);

    return [
        'ok' => $match,
        'step' => $match ? 'done' : 'download',
        'code' => $dl['code'],
        'ms' => $ms,
        'url' => $link,
        'wrapped' => $wrapped,
        'err' => $match ? '' : ('直链内容不匹配，收到 ' . strlen($dl['body']) . ' 字节'),
    ];
}

/** 跑一轮完整探活，返回结果并写缓存 */
function health_run(bool $deep = true, array $only = []): array {
    $all = providers();
    $items = [];
    foreach ($all as $name => $p) {
        if ($only && !in_array($name, $only, true)) continue;

        $site = health_probe_site($p['upload']['url']);
        $item = [
            'label' => $p['label'],
            'endpoint' => $p['upload']['url'],
            'max_mb' => round($p['limits']['max_bytes'] / 1048576, 1),
            'keep_days' => $p['limits']['keep_days'] ?? 0,
            'gzip_wrap' => !empty($p['limits']['gzip_wrap']),
            'site' => $site,
            'upload' => null,
        ];

        if ($deep && $site['ok']) {
            $item['upload'] = health_probe_upload($name, $p);
        }
        $items[$name] = $item;

        usleep(400000);   // 各站之间稍作间隔，避免被判高频
    }

    $out = ['at' => time(), 'deep' => $deep, 'items' => $items];
    health_cache_save($out);
    return $out;
}

/** 生成一行状态结论 */
function health_verdict(array $item): array {
    if (!$item['site']['ok']) {
        return ['status' => 'down', 'text' => '站点不可达'];
    }
    if ($item['upload'] === null) {
        return ['status' => 'unknown', 'text' => '仅站点可达（未测上传）'];
    }
    if (!empty($item['upload']['ok'])) {
        return ['status' => 'ok', 'text' => '上传下载均正常'];
    }
    $step = $item['upload']['step'] ?? '?';
    $map = [
        'upload' => '上传被拒',
        'parse' => '响应无法解析',
        'download' => '直链下载失败',
        'gzip' => '压缩失败',
        'write' => '探针写入失败',
    ];
    return ['status' => 'fail', 'text' => $map[$step] ?? '失败'];
}
