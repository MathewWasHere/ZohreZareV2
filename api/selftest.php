<?php
/* ==========================================================================
   selftest.php — بررسی سلامت نصب

   این صفحه را بعد از آپلود باز کنید تا مطمئن شوید همه چیز سر جایش
   است. هیچ چیزی را تغییر نمی‌دهد — فقط می‌خواند و گزارش می‌دهد.

        https://زهره‌زارع.ir/api/selftest.php

   ⚠️ بعد از تمام شدن کار، این فایل را از هاست پاک کنید.
   ========================================================================== */

define('ZZ_APP', true);
ini_set('display_errors', '0');

/* ------------------------------------------------------------------
   نگهبان — این صفحه وضعیت نصب (نام دیتابیس، شماره‌ی مدیران،
   تعداد کاربران و…) را نشان می‌دهد و نباید برای عموم باز باشد.
   کلید = ۱۲ کاراکتر اول SHA-256 (رمز دیتابیس + |zz-selftest)
   تا کسی که رمز دیتابیس را ندارد نتواند صفحه را باز کند.
   مثال: selftest.php?key=abcd1234ef56
   ------------------------------------------------------------------ */
$configPath = __DIR__ . '/config.php';
if (is_file($configPath)) {
    /** @var array<string,mixed> $config */
    $config = require $configPath;
    $basis  = (string) ($config['db']['pass'] ?? '');
    if ($basis !== '') {
        $guard = substr(hash('sha256', $basis . '|zz-selftest'), 0, 12);
        $given = (string) ($_GET['key'] ?? '');
        if (!hash_equals($guard, $given)) {
            http_response_code(403);
            exit(
                '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8">' .
                '<title>دسترسی مجاز نیست</title></head>' .
                '<body style="font-family:system-ui;direction:rtl;padding:2rem;line-height:2">' .
                '<h2>🔒 این صفحه قفل شده است</h2>' .
                '<p>وضعیت نصب فقط برای مدیر سایت است.</p>' .
                '<p>برای باز کردنش، آدرس را با <code>?key=…</code> باز کنید؛ ' .
                'کلید = ۱۲ کاراکتر اولِ <code>SHA-256</code> از ' .
                '<code>رمز-دیتابیس|zz-selftest</code> ' .
                '(رمز دیتابیس در api/config.php آمده است).</p>' .
                '</body></html>'
            );
        }
    }
}

require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/jalali.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/sms.php';

/* این صفحه HTML چاپ می‌کند، پس خطای اتصال باید استثنا شود نه
   پاسخ JSON — وگرنه وسط صفحه یک تکه JSON ظاهر می‌شود. */
Db::throwOnConnectError();

$rows = [];
$fail = 0;
$warn = 0;

/**
 * ثبت یک بررسی.
 * $state: ok | warn | fail
 */
function check(string $title, string $state, string $detail = ''): void
{
    global $rows, $fail, $warn;
    $rows[] = ['title' => $title, 'state' => $state, 'detail' => $detail];
    if ($state === 'fail') {
        $fail++;
    } elseif ($state === 'warn') {
        $warn++;
    }
}

/* ================= ۱) محیط ================= */

$php = PHP_VERSION;
check(
    'نسخه‌ی PHP',
    version_compare($php, '7.4', '>=') ? 'ok' : 'fail',
    $php . (version_compare($php, '7.4', '>=') ? '' : ' — حداقل ۷٫۴ لازم است')
);

foreach (['pdo_mysql' => 'اتصال به دیتابیس', 'curl' => 'ارسال پیامک',
          'mbstring' => 'متن فارسی', 'json' => 'پاسخ‌های API'] as $ext => $why) {
    check(
        'افزونه‌ی ' . $ext,
        extension_loaded($ext) ? 'ok' : 'fail',
        extension_loaded($ext) ? 'فعال' : 'نصب نیست — برای ' . $why . ' لازم است'
    );
}

check(
    'بازنویسی آدرس (mod_rewrite)',
    function_exists('apache_get_modules')
        ? (in_array('mod_rewrite', apache_get_modules(), true) ? 'ok' : 'warn')
        : 'warn',
    function_exists('apache_get_modules')
        ? (in_array('mod_rewrite', apache_get_modules(), true)
            ? 'فعال' : 'غیرفعال — بدون آن آدرس‌های /api/… کار نمی‌کنند')
        : 'قابل تشخیص نیست؛ با باز کردن /api/health بررسی کنید'
);

/* ================= ۲) تنظیمات ================= */

if (!is_file(__DIR__ . '/config.php')) {
    check('فایل تنظیمات', 'fail', 'api/config.php وجود ندارد. config.sample.php را کپی کنید.');
} else {
    check('فایل تنظیمات', 'ok', 'api/config.php پیدا شد');
    Config::load();

    foreach ([
        'db.name'      => 'نام دیتابیس',
        'db.user'      => 'کاربر دیتابیس',
        'db.pass'      => 'رمز دیتابیس',
        'sms.username' => 'نام کاربری پنل پیامک',
        'sms.from'     => 'شماره‌ی خط فرستنده',
    ] as $key => $label) {
        $v = (string) Config::get($key, '');
        check($label, $v !== '' ? 'ok' : 'fail', $v !== '' ? 'وارد شده' : 'خالی است');
    }

    $admins = (array) Config::get('admin_phones', []);
    check(
        'شماره‌ی مدیران',
        $admins ? 'ok' : 'warn',
        $admins
            ? Jalali::fa(implode('، ', $admins))
            : 'خالی — هیچ‌کس به پنل مدیریت دسترسی نخواهد داشت'
    );

    check(
        'حالت اشکال‌زدایی',
        Config::get('app.debug') ? 'warn' : 'ok',
        Config::get('app.debug')
            ? 'روشن است — روی سایت واقعی خاموشش کنید'
            : 'خاموش (درست)'
    );
}

/* ================= ۳) دسترسی فایل‌ها ================= */

$cfgUrl = (Http::isHttps() ? 'https://' : 'http://')
        . ($_SERVER['HTTP_HOST'] ?? '') . dirname($_SERVER['SCRIPT_NAME'] ?? '') . '/config.php';

check(
    'محافظت از فایل تنظیمات',
    is_file(__DIR__ . '/.htaccess') ? 'ok' : 'fail',
    is_file(__DIR__ . '/.htaccess')
        ? 'فایل api/.htaccess موجود است — با باز کردن ' . $cfgUrl . ' مطمئن شوید ۴۰۳ می‌دهد'
        : 'api/.htaccess آپلود نشده! رمزها از طریق وب قابل خواندن‌اند.'
);

/* ================= ۴) دیتابیس ================= */

$dbOk = false;
if (is_file(__DIR__ . '/config.php')) {
    try {
        $ver = Db::val('SELECT VERSION()');
        check('اتصال به دیتابیس', 'ok', 'MySQL/MariaDB ' . $ver);
        $dbOk = true;
    } catch (Throwable $e) {
        check('اتصال به دیتابیس', 'fail', 'برقرار نشد — نام، کاربر یا رمز را بررسی کنید');
    }
}

if ($dbOk) {
    $tables = ['users', 'sessions', 'otp_codes', 'services', 'service_variants',
               'appointments', 'closed_days', 'blocked_slots', 'sms_log'];
    $missing = [];
    foreach ($tables as $t) {
        if (!Db::tableExists($t)) {
            $missing[] = $t;
        }
    }
    check(
        'جدول‌های دیتابیس',
        $missing ? 'fail' : 'ok',
        $missing
            ? 'این‌ها ساخته نشده‌اند: ' . implode('، ', $missing)
              . ' — فایل api/schema.sql را در phpMyAdmin ایمپورت کنید'
            : Jalali::fa(count($tables)) . ' جدول موجود است'
    );

    if (!$missing) {
        $n = (int) Db::val('SELECT COUNT(*) FROM services');
        check(
            'کاتالوگ خدمات',
            $n > 0 ? 'ok' : 'fail',
            $n > 0 ? Jalali::fa($n) . ' خدمت ثبت شده'
                   : 'خالی است — schema.sql کامل ایمپورت نشده'
        );

        $lock = Db::one(
            "SELECT COLUMN_NAME FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'appointments'
                AND column_name = 'slot_lock'"
        );
        check(
            'قفل ضدِ رزرو هم‌زمان',
            $lock ? 'ok' : 'warn',
            $lock
                ? 'فعال'
                : 'اختیاری و فعال نیست — در صفحه‌ی install.php دکمه‌ی «افزودن قفل» را بزنید'
        );

        check('کاربران ثبت‌شده', 'ok',
              Jalali::fa((int) Db::val('SELECT COUNT(*) FROM users')) . ' نفر');
        check('نوبت‌های ثبت‌شده', 'ok',
              Jalali::fa((int) Db::val('SELECT COUNT(*) FROM appointments')) . ' نوبت');
    }
}

/* ================= ۵) پیامک ================= */

if (is_file(__DIR__ . '/config.php')) {
    if (!Config::get('sms.enabled')) {
        check(
            'ارسال پیامک',
            'warn',
            'خاموش است — کد تأیید روی صفحه نشان داده می‌شود و پیامکی نمی‌رود. '
            . 'برای راه‌اندازی واقعی enabled را true کنید.'
        );
    } else {
        $sms = new Sms();

        $credit = $sms->credit();
        check(
            'اعتبار پنل پیامک',
            $credit['ok'] ? ($credit['credit'] > 20 ? 'ok' : 'warn') : 'fail',
            $credit['ok']
                ? Jalali::fa((string) $credit['credit']) . ' پیامک'
                  . ($credit['credit'] > 20 ? '' : ' — رو به اتمام است')
                : $credit['message'] . ' — برای دیدن پاسخ خام سامانه صفحه‌ی sms-test.php را باز کنید'
        );

        $senders = $sms->senders();
        $from    = (string) Config::get('sms.from', '');
        if ($senders['ok']) {
            $has = in_array($from, $senders['senders'], true);
            check(
                'خط فرستنده',
                $has ? 'ok' : 'fail',
                $has
                    ? Jalali::fa($from) . ' در حساب شما فعال است'
                    : Jalali::fa($from) . ' جزو خط‌های حساب شما نیست. خط‌های موجود: '
                      . Jalali::fa(implode('، ', $senders['senders']))
            );
        } else {
            /* بعضی حساب‌ها فهرست خط‌ها را از وب‌سرویس نمی‌دهند
               («Sequence contains no elements»). این جلوی ارسال را
               نمی‌گیرد، پس هشدار است نه خطا. */
            check(
                'خط فرستنده',
                'warn',
                'پنل فهرست خط‌ها را نمی‌دهد (' . $senders['message'] . '). '
                . 'این مشکلی برای ارسال نیست — با تست واقعی پیامک مطمئن شوید.'
            );
        }

        /* متن قالب — مهم‌ترین بررسی.
           نیازپرداز متن ارسالی را با الگوی تأییدشده تطبیق می‌دهد؛ اگر
           یک کاراکتر فرق کند، کد ۱۸ برمی‌گردد. */
        $otpText = (new Sms())->template('otp', ['code' => '1234']);
        check(
            'متن کد تأیید',
            $otpText ? 'ok' : 'fail',
            $otpText
                ? 'نمونه: ' . $otpText . ' — باید کاراکتر‌به‌کاراکتر با الگوی تأییدشده در پنل یکی باشد'
                : 'قالب otp در تنظیمات خالی است'
        );

        foreach (['approved' => 'تأیید نوبت', 'rejected' => 'رد نوبت',
                  'reminder' => 'یادآوری',
                  'received' => 'رسید ثبت درخواست',
                  'admin_alert' => 'خبر به سالن'] as $k => $label) {
            $t = Config::get('sms.templates.' . $k);
            check(
                'قالب پیامک ' . $label,
                'warn',
                $t ? 'تعریف شده: ' . $t
                   : 'تعریف نشده — این پیامک فرستاده نمی‌شود (تا وقتی الگویش در پنل تأیید نشده، همین درست است)'
            );
        }
    }
}

/* ================= خروجی ================= */

$state = $fail ? 'fail' : ($warn ? 'warn' : 'ok');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>بررسی سلامت نصب — زهره زارع</title>
<style>
  :root {
    --bg: #FFFCFA; --card: #FFFFFF; --ink: #15100E; --soft: #45332B;
    --muted: #6B564C; --line: rgba(21,16,14,.14);
    --ok: #4C7A53; --ok-bg: #E9F2EA;
    --danger: #A8443A; --danger-bg: #FBE9E6;
    --warn: #8A6318; --warn-bg: #FDF1DC;
    --accent: #A55C44;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; padding: 24px 16px 64px; background: var(--bg); color: var(--ink);
    font: 15px/1.8 Tahoma, "Segoe UI", sans-serif;
  }
  .wrap { max-width: 760px; margin: 0 auto; }
  h1 { font-size: 22px; margin: 0 0 4px; }
  .sub { color: var(--muted); font-size: 13px; margin: 0 0 24px; }
  .banner {
    border-radius: 14px; padding: 16px 18px; margin-bottom: 24px;
    font-weight: bold; border: 1px solid transparent;
  }
  .banner.ok { background: var(--ok-bg); color: var(--ok); border-color: var(--ok); }
  .banner.warn { background: var(--warn-bg); color: var(--warn); border-color: var(--warn); }
  .banner.fail { background: var(--danger-bg); color: var(--danger); border-color: var(--danger); }
  .card {
    background: var(--card); border: 1px solid var(--line); border-radius: 14px;
    overflow: hidden;
  }
  .row {
    display: flex; gap: 12px; align-items: flex-start;
    padding: 12px 16px; border-bottom: 1px solid var(--line);
  }
  .row:last-child { border-bottom: 0; }
  .dot {
    flex: 0 0 auto; width: 22px; height: 22px; border-radius: 50%;
    display: grid; place-items: center; font-size: 13px; color: #fff; margin-top: 4px;
  }
  .dot.ok { background: var(--ok); }
  .dot.warn { background: var(--warn); }
  .dot.fail { background: var(--danger); }
  .t { font-weight: bold; }
  .d { color: var(--soft); font-size: 13px; word-break: break-word; }
  .note {
    margin-top: 24px; padding: 14px 16px; border-radius: 12px;
    background: var(--warn-bg); color: var(--warn); font-size: 13px;
    border: 1px solid var(--warn);
  }
  code {
    background: rgba(21,16,14,.06); padding: 1px 5px; border-radius: 5px;
    font-family: Menlo, Consolas, monospace; direction: ltr; display: inline-block;
  }
</style>
</head>
<body>
<div class="wrap">
  <h1>بررسی سلامت نصب</h1>
  <p class="sub">سایت زهره زارع — <?= htmlspecialchars(date('Y-m-d H:i'), ENT_QUOTES, 'UTF-8') ?></p>

  <div class="banner <?= $state ?>">
    <?php if ($state === 'ok'): ?>
      همه چیز درست است. سایت آماده‌ی کار است.
    <?php elseif ($state === 'warn'): ?>
      <?= Jalali::fa($warn) ?> هشدار — سایت کار می‌کند ولی موارد زیر را ببینید.
    <?php else: ?>
      <?= Jalali::fa($fail) ?> مشکل جدی پیدا شد. تا رفع نشوند سایت درست کار نمی‌کند.
    <?php endif; ?>
  </div>

  <div class="card">
    <?php foreach ($rows as $r): ?>
      <div class="row">
        <div class="dot <?= $r['state'] ?>"><?=
          $r['state'] === 'ok' ? '✓' : ($r['state'] === 'warn' ? '!' : '×')
        ?></div>
        <div>
          <div class="t"><?= htmlspecialchars($r['title'], ENT_QUOTES, 'UTF-8') ?></div>
          <?php if ($r['detail'] !== ''): ?>
            <div class="d"><?= htmlspecialchars($r['detail'], ENT_QUOTES, 'UTF-8') ?></div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="note">
    بعد از اینکه همه چیز سبز شد، این فایل و <code>api/sms-test.php</code> را
    از هاست پاک کنید. این صفحه اطلاعات نصب را نشان می‌دهد و نباید در
    دسترس عموم بماند.
  </div>
</div>
</body>
</html>
