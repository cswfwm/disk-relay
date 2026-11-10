<?php
declare(strict_types=1);

/**
 * 调度器
 *
 * 职责：
 *   1. 按文件大小 / 扩展名筛掉接不了的网盘
 *   2. 按健康度（熔断状态）和剩余容量排序
 *   3. 依次尝试，失败自动降级到下一个
 *   4. 记录每个后端的连续失败次数，触发熔断冷却
 *
 * 状态存在 _storage/state.json，避免依赖数据库。
 */

function state_path(): string {
    return dirname(tmp_dir()) . '/state.json';
}

function state_load(): array {
    $p = state_path();
    if (!is_file($p)) return ['providers' => []];
    $d = json_decode((string)file_get_contents($p), true);
    return is_array($d) ? $d : ['providers' => []];
}

function state_save(array $s): void {
    $p = state_path();
    $dir = dirname($p);
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    @file_put_contents($p, json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/** 后端当前是否处于熔断冷却期 */
function provider_cooling(array $state, string $name): bool {
    $st = $state['providers'][$name] ?? null;
    if (!$st) return false;
    $until = (int)($st['blocked_until'] ?? 0);
    return $until > time();
}

function provider_mark_ok(string $name): void {
    $s = state_load();
    $s['providers'][$name] = [
        'fails' => 0, 'blocked_until' => 0,
        'ok' => (int)($s['providers'][$name]['ok'] ?? 0) + 1,
        'last_ok' => date('c'),
    ];
    state_save($s);
}

function provider_mark_fail(string $name, string $reason): void {
    $s = state_load();
    $cfg = cfg()['circuit'];
    $cur = $s['providers'][$name] ?? ['fails' => 0, 'blocked_until' => 0];
    $cur['fails'] = (int)($cur['fails'] ?? 0) + 1;
    $cur['last_error'] = mb_substr($reason, 0, 200);
    $cur['last_fail'] = date('c');
    if ($cur['fails'] >= (int)$cfg['fail_threshold']) {
        $cur['blocked_until'] = time() + (int)$cfg['cooldown'];
    }
    $s['providers'][$name] = $cur;
    state_save($s);
}

/**
 * 为给定文件挑选候选后端，按优先级排序。
 * 返回 [ ['name'=>..., 'cfg'=>...], ... ]
 */
function plan_upload(string $filename, int $size): array {
    $state = state_load();
    $all = providers();
    $out = [];

    foreach ($all as $name => $p) {
        $lim = $p['limits'];
        if ($size > (int)$lim['max_bytes']) continue;          // 容量不够
        if (provider_cooling($state, $name)) continue;          // 熔断中

        // 健康度打分：连续失败越多越靠后
        $fails = (int)($state['providers'][$name]['fails'] ?? 0);
        $okCount = (int)($state['providers'][$name]['ok'] ?? 0);
        $score = $lim['max_bytes'] / 1073741824 * 10 + $okCount - $fails * 100;

        $out[] = ['name' => $name, 'cfg' => $p, 'score' => $score];
    }

    usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
    return $out;
}

/**
 * 上传：按候选顺序尝试，返回 [name, url, bytes, wrapped]
 * 全部失败返回 null。
 */
function upload_dispatch(string $path, string $origName, int $size): ?array {
    $cands = plan_upload($origName, $size);
    if (!$cands) return null;

    foreach ($cands as $c) {
        $r = upload_one($c['name'], $c['cfg'], $path, $origName, $size);
        if ($r !== null) return $r;
    }
    return null;
}

/** 针对单个后端执行上传 */
function upload_one(string $name, array $p, string $path, string $origName, int $size = 0): ?array {
    $up = $p['upload'];
    $lim = $p['limits'];

    // 决定发送用的文件名
    $sendName = $up['filename'] ?? null;
    if ($sendName === null) {
        $sendName = $origName;
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!empty($lim['deny_ext']) && in_array($ext, $lim['deny_ext'], true)) {
            $sendName = pathinfo($origName, PATHINFO_FILENAME) . '.' . $lim['rename_ext'];
        }
    }

    // 需要按内容避让时（pomf 拦 PK），先 gzip 包裹
    $sendPath = $path;
    $wrapped = false;
    if (!empty($lim['gzip_wrap'])) {
        $raw = @file_get_contents($path);
        if ($raw === false) return null;
        $gz = @gzencode($raw, 6);
        if ($gz === false) return null;
        $sendPath = tmp_dir() . '/w-' . bin2hex(random_bytes(8)) . '.tar.gz';
        if (@file_put_contents($sendPath, $gz) === false) return null;
        $wrapped = true;
    }

    $res = http_post_retry($up['url'], [
        CURLOPT_POSTFIELDS => [
            $up['field'] => new CURLFile($sendPath, $up['mime'], $sendName),
        ],
    ], 300);

    if ($wrapped) @unlink($sendPath);

    if ($res['code'] < 200 || $res['code'] >= 300) {
        provider_mark_fail($name, 'http ' . $res['code'] . ' ' . substr($res['body'], 0, 120));
        return null;
    }

    $link = parse_link($p['parse'], $res['body']);
    if ($link === null) {
        provider_mark_fail($name, 'unparsable: ' . substr($res['body'], 0, 120));
        return null;
    }

    provider_mark_ok($name);
    return [
        'provider' => $name,
        'label' => $p['label'],
        'url' => $link,
        'bytes' => $size > 0 ? $size : (int)@filesize($path),
        'wrapped' => $wrapped,
        'filename' => $sendName,
    ];
}

/**
 * 下载：把远端内容取回为本地临时文件。
 * 需要解包的后端先 gzip 还原。
 */
function download_dispatch(string $provider, string $url): ?string {
    $all = providers();
    if (!isset($all[$provider])) return null;
    $p = $all[$provider];

    $out = tmp_dir() . '/dl-' . bin2hex(random_bytes(8)) . '.bin';
    $fp = @fopen($out, 'wb');
    if (!$fp) return null;

    $opts = [CURLOPT_FILE => $fp, CURLOPT_TIMEOUT => 300];
    $dl = $p['download'];
    $method = $dl['method'] ?? 'GET';
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $dl['post'] ?? '';
    }

    $res = http_call($method, $url, $opts, 300);
    @fclose($fp);

    if ($res['code'] !== 200 || !is_file($out) || filesize($out) === 0) {
        @unlink($out);
        return null;
    }

    // pomf 类后端上传时做了 gzip 包裹，这里还原
    if (!empty($p['limits']['gzip_wrap'])) {
        $raw = @gzdecode((string)file_get_contents($out));
        if ($raw === false || $raw === '') { @unlink($out); return null; }
        @file_put_contents($out, $raw);
    }

    return $out;
}
