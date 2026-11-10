<?php
declare(strict_types=1);

/**
 * 网盘中转服务
 *
 * 统一的上传/下载调度层：上层只关心「把这段字节存到一个地方，给我一个链接」
 * 和「把这个链接的内容取回来」，具体落到哪个网盘由调度器决定。
 */

return [
    // 中转服务自身的鉴权令牌，调用方需带 Authorization: Bearer <token>
    'relay_token' => 'CHANGE_ME_ON_DEPLOY',

    // 临时文件目录（主机 open_basedir 限制，只能放站点内）
    'tmp_dir' => __DIR__ . '/../_storage/tmp',

    'http' => [
        'connect_timeout' => 20,
        'timeout' => 300,
        'retry_on_429' => 1,
        'retry_backoff' => 12,
    ],

    // 每个后端的连续失败次数达到阈值后，短时间内不再调度过去
    'circuit' => [
        'fail_threshold' => 3,
        'cooldown' => 600,
    ],
];
