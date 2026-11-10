<?php
declare(strict_types=1);

/** 全局配置（支持本地覆盖） */
function cfg(): array {
    static $c = null;
    if ($c !== null) return $c;
    $c = require __DIR__ . '/config.php';
    $local = __DIR__ . '/config.local.php';
    if (is_file($local)) {
        $c = array_replace_recursive($c, require $local);
    }
    return $c;
}

function relay_token(): string {
    return (string)(cfg()['relay_token'] ?? '');
}

/**
 * 取调用方令牌。
 * 该主机会剥离 Authorization 头，所以优先读 X-Relay-Token，
 * 其次 ?token= 查询参数，最后才尝试 Authorization。
 */
function bearer(): string {
    $h = trim((string)($_SERVER['HTTP_X_RELAY_TOKEN'] ?? ''));
    if ($h !== '') return $h;

    $q = trim((string)($_GET['token'] ?? ''));
    if ($q !== '') return $q;

    $a = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($a === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strtolower($k) === 'authorization') { $a = $v; break; }
        }
    }
    if (preg_match('/^Bearer\s+(.+)$/i', trim($a), $m)) return trim($m[1]);
    return '';
}

function require_auth(): void {
    $want = relay_token();
    $got = bearer();
    if ($want === '' || $got === '' || !hash_equals($want, $got)) {
        json_out(401, ['ok' => false, 'error' => 'unauthorized', 'message' => '需要有效的 Bearer 令牌。']);
    }
}

function json_out(int $code, array $payload): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function body_json(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

/** 主机出站需要伪装成浏览器 UA，否则会被前置挑战拦截 */
function ua(): string {
    return 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
}

function tmp_dir(): string {
    $d = (string)cfg()['tmp_dir'];
    if (!is_dir($d)) @mkdir($d, 0770, true);
    return $d;
}

/**
 * 通用 HTTP 请求。
 * 返回 ['code'=>int, 'body'=>string, 'err'=>string]
 */
function http_call(string $method, string $url, array $opts = [], ?int $timeout = null): array {
    $h = cfg()['http'];
    $timeout = $timeout ?? (int)$h['timeout'];

    $base = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => (int)$h['connect_timeout'],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_USERAGENT      => ua(),
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    if (strtoupper($method) === 'POST') {
        $base[CURLOPT_POST] = true;
    }
    $base = array_replace($base, $opts);

    $ch = curl_init($url);
    curl_setopt_array($ch, $base);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    return ['code' => $code, 'body' => (string)$body, 'err' => $err];
}

/**
 * 带 429 退避重试的 POST。
 */
function http_post_retry(string $url, array $opts, int $timeout): array {
    $res = http_call('POST', $url, $opts, $timeout);
    $cfg = cfg()['http'];
    if ($res['code'] === 429 && !empty($cfg['retry_on_429'])) {
        sleep((int)$cfg['retry_backoff']);
        $res = http_call('POST', $url, $opts, $timeout);
    }
    return $res;
}
