<?php
/* ==========================================================================
   db.php — اتصال به دیتابیس

   یک اتصال PDO که بین همه‌ی درخواست‌ها مشترک است. حالت خطا روی
   استثنا تنظیم شده تا هیچ خطای دیتابیسی بی‌سر‌و‌صدا رد نشود.
   ========================================================================== */

if (!defined('ZZ_APP')) {
    http_response_code(403);
    exit('دسترسی مستقیم مجاز نیست.');
}

final class Db
{
    /** @var PDO|null */
    private static $pdo = null;

    /**
     * صفحه‌های تشخیصی (install.php و selftest.php) خروجی HTML دارند.
     * برای آن‌ها Http::fail بد است، چون وسط صفحه JSON چاپ می‌کند و
     * exit می‌زند. با روشن کردن این پرچم، خطای اتصال به‌جای پاسخ
     * JSON یک استثنا می‌شود تا خودشان مدیریتش کنند.
     */
    private static $throwOnConnectError = false;

    public static function throwOnConnectError(bool $on = true): void
    {
        self::$throwOnConnectError = $on;
    }

    public static function conn(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $cfg = Config::get('db');
        $dsn = 'mysql:host=' . $cfg['host']
             . ';dbname=' . $cfg['name']
             . ';charset=' . ($cfg['charset'] ?? 'utf8mb4');

        try {
            self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                /* آماده‌سازی واقعی سمت سرور — جلوی تزریق را محکم‌تر می‌گیرد */
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            /* پیام واقعی فقط در لاگ؛ کاربر نباید نام دیتابیس را ببیند */
            error_log('[zz] اتصال به دیتابیس ناموفق: ' . $e->getMessage());

            if (self::$throwOnConnectError) {
                throw $e;
            }
            Http::fail(503, 'ارتباط با پایگاه داده برقرار نشد. لطفاً کمی بعد دوباره تلاش کنید.');
        }

        /* منطقه‌ی زمانی نشست دیتابیس با PHP یکی شود تا NOW() و
           date() هم‌خوان بمانند. */
        try {
            $offset = (new DateTime('now', new DateTimeZone(date_default_timezone_get())))
                ->format('P');
            self::$pdo->exec("SET time_zone = '" . $offset . "'");
        } catch (Exception $e) {
            /* بعضی هاست‌ها اجازه‌ی SET time_zone نمی‌دهند — مهم نیست،
               چون همه‌ی تاریخ‌ها را خودمان از PHP می‌فرستیم. */
        }

        return self::$pdo;
    }

    /** اجرای پرس‌وجو با پارامتر */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $st = self::conn()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    /** یک ردیف */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** همه‌ی ردیف‌ها */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** یک مقدار تکی */
    public static function val(string $sql, array $params = [])
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** شناسه‌ی یکتا به سبک فرانت: apt_9f3c… */
    public static function uid(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(8));
    }

    /** آیا جدول وجود دارد؟ — برای selftest */
    public static function tableExists(string $name): bool
    {
        $n = Db::val(
            'SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = ?',
            [$name]
        );
        return (int) $n > 0;
    }
}
