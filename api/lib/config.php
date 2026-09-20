<?php
/* ==========================================================================
   config.php (loader) — خواندن تنظیمات سایت

   فایل واقعی تنظیمات api/config.php است که روی هاست ساخته می‌شود و
   داخل گیت نیست. این‌جا فقط آن را می‌خوانیم و مقدارهای جاافتاده را
   با پیش‌فرض پر می‌کنیم، تا اگر روزی کلیدی به نمونه اضافه شد،
   سایت‌های قدیمی خطا ندهند.
   ========================================================================== */

if (!defined('ZZ_APP')) {
    http_response_code(403);
    exit('دسترسی مستقیم مجاز نیست.');
}

final class Config
{
    /** @var array|null */
    private static $data = null;

    private const DEFAULTS = [
        'db' => [
            'host'    => 'localhost',
            'name'    => '',
            'user'    => '',
            'pass'    => '',
            'charset' => 'utf8mb4',
        ],
        'sms' => [
            'enabled'   => false,
            'provider'  => 'niazpardaz',
            'username'  => '',
            'password'  => '',
            'api_key'   => '',
            'auth_mode' => 'username_password',
            'from'      => '',
            'base_url'  => 'http://in.payamak-service.ir/api/v2/RestWebApi/',
            'timeout'   => 15,
            'templates' => [
                'otp'      => 'کد تایید شما: {code}',
                'approved'    => null,
                'rejected'    => null,
                'reminder'    => null,
                'received'    => null,
                'admin_alert' => null,
            ],
        ],
        'otp' => [
            'length'       => 4,
            'ttl_seconds'  => 120,
            'resend_after' => 60,
            'max_attempts' => 5,
            'per_hour'     => 5,
            'per_ip_hour'  => 20,
        ],
        'booking' => [
            'days_ahead'          => 14,
            'cancel_window_hours' => 24,
            'max_open_requests'   => 2,
            'closed_weekday'      => 5,
            'times' => ['10:00', '11:30', '13:00', '14:30', '16:00', '17:30', '19:00'],
        ],
        'admin_phones' => [],
        'app' => [
            'site_url' => '',
            'timezone' => 'Asia/Tehran',
            'debug'    => false,
        ],
    ];

    public static function load(): void
    {
        if (self::$data !== null) {
            return;
        }

        $file = __DIR__ . '/../config.php';
        if (!is_file($file)) {
            Http::fail(
                503,
                'سایت هنوز پیکربندی نشده است. فایل api/config.sample.php را به api/config.php کپی کنید.'
            );
        }

        $user = require $file;
        if (!is_array($user)) {
            Http::fail(503, 'فایل تنظیمات معتبر نیست.');
        }

        self::$data = self::merge(self::DEFAULTS, $user);

        /* منطقه‌ی زمانی — همه‌ی تاریخ‌ها بر مبنای تهران محاسبه می‌شوند */
        $tz = self::$data['app']['timezone'] ?: 'Asia/Tehran';
        if (in_array($tz, timezone_identifiers_list(), true)) {
            date_default_timezone_set($tz);
        }
    }

    /** ادغام بازگشتی — کلیدهای کاربر روی پیش‌فرض می‌نشینند */
    private static function merge(array $base, array $over): array
    {
        foreach ($over as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k])
                && self::isAssoc($base[$k])) {
                $base[$k] = self::merge($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    private static function isAssoc(array $a): bool
    {
        return $a !== [] && array_keys($a) !== range(0, count($a) - 1);
    }

    /**
     * خواندن تنظیم با مسیر نقطه‌ای: Config::get('sms.from')
     */
    public static function get(string $path, $default = null)
    {
        self::load();
        $node = self::$data;
        foreach (explode('.', $path) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    /** آیا این شماره مدیر است؟ */
    public static function isAdminPhone(string $phone): bool
    {
        $list = self::get('admin_phones', []);
        if (!is_array($list)) {
            return false;
        }
        foreach ($list as $p) {
            if (Http::normalizePhone((string) $p) === $phone) {
                return true;
            }
        }
        return false;
    }
}
