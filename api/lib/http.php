<?php
/* ==========================================================================
   http.php — ورودی و خروجی JSON

   قرارداد خطا با فرانت (assets/js/core/api.js):
     • وضعیت غیر 2xx
     • بدنه‌ی { "message": "...", "field": "..." }
   فرانت مستقیم message را به کاربر نشان می‌دهد، پس همه‌ی پیام‌ها
   باید فارسیِ قابل‌فهم باشند — نه کد خطای فنی.
   ========================================================================== */

if (!defined('ZZ_APP')) {
    http_response_code(403);
    exit('دسترسی مستقیم مجاز نیست.');
}

final class Http
{
    /** @var array|null بدنه‌ی JSON خوانده‌شده */
    private static $body = null;

    /** پاسخ موفق */
    public static function json($data, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            /* پاسخ‌های API هرگز نباید کش شوند — وگرنه کاربر وضعیت
               قدیمی نوبتش را می‌بیند. */
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    /** پاسخ خطا — پیام باید فارسی و قابل نمایش به کاربر باشد */
    public static function fail(int $status, string $message, ?string $field = null): void
    {
        $out = ['message' => $message];
        if ($field !== null) {
            $out['field'] = $field;
        }
        self::json($out, $status);
    }

    /** بدنه‌ی JSON درخواست */
    public static function body(): array
    {
        if (self::$body !== null) {
            return self::$body;
        }
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return self::$body = [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            Http::fail(400, 'داده‌ی ارسالی معتبر نیست.');
        }
        return self::$body = $data;
    }

    /** یک فیلد از بدنه، به‌صورت رشته‌ی تمیز */
    public static function str(string $key, int $max = 500): string
    {
        $b = self::body();
        $v = $b[$key] ?? '';
        if (!is_scalar($v)) {
            return '';
        }
        /* حذف کاراکترهای کنترلی — جلوی خراب شدن پیامک و HTML را می‌گیرد */
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $v);
        return mb_substr(trim($v), 0, $max, 'UTF-8');
    }

    /** یک فیلد عددی از بدنه */
    public static function int(string $key, int $default = 0): int
    {
        $b = self::body();
        if (!isset($b[$key]) || !is_scalar($b[$key])) {
            return $default;
        }
        $v = preg_replace('/\D/', '', Jalali::en((string) $b[$key]));
        return $v === '' ? $default : (int) $v;
    }

    /** یک فیلد بولی از بدنه */
    public static function bool(string $key): bool
    {
        $b = self::body();
        return !empty($b[$key]);
    }

    /** پارامتر query به‌صورت رشته */
    public static function query(string $key, string $default = '', int $max = 200): string
    {
        $v = $_GET[$key] ?? $default;
        if (!is_scalar($v)) {
            return $default;
        }
        $v = preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $v);
        return mb_substr(trim($v), 0, $max, 'UTF-8');
    }

    /** آی‌پی کاربر — با در نظر گرفتن پراکسی‌های رایج (کلودفلر و…) */
    public static function ip(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', (string) $_SERVER[$k])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }

    /** آیا درخواست روی HTTPS است؟ — برای تعیین Secure روی کوکی */
    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
            return true;
        }
        return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }

    /** نرمال‌سازی شماره موبایل — دقیقاً معادل utils.normalizePhone */
    public static function normalizePhone(string $value): string
    {
        $v = preg_replace('/[\s\-()]/u', '', Jalali::en($value));

        if (strncmp($v, '+98', 3) === 0) {
            $v = '0' . substr($v, 3);
        } elseif (strncmp($v, '0098', 4) === 0) {
            $v = '0' . substr($v, 4);
        } elseif (strncmp($v, '98', 2) === 0 && strlen($v) === 12) {
            $v = '0' . substr($v, 2);
        } elseif (strlen($v) === 10 && $v[0] === '9') {
            $v = '0' . $v;
        }

        return $v;
    }

    public static function isValidPhone(string $value): bool
    {
        return (bool) preg_match('/^09\d{9}$/', self::normalizePhone($value));
    }
}
