<?php
declare(strict_types=1);

require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/providers.php';
require __DIR__ . '/lib/dispatch.php';
require __DIR__ . '/lib/health.php';
require __DIR__ . '/lib/page.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// 去掉脚本自身路径前缀（直访 /index.php 时也会正确路由）
$script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
if ($script !== '/' && str_starts_with($uri, $script)) {
    $uri = substr($uri, strlen($script));
}
$path = '/' . trim($uri, '/');
if ($path === '/') $path = '/';
if ($path === '/index.php') $path = '/';

set_exception_handler(function (Throwable $e) {
    error_log('[relay] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        json_out(500, ['ok' => false, 'error' => 'internal_error', 'message' => '服务器内部错误。']);
    }
});

// —— 探活数据（主页与 API 共用）
function health_payload(): array {
    $cache = health_cache_load();
    $items = $cache['items'] ?? [];
    $summary = ['ok' => 0, 'fail' => 0, 'down' => 0, 'unknown' => 0];
    foreach ($items as $it) {
        $v = health_verdict($it);
        $summary[$v['status']] = ($summary[$v['status']] ?? 0) + 1;
    }
    return [
        'ok' => true,
        'service' => 'disk-relay',
        'php' => PHP_VERSION,
        'checked_at' => (int)($cache['at'] ?? 0),
        'deep' => (bool)($cache['deep'] ?? false),
        'summary' => $summary,
        'providers' => $items,
    ];
}

$canRefresh = false;
$wantRefresh = isset($_GET['refresh']);
if ($wantRefresh) {
    $want = relay_token();
    $got  = bearer();
    $canRefresh = ($want !== '' && $got !== '' && hash_equals($want, $got));
} else {
    $want = relay_token();
    $got  = bearer();
    $canRefresh = ($want !== '' && $got !== '' && hash_equals($want, $got));
}

if ($wantRefresh && $canRefresh) {
    $deep = !isset($_GET['deep']) || (string)$_GET['deep'] !== '0';
    health_run($deep);
    if (isset($_GET['api'])) {
        json_out(200, health_payload());
    }
    header('Location: /');
    exit;
}

// —— JSON 版探活
if ($method === 'GET' && $path === '/api/health') {
    json_out(200, health_payload());
}

// —— 主页
if ($method === 'GET' && ($path === '/' || $path === '/index.php')) {
    render_index(health_cache_load(), $canRefresh);
    exit;
}

// —— 后端列表与状态
if ($method === 'GET' && $path === '/api/providers') {
    require_auth();
    $state = state_load();
    $out = [];
    foreach (providers() as $name => $p) {
        $st = $state['providers'][$name] ?? [];
        $out[$name] = [
            'label' => $p['label'],
            'max_mb' => round($p['limits']['max_bytes'] / 1048576, 1),
            'gzip_wrap' => !empty($p['limits']['gzip_wrap']),
            'ok_count' => (int)($st['ok'] ?? 0),
            'fails' => (int)($st['fails'] ?? 0),
            'cooling' => provider_cooling($state, $name),
            'last_error' => $st['last_error'] ?? null,
        ];
    }
    json_out(200, ['ok' => true, 'providers' => $out]);
}

// —— 上传
if ($method === 'POST' && $path === '/api/upload') {
    require_auth();

    $f = $_FILES['file'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $code = $f['error'] ?? null;
        $hint = match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                '文件超过主机 upload_max_filesize 限制（' . ini_get('upload_max_filesize') . '）。',
            UPLOAD_ERR_PARTIAL => '文件只上传了一部分。',
            default => '请用 multipart 字段 file 上传文件。',
        };
        json_out(422, ['ok' => false, 'error' => 'missing_file', 'message' => $hint]);
    }
    if ((int)$f['size'] <= 0) {
        json_out(422, ['ok' => false, 'error' => 'empty_file', 'message' => '文件为空。']);
    }
    if (!is_uploaded_file((string)$f['tmp_name'])) {
        json_out(400, ['ok' => false, 'error' => 'invalid_upload', 'message' => '上传校验失败。']);
    }

    $origName = basename((string)$f['name']);
    $wantProvider = trim((string)($_POST['provider'] ?? ''));

    $res = null;
    if ($wantProvider !== '') {
        $all = providers();
        if (!isset($all[$wantProvider])) {
            json_out(422, ['ok' => false, 'error' => 'unknown_provider',
                'message' => '未知后端：' . $wantProvider]);
        }
        $res = upload_one($wantProvider, $all[$wantProvider], (string)$f['tmp_name'], $origName);
        if ($res === null) {
            json_out(502, ['ok' => false, 'error' => 'upload_failed',
                'message' => '指定后端上传失败。']);
        }
    } else {
        $res = upload_dispatch((string)$f['tmp_name'], $origName, (int)$f['size']);
        if ($res === null) {
            json_out(502, ['ok' => false, 'error' => 'all_failed',
                'message' => '所有可用后端都上传失败。']);
        }
    }

    json_out(200, ['ok' => true] + $res);
}

// —— 下载（流式转发，不在磁盘落地）
if ($method === 'GET' && $path === '/api/download') {
    require_auth();

    $provider = trim((string)($_GET['provider'] ?? ''));
    $url = trim((string)($_GET['url'] ?? ''));
    if ($provider === '' || $url === '' || !preg_match('#^https?://#i', $url)) {
        json_out(422, ['ok' => false, 'error' => 'bad_params',
            'message' => '需要 provider 和 url 参数。']);
    }

    $tmp = download_dispatch($provider, $url);
    if ($tmp === null) {
        json_out(502, ['ok' => false, 'error' => 'download_failed',
            'message' => '从该后端取回失败。']);
    }

    $name = trim((string)($_GET['name'] ?? 'download.bin'));
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?: 'download.bin';

    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($tmp));
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('X-Relay-Provider: ' . $provider);
    readfile($tmp);
    @unlink($tmp);
    exit;
}

json_out(404, ['ok' => false, 'error' => 'not_found', 'message' => "No route for $method $path"]);
