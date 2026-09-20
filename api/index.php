<?php
/* ==========================================================================
   index.php — مسیریاب API

   همه‌ی درخواست‌های /api/… از این‌جا رد می‌شوند (با کمک .htaccess).

   قرارداد این فایل با فرانت در assets/js/core/api.js نوشته شده و
   شکل دقیق JSON در assets/js/data/backend-bridge.js. اگر این‌جا نام
   کلیدی را عوض کردید، آن‌جا هم باید عوض شود.
   ========================================================================== */

define('ZZ_APP', true);

/* خطاها هرگز نباید داخل بدنه‌ی JSON چاپ شوند؛ فقط در لاگ هاست */
ini_set('display_errors', '0');
error_reporting(E_ALL);

require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/jalali.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/sms.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/catalog.php';
require __DIR__ . '/lib/booking.php';
require __DIR__ . '/lib/admin.php';

Config::load();

/* هر خطای پیش‌بینی‌نشده هم باید JSON برگردد، نه صفحه‌ی سفید —
   وگرنه فرانت پیام «خطا در ارتباط با سرور» می‌دهد و ما هیچ سرنخی
   نداریم. */
set_exception_handler(function (Throwable $e) {
    error_log('[zz] خطای مدیریت‌نشده: ' . $e->getMessage()
              . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Http::fail(
        500,
        Config::get('app.debug')
            ? ('خطای سرور: ' . $e->getMessage())
            : 'خطای غیرمنتظره در سرور. لطفاً دوباره تلاش کنید.'
    );
});

/* ---------------- استخراج مسیر ---------------- */

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$path = rawurldecode($path);

/* هر چیزی قبل از /api/ را دور بریز — این‌طور چه سایت در ریشه باشد
   چه در زیرپوشه، مسیرها یکسان می‌مانند. */
$pos = strpos($path, '/api');
if ($pos !== false) {
    $path = substr($path, $pos + 4);
}
$path   = '/' . trim($path, '/');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

/* مرورگرها قبل از PATCH/DELETE گاهی preflight می‌فرستند. چون فرانت
   و API روی یک دامنه‌اند این معمولاً پیش نمی‌آید، ولی اگر آمد باید
   جواب درست بگیرد. */
if ($method === 'OPTIONS') {
    header('Allow: GET, POST, PATCH, DELETE, OPTIONS');
    http_response_code(204);
    exit;
}

/* ---------------- کمکی مسیریابی ---------------- */

/**
 * تطبیق مسیر با الگو. * یعنی یک بخش دلخواه.
 * مقدارهای * در $args ریخته می‌شوند.
 */
function match_path(string $pattern, string $path, ?array &$args = null): bool
{
    $p = explode('/', trim($pattern, '/'));
    $q = explode('/', trim($path, '/'));
    if (count($p) !== count($q)) {
        return false;
    }
    $args = [];
    foreach ($p as $i => $seg) {
        if ($seg === '*') {
            $args[] = $q[$i];
        } elseif ($seg !== $q[$i]) {
            return false;
        }
    }
    return true;
}

/** فقط این متد مجاز است، وگرنه ۴۰۵ */
function only(string ...$allowed): void
{
    global $method;
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        Http::fail(405, 'این عملیات روی این آدرس مجاز نیست.');
    }
}

/**
 * محافظت CSRF برای درخواست‌های تغییردهنده.
 *
 * کوکی نشست SameSite=Lax است، پس مرورگر آن را روی POST از دامنه‌ی
 * دیگر نمی‌فرستد. این بررسی لایه‌ی دوم است برای مرورگرهای قدیمی که
 * SameSite را نمی‌فهمند.
 */
function check_origin(): void
{
    global $method;
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return;   // برنامه‌های غیرمرورگری Origin نمی‌فرستند
    }

    $host = parse_url($origin, PHP_URL_HOST);
    if ($host === null) {
        Http::fail(403, 'منبع درخواست معتبر نیست.');
    }

    $self = $_SERVER['HTTP_HOST'] ?? '';
    $self = explode(':', $self)[0];

    /* www و بدون www یک دامنه حساب می‌شوند */
    $strip = function (string $h): string {
        return strncmp($h, 'www.', 4) === 0 ? substr($h, 4) : $h;
    };

    if ($strip(strtolower($host)) !== $strip(strtolower($self))) {
        Http::fail(403, 'منبع درخواست معتبر نیست.');
    }
}

check_origin();

/* ======================================================================
   مسیرها
   ====================================================================== */

/* ---------------- سلامت ----------------
   backend-bridge.js اول این را صدا می‌زند؛ اگر جواب نداد، کل سایت
   روی حالت localStorage می‌ماند. پس این باید سبک باشد و به هیچ
   چیزی جز دیتابیس وابسته نباشد. */
if ($path === '/health') {
    only('GET');
    Http::json([
        'ok'      => true,
        'time'    => date('c'),
        'version' => '1.0',
    ]);
}

/* ================= احراز هویت ================= */

if ($path === '/auth/request-code') {
    only('POST');
    Http::json(Auth::requestCode(Http::str('phone', 20)));
}

if ($path === '/auth/verify') {
    only('POST');
    $r = Auth::verifyCode(Http::str('phone', 20), Http::str('code', 10));
    Http::json([
        'user'        => Auth::publicUser($r['user']),
        'is_new_user' => $r['is_new_user'],
    ]);
}

if ($path === '/auth/me') {
    if ($method === 'GET') {
        Http::json(Auth::publicUser(Auth::needUser()));
    }

    only('GET', 'PATCH');
    $u = Auth::needUser();
    $b = Http::body();

    $sets   = [];
    $params = [];

    if (array_key_exists('name', $b)) {
        $name = Http::str('name', 80);
        /* نام خالی مجاز است (کاربر می‌تواند پاکش کند) ولی نام
           یک‌حرفی معمولاً اشتباه تایپی است. */
        if ($name !== '' && mb_strlen($name, 'UTF-8') < 2) {
            Http::fail(422, 'نام باید حداقل دو حرف باشد.', 'name');
        }
        $sets[]   = 'name = ?';
        $params[] = $name;
    }

    if (!empty($b['clear_birth'])) {
        $sets[] = 'birth_y = NULL, birth_m = NULL, birth_d = NULL';
    } elseif (isset($b['birth']) && is_array($b['birth'])) {
        $y = (int) ($b['birth']['y'] ?? 0);
        $m = (int) ($b['birth']['m'] ?? 0);
        $d = (int) ($b['birth']['d'] ?? 0);
        $err = Jalali::validBirth($y, $m, $d);
        if ($err !== null) {
            Http::fail(422, $err, 'birth');
        }
        $sets[]   = 'birth_y = ?, birth_m = ?, birth_d = ?';
        $params[] = $y;
        $params[] = $m;
        $params[] = $d;
    }

    if ($sets) {
        $params[] = $u['id'];
        Db::run('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    Http::json(Auth::publicUser(Db::one('SELECT * FROM users WHERE id = ?', [$u['id']])));
}

if ($path === '/auth/logout') {
    only('POST');
    Auth::endSession();
    Http::json(['ok' => true]);
}

/* ================= خدمات ================= */

if ($path === '/services') {
    only('GET');
    Http::json(Catalog::all());
}

if (match_path('/services/*', $path, $args)) {
    only('GET');
    $s = Catalog::bySlug($args[0]);
    if (!$s) {
        Http::fail(404, 'این خدمت پیدا نشد.');
    }
    Http::json($s);
}

/* ================= تقویم ================= */

if ($path === '/days') {
    only('GET');
    Http::json(Booking::days((int) Http::query('count', '0')));
}

if ($path === '/slots') {
    only('GET');
    Http::json(Booking::slots(Http::query('date')));
}

/* ================= نوبت‌ها ================= */

if ($path === '/appointments/me') {
    only('GET');
    Http::json(Booking::forUser((string) Auth::needUser()['id']));
}

if ($path === '/appointments') {
    only('POST');
    Http::json(Booking::create(Auth::needUser(), Http::body()), 201);
}

if (match_path('/appointments/*', $path, $args)) {
    only('DELETE');
    Http::json(Booking::cancel(Auth::needUser(), $args[0]));
}

/* ================= مدیریت ================= */

if (strncmp($path, '/admin/', 7) === 0) {
    Auth::requireAdmin();

    if ($path === '/admin/stats') {
        only('GET');
        Http::json(Admin::stats());
    }

    if ($path === '/admin/appointments') {
        only('GET');
        Http::json(Admin::appointments([
            'status' => Http::query('status', 'all'),
            'q'      => Http::query('q'),
            'date'   => Http::query('date'),
        ]));
    }

    if (match_path('/admin/appointments/*/approve', $path, $args)) {
        only('POST');
        Http::json(Admin::approve($args[0]));
    }

    if (match_path('/admin/appointments/*/reject', $path, $args)) {
        only('POST');
        Http::json(Admin::reject($args[0], Http::str('reason', 200)));
    }

    if (match_path('/admin/appointments/*/revert', $path, $args)) {
        only('POST');
        Http::json(Admin::revertToPending($args[0]));
    }

    if (match_path('/admin/appointments/*/done', $path, $args)) {
        only('POST');
        Http::json(Admin::markDone($args[0]));
    }

    if (match_path('/admin/appointments/*/no-show', $path, $args)) {
        only('POST');
        Http::json(Admin::markNoShow($args[0]));
    }

    if (match_path('/admin/appointments/*/deposit', $path, $args)) {
        only('POST', 'DELETE');
        Http::json(
            $method === 'DELETE'
                ? Admin::clearDeposit($args[0])
                : Admin::setDeposit($args[0], Http::body())
        );
    }

    if (match_path('/admin/appointments/*', $path, $args)) {
        only('DELETE');
        Http::json(Admin::cancel($args[0]));
    }

    if ($path === '/admin/day-slots') {
        only('GET');
        Http::json(Admin::daySlots(Http::query('date')));
    }

    if ($path === '/admin/block-day') {
        only('POST');
        Http::json(Admin::toggleDay(Http::str('date', 10), Http::str('reason', 120)));
    }

    if ($path === '/admin/block-slot') {
        only('POST');
        Http::json(Admin::toggleSlot(
            Http::str('date', 10), Http::str('time', 5), Http::str('reason', 120)
        ));
    }

    if ($path === '/admin/users') {
        only('GET');
        Http::json(Admin::users(['q' => Http::query('q')]));
    }

    if (match_path('/admin/users/*', $path, $args)) {
        only('GET');
        Http::json(Admin::userDetail($args[0]));
    }

    if ($path === '/admin/birthdays') {
        only('GET');
        Http::json(Admin::birthdays());
    }

    if ($path === '/admin/services') {
        only('GET');
        Http::json(Catalog::all(true));
    }

    if (match_path('/admin/services/*', $path, $args)) {
        only('PATCH');
        Http::json(Catalog::update($args[0], Http::body()));
    }
}

/* ---------------- مسیر ناشناخته ---------------- */
Http::fail(404, 'این آدرس روی سرور وجود ندارد.');
