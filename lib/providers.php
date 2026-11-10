<?php
declare(strict_types=1);

/**
 * 各网盘的请求格式定义。
 *
 * 每条配置描述一个后端需要的全部信息：
 *   upload   上传协议（url / field / 文件名与 MIME 变换 / 响应解析方式）
 *   download 下载协议（GET 还是 POST / 是否需要解包）
 *   limits   容量、保留期、可接受的格式
 *
 * 新增一个网盘只需在这里加一项，调度器无需改动。
 */
function providers(): array {
    return [
        'x0' => [
            'label' => 'x0.at',
            'upload' => [
                'url' => 'https://x0.at/',
                'field' => 'file',
                'filename' => null,          // 用原始文件名
                'mime' => 'application/octet-stream',
            ],
            'parse' => 'text',               // 响应就是一行 URL
            'download' => ['method' => 'GET'],
            'limits' => [
                'max_bytes' => 1024 * 1024 * 1024,
                'keep_days' => 3,
                'deny_ext' => ['apk', 'exe', 'dll', 'ipa', 'jar', 'sh', 'dat'],
                // 命中去 deny_ext 时改用此外扩展名上传，内容不变
                'rename_ext' => 'tar.gz',
            ],
        ],

        'pomf' => [
            'label' => 'pomf2.lain.la',
            'upload' => [
                'url' => 'https://pomf2.lain.la/upload.php',
                'field' => 'files[]',
                'filename' => 'blob.tar.gz',
                'mime' => 'application/gzip',
            ],
            'parse' => 'pomf',
            'download' => ['method' => 'GET'],
            'limits' => [
                'max_bytes' => 750 * 1024 * 1024,
                'keep_days' => 0,
                'deny_content' => ['PK'],    // 按内容魔数拦收 zip
                'gzip_wrap' => true,         // 上传前 gzip 包裹，下载时解开
            ],
        ],

        'kappa' => [
            'label' => 'kappa.lol',
            'upload' => [
                'url' => 'https://kappa.lol/api/upload',
                'field' => 'file',
                'filename' => null,
                'mime' => 'application/octet-stream',
            ],
            'parse' => 'json',
            'download' => ['method' => 'GET'],
            'limits' => [
                'max_bytes' => 100 * 1024 * 1024,
                'keep_days' => 0,
            ],
        ],

        'uguu' => [
            'label' => 'uguu.se',
            'upload' => [
                'url' => 'https://uguu.se/upload.php',
                'field' => 'files[]',
                'filename' => null,
                'mime' => 'application/octet-stream',
            ],
            'parse' => 'pomf',
            'download' => ['method' => 'GET'],
            'limits' => [
                'max_bytes' => 128 * 1024 * 1024,
                'keep_days' => 0,            // 实际只存 3 小时
                'deny_ext' => ['zip', 'rar', 'apk', 'exe', 'pdf'],
                'rename_ext' => 'tar.gz',
            ],
        ],

        'stow' => [
            'label' => 'stow.im',
            'upload' => [
                'url' => 'https://stow.im/',
                'field' => 'file',
                'filename' => null,
                'mime' => 'application/octet-stream',
            ],
            'parse' => 'text',
            'download' => ['method' => 'GET'],
            'limits' => ['max_bytes' => 99 * 1024 * 1024, 'keep_days' => 0],
        ],

        'cyx' => [
            'label' => 'share.cyx.su',
            'upload' => [
                'url' => 'https://share.cyx.su/',
                'field' => 'file',
                'filename' => null,
                'mime' => 'application/octet-stream',
            ],
            'parse' => 'text',
            'download' => ['method' => 'GET'],
            'limits' => ['max_bytes' => 99 * 1024 * 1024, 'keep_days' => 0],
        ],

        'juicey' => [
            'label' => 'juicey.dev',
            'upload' => [
                'url' => 'https://box.juicey.dev/upload',
                'field' => 'file',
                'filename' => null,
                'mime' => 'application/octet-stream',
            ],
            'parse' => 'juicey',
            'download' => ['method' => 'GET'],
            'limits' => ['max_bytes' => 99 * 1024 * 1024, 'keep_days' => 0],
        ],

        'litterbox' => [
            'label' => 'litterbox',
            'upload' => [
                'url' => 'https://litterbox.catbox.moe/resources/internals/api.php',
                'field' => 'fileToUpload',
                'filename' => null,
                'mime' => 'application/octet-stream',
                'extra_fields' => ['reqtype' => 'fileupload', 'time' => '72h'],
            ],
            'parse' => 'text',
            'download' => ['method' => 'GET'],
            'limits' => ['max_bytes' => 1024 * 1024 * 1024, 'keep_days' => 3],
        ],

        'file2me' => [
            'label' => 'file2.me',
            'upload' => [
                'url' => 'https://file2.me/api/upload',
                'field' => 'file',
                'filename' => null,
                'mime' => 'application/octet-stream',
            ],
            'parse' => 'file2me',
            'download' => ['method' => 'GET'],
            'limits' => ['max_bytes' => 20 * 1024 * 1024, 'keep_days' => 0],
        ],
    ];
}

/** 从响应体中解析出直链 */
function parse_link(string $mode, string $body): ?string {
    $body = trim($body);
    if ($body === '') return null;

    if ($mode === 'json') {
        $d = json_decode($body, true);
        if (is_array($d) && !empty($d['link'])) return (string)$d['link'];
        return null;
    }

    if ($mode === 'juicey') {
        $d = json_decode($body, true);
        if (is_array($d)) {
            if (!empty($d['raw_url'])) return (string)$d['raw_url'];
            if (!empty($d['url']) && str_starts_with((string)$d['url'], 'http')) return (string)$d['url'];
        }
        return null;
    }

    if ($mode === 'file2me') {
        $d = json_decode($body, true);
        if (is_array($d) && !empty($d['urls']['raw'])) return (string)$d['urls']['raw'];
        return null;
    }

    if ($mode === 'pomf') {
        $d = json_decode($body, true);
        if (is_array($d) && !empty($d['success']) && !empty($d['files'][0]['url'])) {
            return (string)$d['files'][0]['url'];
        }
        return null;
    }

    // text：响应本身就是一行 URL
    if (preg_match('#^https?://\S+$#', $body)) return $body;
    if (preg_match('#https?://[^\s"\'<>]+#', $body, $m)) return $m[0];
    return null;
}
