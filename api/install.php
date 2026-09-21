<?php
/* ==========================================================================
   install.php — ساخت جدول‌های دیتابیس

   چرا این صفحه وجود دارد؟
   ایمپورت کردن schema.sql در phpMyAdmin دو جای لغزنده دارد: باید
   دقیقاً دیتابیس درست انتخاب شده باشد (وگرنه خطای «No database
   selected» می‌گیرید ولی پیام «اجرا شد» هم می‌بینید)، و پیام‌های
   خطایش انگلیسی و گنگ‌اند.

   این صفحه همان فایل را اجرا می‌کند، ولی از روی api/config.php وصل
   می‌شود — یعنی دیتابیس صد‌در‌صد همانی است که سایت استفاده می‌کند و
   امکان انتخاب اشتباه اصلاً وجود ندارد. هر دستور را جدا اجرا می‌کند
   و اگر جایی خطا داد، دقیقاً می‌گوید کدام دستور و چه خطایی.

   روش استفاده:
       https://zohrezare.ir/api/install.php
   (خودش آدرس کامل با کلید را نشان می‌دهد)

   ⚠️ بعد از ساخته شدن جدول‌ها این فایل را از هاست پاک کنید.
   ========================================================================== */

define('ZZ_APP', true);
ini_set('display_errors', '0');
set_time_limit(120);

require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/jalali.php';
require __DIR__ . '/lib/db.php';

/* خروجی این صفحه HTML است، نه JSON */
Db::throwOnConnectError();

/* ------------------------------------------------------------------
   نگهبان
   ------------------------------------------------------------------ */

$configExists = is_file(__DIR__ . '/config.php');

$guardKey = '';
if ($configExists) {
    Config::load();
    /* کلید از روی رمز دیتابیس ساخته می‌شود: کسی که رمز را ندارد
       نمی‌تواند این صفحه را اجرا کند، و خود رمز هم هیچ‌جا نمایش
       داده نمی‌شود. */
    $guardKey = substr(hash('sha256', (string) Config::get('db.pass') . '|zz-install'), 0, 12);
}

$given   = isset($_GET['key']) ? (string) $_GET['key'] : '';
$allowed = $configExists && $guardKey !== '' && hash_equals($guardKey, $given);
$doRun   = $allowed && isset($_GET['run']);
$doLock  = $allowed && isset($_GET['lock']);

/* ------------------------------------------------------------------
   وضعیت دیتابیس — ساخته شده یا نه؟

   PHP روی هاست اشتراکی معمولاً اجازه‌ی ساختن دیتابیس را ندارد؛ این
   کار در cPanel فقط به کاربری که وارد پنل شده داده می‌شود. ولی بعضی
   هاست‌ها این اجازه را می‌دهند. پس اول وضعیت را دقیق تشخیص می‌دهیم
   تا پیام درست بدهیم، و اگر هاست اجازه داد، خودمان می‌سازیمش.
   ------------------------------------------------------------------ */

/**
 * کد خطای MySQL را به یک وضعیت خوانا تبدیل می‌کند.
 *
 * @return array{0:string,1:int}  [وضعیت، کد خطا]
 *   ok      → اتصال برقرار است
 *   missing → دیتابیس وجود ندارد (۱۰۴۹)
 *   badcreds→ نام کاربری یا رمز غلط است (۱۰۴۵)
 *   nopriv  → کاربر به این دیتابیس دسترسی ندارد (۱۰۴۴ / ۱۱۴۲)
 *   down    → سرور دیتابیس جواب نمی‌دهد
 */
function db_state(?Throwable $e): array
{
    if ($e === null) {
        return ['ok', 0];
    }

    $code = 0;
    if ($e instanceof PDOException && isset($e->errorInfo[1])) {
        $code = (int) $e->errorInfo[1];
    }

    if ($code === 0) {
        $msg = $e->getMessage();
        if (stripos($msg, 'Unknown database') !== false) {
            $code = 1049;
        } elseif (stripos($msg, 'Access denied for user') !== false) {
            $code = 1045;
        }
    }

    if ($code === 1049) {
        return ['missing', $code];
    }
    if ($code === 1045) {
        return ['badcreds', $code];
    }
    if ($code === 1044 || $code === 1142) {
        return ['nopriv', $code];
    }

    return ['down', $code];
}

/**
 * تلاش برای ساختن دیتابیس بدون انتخاب دیتابیس.
 *
 * روی اکثر هاست‌های اشتراکی کاربر MySQL اجازه‌ی CREATE DATABASE ندارد
 * و این تابع خطا برمی‌گرداند — که اشکالی ندارد، چون صفحه همان‌جا
 * راهنمای سه‌کلیکی cPanel را نشان می‌دهد.
 *
 * @return array{0:bool,1:string}  [موفق؟، پیام خطا]
 */
function try_create_database(array $cfg): array
{
    $name = trim((string) ($cfg['name'] ?? ''));
    if ($name === '') {
        return [false, 'نام دیتابیس در api/config.php خالی است.'];
    }

    try {
        $dsn = 'mysql:host=' . $cfg['host'] . ';charset=' . ($cfg['charset'] ?? 'utf8mb4');
        $pdo = new PDO($dsn, (string) $cfg['user'], (string) $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $safe = str_replace('`', '``', $name);
        $pdo->exec(
            'CREATE DATABASE IF NOT EXISTS `' . $safe . '`'
            . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );

        return [true, ''];
    } catch (Throwable $e) {
        return [false, $e->getMessage()];
    }
}

/* اسم‌های داخل config — برای نمایش و برای راهنمای cPanel.
   cPanel پیشوند حساب را خودش اضافه می‌کند، پس نسخه‌ی بدون پیشوند
   را هم نگه می‌داریم تا کاربر بداند در ویزارد چه بنویسد. */
$cfgDbName = '';
$cfgDbUser = '';
$dbNameRaw = '';
$dbUserRaw = '';
if ($configExists) {
    $cfgDbName = trim((string) Config::get('db.name'));
    $cfgDbUser = trim((string) Config::get('db.user'));
    $dbNameRaw = strpos($cfgDbName, '_') !== false
        ? substr($cfgDbName, (int) strpos($cfgDbName, '_') + 1) : $cfgDbName;
    $dbUserRaw = strpos($cfgDbUser, '_') !== false
        ? substr($cfgDbUser, (int) strpos($cfgDbUser, '_') + 1) : $cfgDbUser;
}

/* وضعیت را همین اول مشخص کن */
$dbState      = 'unknown';
$dbStateMsg   = '';
$dbStateCode  = 0;
$dbCreated    = false;   // خودمان ساختیمش
$dbCreateErr  = '';

if ($configExists) {
    try {
        Db::conn();
        $dbState = 'ok';
    } catch (Throwable $e) {
        [$dbState, $dbStateCode] = db_state($e);
        $dbStateMsg = $e->getMessage();
    }
}

$doCreateDb = $allowed && isset($_GET['create-db']);

if ($doCreateDb && $dbState === 'missing') {
    [$ok, $err] = try_create_database((array) Config::get('db', []));
    if ($ok) {
        /* اتصال را دوباره امتحان کن تا مطمئن شویم دیتابیس واقعاً هست */
        try {
            Db::conn();
            $dbState   = 'ok';
            $dbCreated = true;
        } catch (Throwable $e) {
            [$dbState, $dbStateCode] = db_state($e);
            $dbStateMsg  = $e->getMessage();
            $dbCreateErr = $e->getMessage();
        }
    } else {
        $dbCreateErr = $err;
    }
}

/* ------------------------------------------------------------------
   جدا کردن دستورهای SQL

   نمی‌شود ساده روی «;» شکست: نقطه‌ویرگول ممکن است داخل رشته یا
   کامنت باشد. این تابع رشته‌ها، کامنت بلوکی و کامنت خطی را می‌شناسد.
   ------------------------------------------------------------------ */

/**
 * @return array<int,string>
 */
function split_sql(string $sql): array
{
    $out = [];
    $cur = '';
    $len = strlen($sql);

    $inString = false;
    $inBlock  = false;
    $inLine   = false;

    for ($i = 0; $i < $len; $i++) {
        $c    = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($inBlock) {
            if ($c === '*' && $next === '/') {
                $inBlock = false;
                $i++;
            }
            continue;   // کامنت اصلاً وارد دستور نمی‌شود
        }

        if ($inLine) {
            if ($c === "\n") {
                $inLine = false;
                $cur .= "\n";
            }
            continue;
        }

        if ($inString) {
            $cur .= $c;
            if ($c === '\\') {
                if ($next !== '') {
                    $cur .= $next;
                    $i++;
                }
                continue;
            }
            if ($c === "'") {
                $inString = false;
            }
            continue;
        }

        if ($c === '/' && $next === '*') {
            $inBlock = true;
            $i++;
            continue;
        }

        /* «--» در MySQL فقط وقتی کامنت است که بعدش فاصله باشد */
        if ($c === '-' && $next === '-'
            && ($i + 2 >= $len || $sql[$i + 2] === ' ' || $sql[$i + 2] === "\t"
                || $sql[$i + 2] === "\n" || $sql[$i + 2] === "\r")) {
            $inLine = true;
            $i++;
            continue;
        }

        if ($c === "'") {
            $inString = true;
            $cur .= $c;
            continue;
        }

        if ($c === ';') {
            if (trim($cur) !== '') {
                $out[] = trim($cur);
            }
            $cur = '';
            continue;
        }

        $cur .= $c;
    }

    if (trim($cur) !== '') {
        $out[] = trim($cur);
    }

    return $out;
}

/** چند کلمه‌ی اول دستور، برای نمایش */
function describe_sql(string $st): string
{
    $flat = preg_replace('/\s+/', ' ', $st);
    if (preg_match('/^CREATE TABLE(?:\s+IF NOT EXISTS)?\s+`?(\w+)`?/i', $flat, $m)) {
        return 'ساخت جدول ' . $m[1];
    }
    if (preg_match('/^INSERT INTO\s+`?(\w+)`?/i', $flat, $m)) {
        return 'درج داده در ' . $m[1];
    }
    if (preg_match('/^ALTER TABLE\s+`?(\w+)`?/i', $flat, $m)) {
        return 'تغییر جدول ' . $m[1];
    }
    if (preg_match('/^SET\s+(\w+)/i', $flat, $m)) {
        return 'تنظیم ' . $m[1];
    }
    return mb_substr($flat, 0, 60, 'UTF-8');
}

/* ------------------------------------------------------------------
   اجرا
   ------------------------------------------------------------------ */

$results   = [];
$okCount   = 0;
$failCount = 0;
$dbError   = null;
$fileError = null;
$tables    = [];

$sqlFile = __DIR__ . '/schema.sql';

if ($doRun) {
    if (!is_file($sqlFile)) {
        $fileError = 'فایل api/schema.sql روی هاست پیدا نشد. آن را آپلود کنید.';
    } else {
        $sql = (string) file_get_contents($sqlFile);
        /* اگر ویرایشگری BOM اضافه کرده باشد، اولین دستور خراب می‌شود */
        $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);

        $statements = split_sql($sql);

        try {
            $pdo = Db::conn();

            foreach ($statements as $st) {
                $label = describe_sql($st);
                try {
                    $pdo->exec($st);
                    $results[] = ['ok' => true, 'label' => $label, 'msg' => 'انجام شد'];
                    $okCount++;
                } catch (PDOException $e) {
                    $results[] = [
                        'ok'    => false,
                        'label' => $label,
                        'msg'   => '#' . ($e->errorInfo[1] ?? '?') . ' — ' . ($e->errorInfo[2] ?? $e->getMessage()),
                        'sql'   => mb_substr(preg_replace('/\s+/', ' ', $st), 0, 300, 'UTF-8'),
                    ];
                    $failCount++;
                }
            }

            /* گزارش نهایی: واقعاً چه چیزی ساخته شد */
            foreach (Db::all(
                'SELECT table_name AS t,
                        (SELECT COUNT(*) FROM information_schema.columns c
                          WHERE c.table_schema = DATABASE() AND c.table_name = i.table_name) AS cols
                   FROM information_schema.tables i
                  WHERE i.table_schema = DATABASE()
                  ORDER BY table_name'
            ) as $row) {
                $tables[] = $row;
            }
        } catch (Throwable $e) {
            $dbError = $e->getMessage();
        }
    }
}

/* ------------------------------------------------------------------
   قفل ضدِ رزرو هم‌زمان (اختیاری)

   یک ستون محاسبه‌شده به appointments اضافه می‌کند که «تاریخ + ساعت»
   نوبت‌های فعال را یکتا می‌کند. اگر دو نفر در همان لحظه یک ساعت را
   بگیرند، دیتابیس دومی را رد می‌کند. به MySQL 5.7+ یا MariaDB 10.2+
   نیاز دارد؛ روی سرورهای قدیمی‌تر خطا می‌دهد و سایت بدون آن هم درست
   کار می‌کند.
   ------------------------------------------------------------------ */
$lockDone    = false;
$lockAlready = false;
$lockError   = null;

if ($doLock) {
    try {
        $exists = (int) Db::val(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'appointments'
                AND column_name = 'slot_lock'"
        );

        if ($exists > 0) {
            $lockAlready = true;
        } else {
            Db::conn()->exec(
                "ALTER TABLE appointments
                   ADD COLUMN slot_lock VARCHAR(20)
                     GENERATED ALWAYS AS (
                       CASE WHEN status IN ('pending','confirmed')
                            THEN CONCAT(`date`, ' ', `time`) ELSE NULL END
                     ) STORED,
                   ADD UNIQUE KEY uk_appt_slot (slot_lock)"
            );
            $lockDone = true;
        }
    } catch (Throwable $e) {
        $lockError = $e instanceof PDOException
            ? ('#' . ($e->errorInfo[1] ?? '?') . ' — ' . ($e->errorInfo[2] ?? $e->getMessage()))
            : $e->getMessage();
    }
}

/* وضعیت فعلی دیتابیس، حتی وقتی هنوز اجرا نکرده‌ایم */
$currentTables = [];
$currentDbName = '';
if ($allowed && !$doRun && !$doLock) {
    try {
        $currentDbName = (string) Db::val('SELECT DATABASE()');
        $currentTables = array_column(
            Db::all('SELECT table_name AS t FROM information_schema.tables
                      WHERE table_schema = DATABASE() ORDER BY table_name'),
            't'
        );
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

$selfUrl = htmlspecialchars(
    strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?'),
    ENT_QUOTES,
    'UTF-8'
);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>ساخت جدول‌های دیتابیس — زهره زارع</title>
<style>
  :root {
    --bg:#FFFCFA; --card:#FFFFFF; --ink:#15100E; --soft:#45332B; --muted:#6B564C;
    --line:rgba(21,16,14,.14); --ok:#4C7A53; --ok-bg:#E9F2EA;
    --danger:#A8443A; --danger-bg:#FBE9E6; --warn:#8A6318; --warn-bg:#FDF1DC;
    --accent:#A55C44;
  }
  *{box-sizing:border-box}
  body{margin:0;padding:24px 16px 64px;background:var(--bg);color:var(--ink);
       font:15px/1.85 Tahoma,"Segoe UI",sans-serif}
  .wrap{max-width:800px;margin:0 auto}
  h1{font-size:22px;margin:0 0 4px}
  h2{font-size:17px;margin:28px 0 10px}
  .sub{color:var(--muted);font-size:13px;margin:0 0 22px}
  .banner{border-radius:14px;padding:16px 18px;margin-bottom:22px;
          border:1px solid transparent;font-weight:bold}
  .banner.ok{background:var(--ok-bg);color:var(--ok);border-color:var(--ok)}
  .banner.warn{background:var(--warn-bg);color:var(--warn);border-color:var(--warn)}
  .banner.fail{background:var(--danger-bg);color:var(--danger);border-color:var(--danger)}
  .card{background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden}
  .row{display:flex;gap:12px;align-items:flex-start;padding:10px 16px;
       border-bottom:1px solid var(--line)}
  .row:last-child{border-bottom:0}
  .dot{flex:0 0 auto;width:20px;height:20px;border-radius:50%;display:grid;
       place-items:center;font-size:12px;color:#fff;margin-top:5px}
  .dot.ok{background:var(--ok)} .dot.fail{background:var(--danger)}
  .t{font-weight:bold}
  .d{color:var(--soft);font-size:13px;word-break:break-word}
  .sqlbox{background:rgba(21,16,14,.05);border-radius:8px;padding:8px 10px;
          margin-top:6px;font:12px/1.7 Menlo,Consolas,monospace;direction:ltr;
          text-align:left;white-space:pre-wrap;word-break:break-all}
  .btn{display:inline-block;background:var(--accent);color:#fff;text-decoration:none;
       padding:12px 26px;border-radius:10px;font-weight:bold;font-size:15px;
       border:0;cursor:pointer}
  .btn:hover{background:#85462F}
  code{background:rgba(21,16,14,.06);padding:1px 5px;border-radius:5px;
       font-family:Menlo,Consolas,monospace;direction:ltr;display:inline-block}
  .note{margin-top:24px;padding:14px 16px;border-radius:12px;background:var(--warn-bg);
        color:var(--warn);font-size:13px;border:1px solid var(--warn)}
  ul{margin:8px 0;padding-inline-start:22px}
  .tbl{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}
  .chip{background:var(--ok-bg);color:var(--ok);border:1px solid var(--ok);
        border-radius:999px;padding:3px 12px;font-size:13px}
</style>
</head>
<body>
<div class="wrap">
  <h1>ساخت جدول‌های دیتابیس</h1>
  <p class="sub">این صفحه فایل schema.sql را مستقیم روی همان دیتابیسی اجرا می‌کند که در api/config.php نوشته‌اید.</p>

<?php if (!$configExists): ?>

  <div class="banner fail">فایل api/config.php وجود ندارد.</div>
  <div class="card"><div class="row"><div>
    اول از <code>api/config.sample.php</code> یک کپی به نام
    <code>api/config.php</code> بسازید و مشخصات دیتابیس را داخلش بنویسید،
    بعد این صفحه را دوباره باز کنید.
  </div></div></div>

<?php elseif (!$allowed): ?>

  <div class="banner warn">برای اجرا به کلید نیاز است.</div>
  <div class="card"><div class="row"><div>
    این صفحه می‌تواند ساختار دیتابیس را عوض کند، پس بدون کلید کار
    نمی‌کند. کلید از روی رمز دیتابیس شما ساخته شده است.
    <br><br>
    این آدرس را باز کنید:
    <div class="sqlbox"><?= $selfUrl ?>?key=<?= htmlspecialchars($guardKey, ENT_QUOTES, 'UTF-8') ?></div>
  </div></div></div>

<?php elseif ($doLock): ?>

  <?php if ($lockError !== null): ?>
    <div class="banner warn">قفل ضدِ رزرو هم‌زمان اضافه نشد.</div>
    <div class="card"><div class="row"><div>
      <div class="t">پیام دیتابیس</div>
      <div class="sqlbox"><?= htmlspecialchars($lockError, ENT_QUOTES, 'UTF-8') ?></div>
      <div class="d" style="margin-top:8px">
        این قابلیت اختیاری است. اگر نسخه‌ی MySQL هاست قدیمی باشد
        پشتیبانی نمی‌شود — <b>سایت بدون آن هم کاملاً کار می‌کند</b> و
        فقط یک لایه‌ی محافظت آخر را ندارد. نگرانش نباشید.
      </div>
    </div></div></div>
  <?php elseif ($lockAlready): ?>
    <div class="banner ok">قفل ضدِ رزرو هم‌زمان از قبل فعال بود.</div>
  <?php else: ?>
    <div class="banner ok">قفل ضدِ رزرو هم‌زمان با موفقیت اضافه شد.</div>
    <div class="card"><div class="row"><div class="d">
      از این به بعد اگر دو نفر دقیقاً هم‌زمان یک ساعت را رزرو کنند،
      نفر دوم پیام «این ساعت همین الان گرفته شد» می‌گیرد.
    </div></div></div>
  <?php endif; ?>

  <p><a class="btn" href="<?= $selfUrl ?>?key=<?= htmlspecialchars($guardKey, ENT_QUOTES, 'UTF-8') ?>">بازگشت</a></p>

<?php elseif (!$doRun): ?>

  <?php if ($dbState === 'missing'): ?>

    <?php if ($dbCreated): ?>
      <div class="banner ok">دیتابیس ساخته شد.</div>
      <div class="card"><div class="row"><div>
        <div class="t">دیتابیس «<?= htmlspecialchars($cfgDbName, ENT_QUOTES, 'UTF-8') ?>» با موفقیت ساخته شد</div>
        <div class="d">
          حالا جدول‌ها را بسازید. دکمه‌ی سبز «ساخت جدول‌ها» پایین همین صفحه است.
        </div>
      </div></div></div>
    <?php else: ?>
      <div class="banner warn">دیتابیس «<?= htmlspecialchars($cfgDbName, ENT_QUOTES, 'UTF-8') ?>» هنوز ساخته نشده است.</div>

      <div class="card"><div class="row"><div>
        <div class="t">خبر خوب: اسم و رمزش درست است</div>
        <div class="d">
          به سرور دیتابیس وصل شدیم و فقط گفت که دیتابیسی با این اسم
          وجود ندارد. یعنی <code>user</code> و <code>pass</code> در
          <code>api/config.php</code> درست است و فقط خودِ دیتابیس
          ساخته نشده.
        </div>
      </div></div>

      <h2>الف) امتحان کن خودش بسازد</h2>
      <p class="d">
        روی بعضی هاست‌ها این کار جواب می‌دهد. یک بار بزنید؛ اگر هاست
        اجازه نداد، هیچ چیزی خراب نمی‌شود و فقط پیام می‌دهد.
      </p>
      <?php if ($dbCreateErr !== ''): ?>
        <div class="card"><div class="row"><div>
          <div class="t">هاست اجازه نداد</div>
          <div class="sqlbox"><?= htmlspecialchars($dbCreateErr, ENT_QUOTES, 'UTF-8') ?></div>
          <div class="d" style="margin-top:8px">
            خیلی طبیعی است — روی اکثر هاست‌های اشتراکی، ساختن دیتابیس
            فقط از داخل cPanel ممکن است. پس بروید سراغ راه «ب» پایین؛
            سی ثانیه بیشتر وقت نمی‌گیرد.
          </div>
        </div></div></div>
      <?php endif; ?>
      <p>
        <a class="btn" href="<?= $selfUrl ?>?key=<?= htmlspecialchars($guardKey, ENT_QUOTES, 'UTF-8') ?>&amp;create-db=1">
          ساختن خودکار دیتابیس
        </a>
      </p>

      <h2>ب) ساختن دستی در cPanel — ۳ کلیک</h2>
      <div class="card">
        <div class="row"><div>
          <div class="t">۱. cPanel ← بخش Databases ← «MySQL Database Wizard»</div>
          <div class="d">
            اسم دیتابیس را بنویسید: <code><?= htmlspecialchars($dbNameRaw, ENT_QUOTES, 'UTF-8') ?></code>
            <br>
            (cPanel پیشوند حساب شما را خودش اضافه می‌کند. پس فقط بخش
            بدون پیشوند را بنویسید.)
          </div>
        </div></div>
        <div class="row"><div>
          <div class="t">۲. همان صفحه: یک کاربر بسازید و رمزش را کپی کنید</div>
          <div class="d">
            اسم کاربر و رمزش باید همان چیزی باشد که در
            <code>api/config.php</code> نوشته‌اید:
            <br>
            کاربر: <code><?= htmlspecialchars($dbUserRaw, ENT_QUOTES, 'UTF-8') ?></code>
            <br>
            (اگر رمز را یادتان نیست، در همان صفحه دکمه‌ی Change Password هست.)
          </div>
        </div></div>
        <div class="row"><div>
          <div class="t">۳. تیک «ALL PRIVILEGES» را بزنید ← Next</div>
          <div class="d">
            بدون این تیک، سایت به دیتابیس وصل نمی‌شود.
          </div>
        </div></div>
      </div>

      <p class="d">
        بعد از این سه کلیک، این صفحه را دوباره باز کنید (همین آدرس با کلید).
        این بار می‌نویسد «اتصال به دیتابیس برقرار است» و دکمه‌ی
        «ساخت جدول‌ها» ظاهر می‌شود.
      </p>
    <?php endif; ?>

  <?php elseif ($dbState === 'badcreds'): ?>

    <div class="banner fail">نام کاربری یا رمز دیتابیس غلط است.</div>
    <div class="card"><div class="row"><div>
      <div class="t">پیام سرور</div>
      <div class="sqlbox"><?= htmlspecialchars($dbStateMsg, ENT_QUOTES, 'UTF-8') ?></div>
      <div class="d" style="margin-top:8px">
        یعنی <code>user</code> یا <code>pass</code> در
        <code>api/config.php</code> با آن چه در cPanel ساخته‌اید
        یکی نیست.
        <br><br>
        <b>رمز را یادتان نیست؟</b> لازم نیست دیتابیس را از نو بسازید:
        cPanel ← MySQL Databases ← پایین صفحه بخش Current Users ←
        جلوی همان کاربر «Change Password» ← رمز تازه. بعد فقط همین رمز
        تازه را در <code>api/config.php</code> بنویسید.
      </div>
    </div></div></div>

  <?php elseif ($dbState === 'nopriv'): ?>

    <div class="banner fail">کاربر به این دیتابیس دسترسی ندارد.</div>
    <div class="card"><div class="row"><div>
      <div class="t">پیام سرور</div>
      <div class="sqlbox"><?= htmlspecialchars($dbStateMsg, ENT_QUOTES, 'UTF-8') ?></div>
      <div class="d" style="margin-top:8px">
        کاربر ساخته شده، ولی به این دیتابیس وصل نشده. در cPanel بروید به
        <b>MySQL Databases</b> ← پایین صفحه بخش «Add User To Database»
        ← همان کاربر و همان دیتابیس را انتخاب کنید ←
        <b>ALL PRIVILEGES</b> ← Make Changes.
      </div>
    </div></div></div>

  <?php elseif ($dbState === 'down'): ?>

    <div class="banner fail">سرور دیتابیس جواب نداد.</div>
    <div class="card"><div class="row"><div>
      <div class="t">پیام سرور</div>
      <div class="sqlbox"><?= htmlspecialchars($dbStateMsg, ENT_QUOTES, 'UTF-8') ?></div>
      <div class="d" style="margin-top:8px">
        معمولاً یعنی مقدار <code>host</code> در <code>api/config.php</code>
        درست نیست. روی اکثر هاست‌های cPanel باید همان
        <code>localhost</code> بماند. اگر باز هم نشد، با پشتیبانی هاست
        تماس بگیرید و بگویید سرور MySQL پاسخ نمی‌دهد.
      </div>
    </div></div></div>

  <?php else: ?>
    <div class="banner ok">اتصال به دیتابیس برقرار است.</div>
    <div class="card">
      <div class="row"><div>
        <div class="t">دیتابیس</div>
        <div class="d"><?= htmlspecialchars($currentDbName, ENT_QUOTES, 'UTF-8') ?></div>
      </div></div>
      <div class="row"><div>
        <div class="t">جدول‌های موجود</div>
        <?php if ($currentTables): ?>
          <div class="tbl">
            <?php foreach ($currentTables as $t): ?>
              <span class="chip"><?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="d">هیچ جدولی وجود ندارد — پس این اولین نصب است.</div>
        <?php endif; ?>
      </div></div>
    </div>

    <h2>اجرا</h2>
    <p class="d">
      اجرای دوباره خطرناک نیست: جدول‌های موجود دست‌نخورده می‌مانند و
      فقط اطلاعات خدمات به‌روز می‌شود. هیچ نوبت یا کاربری پاک نمی‌شود.
    </p>
    <p>
      <a class="btn" href="<?= $selfUrl ?>?key=<?= htmlspecialchars($guardKey, ENT_QUOTES, 'UTF-8') ?>&amp;run=1">
        ساخت جدول‌ها
      </a>
    </p>

    <h2>قفل ضدِ رزرو هم‌زمان (اختیاری)</h2>
    <p class="d">
      جلوی این را می‌گیرد که دو نفر در یک لحظه یک ساعت را رزرو کنند.
      بعد از ساخت جدول‌ها یک بار بزنید. اگر هاست پشتیبانی نکند فقط
      پیام می‌دهد و هیچ چیزی خراب نمی‌شود.
    </p>
    <p>
      <a class="btn" href="<?= $selfUrl ?>?key=<?= htmlspecialchars($guardKey, ENT_QUOTES, 'UTF-8') ?>&amp;lock=1">
        افزودن قفل
      </a>
    </p>
  <?php endif; ?>

<?php else: ?>

  <?php if ($fileError !== null): ?>
    <div class="banner fail"><?= htmlspecialchars($fileError, ENT_QUOTES, 'UTF-8') ?></div>
  <?php elseif ($dbError !== null): ?>
    <div class="banner fail">
      <?php if ($dbState === 'missing'): ?>
        دیتابیس «<?= htmlspecialchars($cfgDbName, ENT_QUOTES, 'UTF-8') ?>» پیدا نشد.
      <?php elseif ($dbState === 'badcreds'): ?>
        نام کاربری یا رمز دیتابیس غلط است.
      <?php elseif ($dbState === 'nopriv'): ?>
        کاربر به این دیتابیس دسترسی ندارد.
      <?php else: ?>
        اتصال به دیتابیس برقرار نشد.
      <?php endif; ?>
    </div>
    <div class="sqlbox"><?= htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8') ?></div>
    <div class="card" style="margin-top:14px"><div class="row"><div class="d">
      <?php if ($dbState === 'missing'): ?>
        اول دیتابیس را بسازید. یک راه سریع: همین آدرس را بدون
        <code>&amp;run=1</code> باز کنید — در همان صفحه دکمه‌ی
        «ساختن خودکار دیتابیس» و راهنمای سه‌کلیکی cPanel هست.
      <?php elseif ($dbState === 'badcreds'): ?>
        رمز را در cPanel ← MySQL Databases ← Current Users ←
        Change Password عوض کنید و همان رمز تازه را در
        <code>api/config.php</code> بنویسید.
      <?php elseif ($dbState === 'nopriv'): ?>
        cPanel ← MySQL Databases ← Add User To Database ← همان کاربر
        و همان دیتابیس ← ALL PRIVILEGES ← Make Changes.
      <?php else: ?>
        مقدار <code>host</code> در <code>api/config.php</code> را بررسی
        کنید (روی اکثر هاست‌ها <code>localhost</code> درست است).
      <?php endif; ?>
    </div></div></div>
  <?php else: ?>

    <?php if ($failCount === 0): ?>
      <div class="banner ok">
        همه‌ی <?= Jalali::fa($okCount) ?> دستور با موفقیت اجرا شد.
      </div>
    <?php else: ?>
      <div class="banner fail">
        <?= Jalali::fa($okCount) ?> دستور موفق، <?= Jalali::fa($failCount) ?> دستور ناموفق.
        جزئیات خطا پایین آمده است.
      </div>
    <?php endif; ?>

    <h2>جدول‌های دیتابیس بعد از اجرا</h2>
    <?php if ($tables): ?>
      <div class="card">
        <?php foreach ($tables as $t): ?>
          <div class="row">
            <div class="dot ok">✓</div>
            <div>
              <div class="t"><?= htmlspecialchars($t['t'], ENT_QUOTES, 'UTF-8') ?></div>
              <div class="d"><?= Jalali::fa($t['cols']) ?> ستون</div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="d">
        باید <b>۹ جدول</b> ببینید: users، sessions، otp_codes، services،
        service_variants، appointments، closed_days، blocked_slots، sms_log
      </p>
    <?php else: ?>
      <div class="banner fail">هیچ جدولی ساخته نشد.</div>
    <?php endif; ?>

    <?php if ($failCount > 0): ?>
      <h2>دستورهای ناموفق</h2>
      <div class="card">
        <?php foreach ($results as $r): ?>
          <?php if ($r['ok']) { continue; } ?>
          <div class="row">
            <div class="dot fail">×</div>
            <div style="flex:1">
              <div class="t"><?= htmlspecialchars($r['label'], ENT_QUOTES, 'UTF-8') ?></div>
              <div class="d"><?= htmlspecialchars($r['msg'], ENT_QUOTES, 'UTF-8') ?></div>
              <?php if (!empty($r['sql'])): ?>
                <div class="sqlbox"><?= htmlspecialchars($r['sql'], ENT_QUOTES, 'UTF-8') ?></div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="d">
        معنی چند خطای رایج:
      </p>
      <ul class="d">
        <li><code>#1044</code> یا <code>#1142</code> — کاربر دیتابیس اجازه‌ی ساخت جدول ندارد.
            در cPanel به او <b>ALL PRIVILEGES</b> بدهید.</li>
        <li><code>#1273</code> — نسخه‌ی دیتابیس این نوع حروف را نمی‌شناسد. به من بگویید تا نسخه‌ی سازگار بسازم.</li>
        <li><code>#1071</code> — طول کلید زیاد است. نسخه‌ی دیتابیس قدیمی است؛ به من بگویید.</li>
      </ul>
    <?php endif; ?>

    <h2>قدم بعد</h2>
    <p class="d">
      اگر ۹ جدول ساخته شده، بروید سراغ
      <code>api/selftest.php</code> تا بقیه‌ی نصب هم بررسی شود.
    </p>

  <?php endif; ?>

<?php endif; ?>

  <div class="note">
    بعد از تمام شدن کار، این فایل را از هاست پاک کنید:
    <code>api/install.php</code><br>
    این صفحه می‌تواند ساختار دیتابیس را تغییر دهد و نباید در دسترس عموم بماند.
  </div>
</div>
</body>
</html>
