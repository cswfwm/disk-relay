<?php
declare(strict_types=1);

function render_index(array $health, bool $canRefresh): void {
    $items = $health['items'] ?? [];
    $at = (int)($health['at'] ?? 0);

    $okCount = 0; $failCount = 0; $downCount = 0; $unknown = 0;
    foreach ($items as $it) {
        $v = health_verdict($it);
        if ($v['status'] === 'ok') $okCount++;
        elseif ($v['status'] === 'fail') $failCount++;
        elseif ($v['status'] === 'down') $downCount++;
        else $unknown++;
    }
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>disk-relay · 网盘探活</title>
<style>
:root{
  --bg:#0b0f14;--panel:#121922;--panel2:#172231;--line:#22303e;
  --tx:#dbe7f3;--dim:#7e93a8;--acc:#38e0a5;--warn:#ffb84a;--err:#ff6b6b;--info:#4aa8ff;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--tx);
  font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",sans-serif}
a{color:var(--info);text-decoration:none}
a:hover{text-decoration:underline}
.wrap{max-width:1080px;margin:0 auto;padding:0 20px}
header{border-bottom:1px solid var(--line);padding:18px 0;margin-bottom:26px}
.brand{display:flex;align-items:center;gap:10px;font-weight:700;font-size:17px}
.dot{width:9px;height:9px;border-radius:50%;background:var(--acc);box-shadow:0 0 12px var(--acc)}
h1{font-size:26px;margin:0 0 8px}
.lead{color:var(--dim);margin:0 0 22px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:26px}
.stat{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:14px 16px}
.stat .k{font-size:12px;color:var(--dim);letter-spacing:.5px;text-transform:uppercase}
.stat .v{font-size:24px;font-weight:700;margin-top:4px}
.v.ok{color:var(--acc)} .v.fail{color:var(--err)} .v.down{color:var(--dim)} .v.unknown{color:var(--warn)}
table{width:100%;border-collapse:collapse;background:var(--panel);border:1px solid var(--line);
  border-radius:10px;overflow:hidden;font-size:14px}
th,td{padding:11px 14px;text-align:left;border-bottom:1px solid var(--line);vertical-align:middle}
th{background:var(--panel2);font-size:12px;color:var(--dim);letter-spacing:.4px;text-transform:uppercase;font-weight:600}
tr:last-child td{border-bottom:0}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.5px}
.badge{display:inline-block;padding:2px 9px;border-radius:6px;font-size:12px;font-weight:600;white-space:nowrap}
.badge.ok{background:rgba(56,224,165,.14);color:var(--acc)}
.badge.fail{background:rgba(255,107,107,.15);color:var(--err)}
.badge.down{background:rgba(126,147,168,.15);color:var(--dim)}
.badge.unknown{background:rgba(255,184,74,.14);color:var(--warn)}
.actions{margin:22px 0;display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.btn{display:inline-block;padding:9px 18px;border-radius:8px;font-size:14px;cursor:pointer;
  border:1px solid var(--line);background:var(--panel2);color:var(--tx)}
.btn:hover{border-color:var(--acc);text-decoration:none}
.btn.pri{background:rgba(56,224,165,.12);border-color:rgba(56,224,165,.35);color:var(--acc)}
.note{background:var(--panel);border:1px solid var(--line);border-left:3px solid var(--info);
  border-radius:8px;padding:13px 16px;font-size:13px;color:var(--dim);margin:22px 0;line-height:1.8}
.note b{color:var(--tx)}
pre{background:#0a0e13;border:1px solid #22303e;border-radius:8px;padding:14px 16px;
  overflow-x:auto;font-size:12.5px;line-height:1.7;color:#a9c7e3}
footer{border-top:1px solid var(--line);margin-top:40px;padding:18px 0;color:var(--dim);font-size:13px}
@media(max-width:720px){
  h1{font-size:21px}
  .stats{grid-template-columns:repeat(2,1fr)}
  table,tbody,tr,td{display:block;width:100%}
  table{border:0;background:none}
  thead{display:none}
  tr{background:var(--panel);border:1px solid var(--line);border-radius:10px;
     margin-bottom:10px;padding:4px 0}
  td{border:0;padding:6px 14px}
  td:first-child{padding-top:12px}
  td:last-child{padding-bottom:12px}
  td::before{content:attr(data-l);display:inline-block;min-width:76px;
    color:var(--dim);font-size:12px;text-transform:uppercase;letter-spacing:.4px}
  th{display:none}
}
</style>
</head>
<body>
<header><div class="wrap">
  <div class="brand"><span class="dot"></span> disk-relay · 网盘中转</div>
</div></header>

<div class="wrap">
  <h1>网盘探活</h1>
  <p class="lead">
    实时显示已接入的网盘是否可用。深度探活会真的上传一个探针文件，再取回校验字节。
    <?php if ($at > 0): ?>
      <br>上次检测：<?= htmlspecialchars(gmdate('Y-m-d H:i:s', $at), ENT_QUOTES) ?> UTC
      （<?= htmlspecialchars((string)round((time() - $at) / 60), ENT_QUOTES) ?> 分钟前）
    <?php else: ?>
      <br>尚未检测。
    <?php endif; ?>
  </p>

  <div class="stats">
    <div class="stat"><div class="k">正常</div><div class="v ok"><?= $okCount ?></div></div>
    <div class="stat"><div class="k">上传失败</div><div class="v fail"><?= $failCount ?></div></div>
    <div class="stat"><div class="k">站点不可达</div><div class="v down"><?= $downCount ?></div></div>
    <div class="stat"><div class="k">仅站点可通</div><div class="v unknown"><?= $unknown ?></div></div>
  </div>

  <div class="actions">
    <?php if ($canRefresh): ?>
      <a class="btn pri" href="/?refresh=1">重新检测（深度）</a>
      <a class="btn" href="/?refresh=1&deep=0">仅测连通性（快）</a>
    <?php else: ?>
      <span style="color:var(--dim);font-size:13px">需要令牌才能触发重新检测。</span>
    <?php endif; ?>
    <a class="btn" href="/api/health">查看 JSON</a>
  </div>

  <?php if (!$items): ?>
    <div class="note">还没有检测数据。<?= $canRefresh ? '点上面的「重新检测」开始。' : '' ?></div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th>网盘</th><th>状态</th><th>单文件上限</th><th>保留</th><th>耗时</th><th>说明</th>
    </tr></thead>
    <?php foreach ($items as $name => $it): $v = health_verdict($it); ?>
      <tr>
        <td data-l="网盘">
          <div style="font-weight:600"><?= htmlspecialchars($it['label'], ENT_QUOTES) ?></div>
          <div class="mono" style="color:var(--dim)"><?= htmlspecialchars($it['endpoint'], ENT_QUOTES) ?></div>
        </td>
        <td data-l="状态"><span class="badge <?= $v['status'] ?>"><?= htmlspecialchars($v['text'], ENT_QUOTES) ?></span></td>
        <td data-l="上限" class="mono"><?= htmlspecialchars((string)$it['max_mb'], ENT_QUOTES) ?> MB</td>
        <td data-l="保留" class="mono"><?= $it['keep_days'] > 0 ? htmlspecialchars((string)$it['keep_days'], ENT_QUOTES).' 天' : '未标注' ?></td>
        <td data-l="耗时" class="mono"><?= htmlspecialchars((string)($it['upload']['ms'] ?? $it['site']['ms'] ?? '-'), ENT_QUOTES) ?> ms</td>
        <td data-l="说明" style="font-size:12.5px;color:var(--dim)">
          <?php
            $d = [];
            if (!empty($it['gzip_wrap'])) $d[] = 'gzip 包裹';
            if (!empty($it['upload']['wrapped'])) $d[] = '已包裹';
            $err = (string)($it['upload']['err'] ?? $it['site']['err'] ?? '');
            if ($err !== '') $d[] = substr($err, 0, 60);
            echo htmlspecialchars(implode('；', $d) ?: '—', ENT_QUOTES);
          ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>

  <div class="note" style="margin-top:26px">
    <b>接口</b><br>
    <code>GET /</code> 或 <code>GET /api/health</code> — 探活结果 JSON<br>
    <code>GET /?refresh=1</code> — 触发深度检测（需令牌，见下）<br>
    <code>GET /api/providers</code> — 各后端调度状态（熔断、失败计数）<br>
    <code>POST /api/upload</code> — 上传，multipart 字段 <code>file</code><br>
    <code>GET /api/download?provider=&amp;url=</code> — 走中转下载
  </div>

  <div class="note">
    <b>触发检测的令牌</b>：带 <code>X-Relay-Token</code> 头，或 URL 加 <code>&amp;token=</code>。
    <pre>curl -H "X-Relay-Token: 你的令牌" "http://cswfwn-ai.uuk.pp.ua/?refresh=1"</pre>
  </div>
</div>

<footer><div class="wrap">
  disk-relay · PHP <?= htmlspecialchars(PHP_VERSION, ENT_QUOTES) ?> ·
  <a href="https://github.com/cswfwm/disk-relay">GitHub</a>
</div></footer>
</body>
</html>
    <?php
}
