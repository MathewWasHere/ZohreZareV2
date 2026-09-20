<?php
/* ==========================================================================
   sms-test.php — صفحه‌ی تشخیص پنل پیامکی

   این فایل را روی هاست باز کنید:
       https://zohrezare.ir/api/sms-test.php?key=<کلید_تشخیص>

   چه کاری می‌کند:
     ۱. سلامت PHP و افزونه‌های لازم را چک می‌کند
     ۲. اعتبار پنل و خط‌های فرستنده را می‌گیرد  → درست بودن نام کاربری
        و رمز را ثابت می‌کند
     ۳. اگر پاسخ سامانه آن چیزی نبود که انتظار داریم، «پاسخ خام» را
        نشان می‌دهد — یعنی عیناً همان چیزی که سرور برگردانده
     ۴. با «آزمایش حالت‌های اتصال» چند شکل مختلف درخواست را امتحان
        می‌کند تا معلوم شود این پنل کدام را می‌فهمد
     ۵. اگر شماره بدهید، یک کد تأیید واقعی می‌فرستد

   ⚠️ بعد از تمام شدن تست این فایل را از روی هاست پاک کنید.
   ========================================================================== */

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    exit('<p style="font-family:sans-serif">فایل api/config.php پیدا نشد. '
       . 'اول config.sample.php را به config.php کپی کنید و مقادیر را پر کنید.</p>');
}

/** @var array<string,mixed> $config */
$config = require $configPath;
$smsCfg = (array) ($config['sms'] ?? []);

/* --- محافظت ساده: بدون کلید درست، صفحه باز نمی‌شود --------------------
   کلید از کلید API ساخته می‌شود؛ اگر خالی بود، از رمز پنل. */
$guardBasis = (string) ($smsCfg['api_key'] ?? '');
if ($guardBasis === '') {
    $guardBasis = (string) ($smsCfg['password'] ?? '');
}
$guard = substr(hash('sha256', $guardBasis), 0, 12);

if (!hash_equals($guard, (string) ($_GET['key'] ?? ''))) {
    /* کلید روی صفحه نمایش داده نمی‌شود — وگرنه نگهبان بی‌فایده است.
       فرمولش: ۱۲ کاراکتر اول SHA-256 از کلید API پنل پیامک
       (یا رمز پنل، اگر کلید API خالی است). */
    http_response_code(403);
    exit('<p style="font-family:sans-serif;direction:rtl">دسترسی مجاز نیست. '
       . 'آدرس را با پارامتر <code>?key=…</code> باز کنید. '
       . 'کلید = ۱۲ کاراکتر اولِ <code>SHA-256</code> از کلید API پنل پیامک '
       . '(یا رمز پنل، اگر کلید API خالی است).</p>');
}

require __DIR__ . '/lib/sms.php';

$sms  = new Sms($smsCfg);
$rows = [];

function row(string $label, bool $ok, string $detail): array
{
    return ['label' => $label, 'ok' => $ok, 'detail' => $detail];
}

$authMode = (string) ($smsCfg['auth_mode'] ?? 'username_password');

/* ---------------- ۱) محیط ---------------- */
$rows[] = row('نسخه‌ی PHP', PHP_VERSION_ID >= 80000, PHP_VERSION . ' (حداقل ۸.۰ لازم است)');
$rows[] = row('افزونه‌ی cURL', function_exists('curl_init'), function_exists('curl_init') ? 'فعال' : 'غیرفعال — بدون این نمی‌توان پیامک فرستاد');
$rows[] = row('افزونه‌ی PDO MySQL', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'فعال' : 'غیرفعال — برای دیتابیس لازم است');
$rows[] = row('افزونه‌ی mbstring', extension_loaded('mbstring'), extension_loaded('mbstring') ? 'فعال' : 'غیرفعال');

/* ---------------- ۲) تنظیمات ---------------- */
$rows[] = row('آدرس وب‌سرویس', !empty($smsCfg['base_url']), (string) ($smsCfg['base_url'] ?? 'خالی است'));
$rows[] = row('روش ورود', true, $authMode === 'api_key' ? 'کلید API' : 'نام کاربری و رمز عبور');
$rows[] = row('نام کاربری پنل', !empty($smsCfg['username']), !empty($smsCfg['username']) ? (string) $smsCfg['username'] : 'خالی است');

if ($authMode === 'api_key') {
    $rows[] = row('کلید API', !empty($smsCfg['api_key']), !empty($smsCfg['api_key']) ? 'تنظیم شده' : 'خالی است');
} else {
    $rows[] = row('رمز پنل', !empty($smsCfg['password']), !empty($smsCfg['password']) ? 'تنظیم شده' : 'خالی است');
}

$rows[] = row('شماره‌ی خط فرستنده', !empty($smsCfg['from']), !empty($smsCfg['from']) ? (string) $smsCfg['from'] : 'خالی است');

/* ---------------- ۳) اتصال به سامانه ----------------
   پاسخ خام را هم نگه می‌داریم؛ اگر کد نتیجه ۱۰۰- شد یعنی سامانه
   چیزی برگردانده که ساختارش را نمی‌شناسیم و باید خودِ متن را دید. */
$credit     = $sms->credit();
$creditCall = $sms->lastCall();
$rows[] = row(
    'اعتبار پنل پیامکی',
    $credit['ok'],
    $credit['ok'] ? ('باقی‌مانده: ' . $credit['credit']) : $credit['message']
);

$senders     = $sms->senders();
$sendersCall = $sms->lastCall();
$rows[] = row(
    'خط‌های فرستنده‌ی حساب شما',
    $senders['ok'],
    $senders['ok'] ? implode('، ', $senders['senders']) : $senders['message']
);

/* اگر خط فرستنده‌ی تنظیم‌شده در فهرست حساب نبود، هشدار بده */
if ($senders['ok'] && !empty($smsCfg['from'])) {
    $inList = in_array((string) $smsCfg['from'], $senders['senders'], true);
    $rows[] = row(
        'خط تنظیم‌شده در فهرست حساب هست؟',
        $inList,
        $inList ? 'بله' : 'نه — مقدار from در config با هیچ‌کدام از خط‌های بالا یکی نیست'
    );
}

/* ---------------- ۴) بررسی متن قالب‌ها ----------------
   template() متن را از روی قالبِ نام‌گذاری‌شده می‌سازد؛ اگر قالب در
   config خالی باشد null برمی‌گرداند — یعنی آن پیامک فعلاً فرستاده
   نمی‌شود. این بخش فقط پیش‌نمایش می‌دهد، پیامکی نمی‌فرستد. */
function tplSampleVars(): array
{
    return [
        'code'    => '1234',
        'date'    => 'چهارشنبه ۲۹ اردیبهشت',
        'time'    => '۱۶:۳۰',
        'service' => 'خط چشم دائم و میکروبلیدینگ',
        'reason'  => 'در آن ساعت نوبت دیگری ثبت شده بود',
        'name'    => 'فاطمه محمدی نژاد',
        'phone'   => '۰۹۱۲۱۲۳۴۵۶۷',
    ];
}

$tplNames = [
    'otp'         => 'کد تأیید (otp)',
    'received'    => 'رسید ثبت درخواست (received)',
    'approved'    => 'تأیید نوبت (approved)',
    'rejected'    => 'رد نوبت (rejected)',
    'reminder'    => 'یادآوری (reminder)',
    'admin_alert' => 'خبر به سالن (admin_alert)',
];
$sampleVars = tplSampleVars();
foreach ($tplNames as $key => $label) {
    $tpl = $sms->template($key, $sampleVars);
    $rows[] = row(
        'قالب ' . $label,
        $tpl !== null,
        $tpl !== null ? $tpl : 'خالی است — این پیامک فرستاده نمی‌شود'
    );
}

/* ---------------- ۵) ارسال واقعی یک قالب ---------------- */
$sendResult = null;
$sendCall   = [];
$testPhone  = trim((string) ($_GET['to'] ?? ''));
$testTpl    = (string) ($_GET['tpl'] ?? 'otp');
if (!isset($tplNames[$testTpl])) {
    $testTpl = 'otp';
}

if ($testPhone !== '') {
    if ($testTpl === 'otp') {
        $otp        = (string) random_int(1000, 9999);
        $sendResult = $sms->sendOtp($testPhone, $otp);
        $sendCall   = $sms->lastCall();
        $sendResult['code_sent'] = $otp;
    } else {
        $text = $sms->template($testTpl, $sampleVars);
        if ($text === null) {
            $sendResult = [
                'ok'      => false,
                'code'    => 17,
                'message' => 'قالب «' . $testTpl . '» در config خالی است.',
                'id'      => null,
            ];
        } else {
            $sendResult = $sms->send($testPhone, $text, $testTpl);
            $sendCall   = $sms->lastCall();
        }
    }
    $sendResult['sent_tpl']    = $testTpl;
    $sendResult['text_sample'] = $sendResult['ok'] || (int) $sendResult['code'] !== 17
        ? ($sms->template($testTpl, $sampleVars) ?? '')
        : '';
}

/* ---------------- ۶) آزمایش حالت‌های اتصال ----------------
   فقط وقتی اجرا می‌شود که دکمه‌اش زده شود. متد GetCredit را با چند
   شکل مختلفِ درخواست صدا می‌زند — هیچ‌کدام پیامک نمی‌فرستد و هیچ
   هزینه‌ای ندارد. هدف: پیدا کردن شکلی که این پنل می‌فهمد. */
$probes  = [];
$doProbe = isset($_GET['probe']);

if ($doProbe) {
    $base  = rtrim((string) ($smsCfg['base_url'] ?? ''), '/');
    $https = preg_replace('#^http://#i', 'https://', $base);

    $variants = [
        ['نام کاربری و رمز، بدنه‌ی JSON (تنظیم فعلی)', [], []],
        ['کلید API به جای رمز، بدنه‌ی JSON',            [], ['auth_mode' => 'api_key']],
        ['نام کاربری و رمز، به‌علاوه‌ی فیلد apiKey',     [], ['with_api_key' => true]],
        ['نام کاربری و رمز، بدنه‌ی فرم (نه JSON)',      [], ['form' => true]],
    ];

    if (is_string($https) && $https !== $base) {
        $variants[] = ['همان تنظیم فعلی ولی روی HTTPS', [], ['base_url' => $https]];
    }

    foreach ($variants as [$title, $params, $opts]) {
        $probes[] = ['title' => $title] + $sms->probe('GetCredit', $params, $opts);
    }
}

/**
 * جعبه‌ی «پاسخ خام» — همان چیزی که سرور برگردانده، بدون دست‌کاری.
 */
function rawBox(array $call): string
{
    $raw = trim((string) ($call['raw'] ?? ''));
    if ($raw === '') {
        $raw = '(پاسخی برنگشت)';
    }
    $out  = '<div class="raw">';
    $out .= '<div><b>آدرس:</b> <code>' . htmlspecialchars((string) ($call['url'] ?? '')) . '</code></div>';
    $out .= '<div><b>نوع بدنه:</b> <code>' . htmlspecialchars((string) ($call['encoding'] ?? '')) . '</code>';
    $out .= ' &nbsp; <b>کد HTTP:</b> <code>' . (int) ($call['http'] ?? 0) . '</code></div>';
    if (!empty($call['error'])) {
        $out .= '<div><b>خطای شبکه:</b> <code>' . htmlspecialchars((string) $call['error']) . '</code></div>';
    }
    $out .= '<div><b>پاسخ خام سرور:</b></div>';
    $out .= '<pre>' . htmlspecialchars($raw) . '</pre>';
    $out .= '</div>';
    return $out;
}

?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>تشخیص پنل پیامکی</title>
<style>
  body { font-family: Tahoma, sans-serif; max-width: 820px; margin: 40px auto;
         padding: 0 16px; line-height: 1.9; color: #15100E; background: #FFFCFA; }
  h1 { font-size: 22px; }
  h2 { font-size: 18px; margin-top: 34px; }
  table { width: 100%; border-collapse: collapse; margin: 20px 0; }
  th, td { text-align: right; padding: 10px 12px; border-bottom: 1px solid #E2D0C1;
           vertical-align: top; font-size: 14px; }
  th { background: #FAF4EF; font-weight: 600; }
  .ok { color: #4C7A53; font-weight: 600; }
  .no { color: #A8443A; font-weight: 600; }
  code { background: #F0E4D9; padding: 2px 6px; border-radius: 4px; direction: ltr;
         display: inline-block; word-break: break-all; }
  .box { padding: 14px 16px; border-radius: 10px; margin: 18px 0; font-size: 14px; }
  .box--ok { background: #E9F2EA; }
  .box--no { background: #FBE9E6; }
  .box--warn { background: #FDF1DC; }
  .raw { background: #FAF4EF; border: 1px solid #E2D0C1; border-radius: 10px;
         padding: 12px 14px; margin: 12px 0 22px; font-size: 13px; }
  .raw pre { direction: ltr; text-align: left; background: #fff; padding: 10px 12px;
             border-radius: 8px; overflow-x: auto; white-space: pre-wrap;
             word-break: break-all; margin: 6px 0 0; font-size: 12px; }
  .probe { border: 1px solid #E2D0C1; border-radius: 10px; padding: 4px 14px 2px;
           margin: 14px 0; }
  .probe h3 { font-size: 15px; margin: 12px 0 4px; }
  form { margin: 20px 0; }
  input { padding: 8px 10px; border: 1px solid #D6C8BC; border-radius: 8px;
          font-family: inherit; direction: ltr; }
  button { padding: 8px 18px; border: 0; border-radius: 8px; background: #A55C44;
           color: #fff; font-family: inherit; cursor: pointer; }
  a.btn { display: inline-block; padding: 8px 18px; border-radius: 8px;
          background: #A55C44; color: #fff; text-decoration: none; }
</style>
</head>
<body>

<h1>تشخیص پنل پیامکی نیازپرداز</h1>

<table>
  <tr><th style="width:34%">بررسی</th><th style="width:14%">وضعیت</th><th>جزئیات</th></tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><?= htmlspecialchars($r['label']) ?></td>
    <td class="<?= $r['ok'] ? 'ok' : 'no' ?>"><?= $r['ok'] ? '✓ سالم' : '✗ مشکل' ?></td>
    <td><?= htmlspecialchars($r['detail']) ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<?php if (!$credit['ok'] || !$senders['ok']): ?>
  <div class="box box--no">
    <strong>سامانه‌ی پیامک آن‌طور که انتظار داریم جواب نداد.</strong><br>
    پاسخ خامِ زیر را ببینید — هر چه سرور گفته، عیناً همان‌جاست. اگر
    <code>کد نتیجه‌ی ناشناخته: -100</code> دیدید یعنی ارتباط برقرار شده
    ولی ساختار پاسخ آن چیزی نیست که مستندات می‌گوید.
  </div>

  <h2>پاسخ خام — گرفتن اعتبار</h2>
  <?= rawBox($creditCall) ?>

  <h2>پاسخ خام — گرفتن خط‌های فرستنده</h2>
  <?= rawBox($sendersCall) ?>

  <h2>آزمایش حالت‌های اتصال</h2>
  <p>چند شکل مختلف درخواست را روی متد <code>GetCredit</code> امتحان می‌کند
  تا معلوم شود این پنل کدام را می‌فهمد. <strong>هیچ پیامکی فرستاده
  نمی‌شود و هیچ اعتباری خرج نمی‌شود.</strong> چند ثانیه طول می‌کشد.</p>

  <?php if (!$doProbe): ?>
    <p><a class="btn" href="?key=<?= htmlspecialchars($guard) ?>&amp;probe=1">شروع آزمایش</a></p>
  <?php else: ?>
    <?php foreach ($probes as $p): ?>
      <div class="probe">
        <h3>
          <?= htmlspecialchars($p['title']) ?> —
          <span class="<?= $p['code'] === 0 ? 'ok' : 'no' ?>">
            <?= $p['code'] === 0 ? '✓ جواب داد' : ('کد نتیجه: ' . (int) $p['code']) ?>
          </span>
        </h3>
        <?php if (!empty($p['error'])): ?>
          <div><b>خطا:</b> <?= htmlspecialchars((string) $p['error']) ?></div>
        <?php endif; ?>
        <?= rawBox($p['call']) ?>
      </div>
    <?php endforeach; ?>

    <div class="box box--warn">
      اگر یکی از حالت‌های بالا <span class="ok">✓ جواب داد</span> گرفت،
      همان را در <code>api/config.php</code> تنظیم کنید. اگر هیچ‌کدام
      جواب نداد، کل همین صفحه را برای من بفرستید.
    </div>
  <?php endif; ?>
<?php endif; ?>

<h2>ارسال پیامک آزمایشی</h2>
<p>یک شماره وارد کنید تا کد تأیید واقعی برایش فرستاده شود. این تست ثابت
می‌کند که خط فرستنده درست است و متن با الگوی تأییدشده می‌خواند.</p>

<form method="get">
  <input type="hidden" name="key" value="<?= htmlspecialchars($guard) ?>">
  <label>
    قالب:
    <select name="tpl">
      <?php foreach ($tplNames as $key => $label): ?>
        <option value="<?= htmlspecialchars($key) ?>"<?= $testTpl === $key ? ' selected' : '' ?>>
          <?= htmlspecialchars($label) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <input type="text" name="to" placeholder="09123456789"
         value="<?= htmlspecialchars($testPhone) ?>" required>
  <button type="submit">ارسال تستی این قالب</button>
</form>

<?php if ($sendResult !== null): ?>
  <div class="box <?= $sendResult['ok'] ? 'box--ok' : 'box--no' ?>">
    <strong><?= $sendResult['ok'] ? '✓ ارسال موفق' : '✗ ارسال ناموفق' ?></strong><br>
    قالب: <code><?= htmlspecialchars((string) $sendResult['sent_tpl']) ?></code><br>
    کد نتیجه: <code><?= (int) $sendResult['code'] ?></code><br>
    پیام: <?= htmlspecialchars((string) $sendResult['message']) ?><br>
    <?php if ($sendResult['ok'] && empty($sendResult['skipped'])): ?>
      متن ارسال‌شده:
      <code style="white-space:pre-wrap"><?= htmlspecialchars((string) $sendResult['text_sample']) ?></code>
    <?php endif; ?>
    <?php if ((int) $sendResult['code'] === 18): ?>
      <br><br><strong>یعنی متن با الگوی تأییدشده نمی‌خواند.</strong>
      متن قالب <code><?= htmlspecialchars((string) $sendResult['sent_tpl']) ?></code>
      در <code>api/config.php</code> باید کاراکتر‌به‌کاراکتر همان چیزی باشد
      که در پنل تأیید شده — حتی فاصله‌ها، خط جدید و دو‌نقطه.
    <?php endif; ?>
  </div>
  <?php if (!$sendResult['ok'] && !empty($sendCall)): ?>
    <h2>پاسخ خام — ارسال</h2>
    <?= rawBox($sendCall) ?>
  <?php endif; ?>
<?php endif; ?>

<div class="box box--warn">
  ⚠️ بعد از تمام شدن تست، این فایل را از روی هاست پاک کنید:
  <code>api/sms-test.php</code>
</div>

</body>
</html>
