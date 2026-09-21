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

    /** میزبانی که واقعاً با آن وصل شدیم (ممکن است localhost باشد). */
    private static $hostUsed = '';

    /** آیا اتصال با localhost جایگزین شد؟ (host اشتباه در config.php) */
    private static $hostFallback = false;

    public static function throwOnConnectError(bool $on = true): void
    {
        self::$throwOnConnectError = $on;
    }

    /** میزبانی که اتصال با آن برقرار شد. */
    public static function hostUsed(): string
    {
        return self::$hostUsed;
    }

    /** اگر true باشد، db.host در config.php اشتباه است و باید localhost شود. */
    public static function hostWasFallback(): bool
    {
        return self::$hostFallback;
    }

    public static function conn(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $cfg  = Config::get('db');
        $host = (string) ($cfg['host'] ?? 'localhost');

        /* ترتیب میزبان‌ها: اول همان مقداری که در config.php نوشته شده،
           و اگر خطا از نوعِ «میزبان» بود، یک بار هم localhost. روی
           cPanel تقریباً همه‌ی حساب‌ها روی localhost کار می‌کنند، پس
           این کار سایت را بدون دست‌زدن به config.php برمی‌گرداند. */
        $hosts = [$host];
        if (!self::isLocalhost($host)) {
            $hosts[] = 'localhost';
        }

        $err = null;
        $connected = false;

        foreach ($hosts as $i => $h) {
            $isLast = ($i === count($hosts) - 1);

            for ($try = 1; $try <= 2; $try++) {
                $err = self::attempt($cfg, $h, $try);

                if ($err === null) {           /* وصل شد */
                    $connected = true;
                    break 2;
                }

                /* شلوغی موقت / پر بودن سقف اتصال: یک بار دیگر، با تأخیر
                   تصادفی تا چند درخواست هم‌زمان با هم تلاش نکنند. */
                if ($try === 1 && self::connErrorIsTransient($err->getMessage())) {
                    usleep(150000 + random_int(0, 300000));
                    continue;
                }
                break;
            }

            /* میزبان بعدی فقط وقتی امتحان می‌شود که خطا مربوط به خودِ
               میزبان باشد؛ خطای رمز یا نام دیتابیس با عوض کردن host
               حل نمی‌شود و فقط سایت را کند می‌کند. */
            if ($isLast || !self::connectionLevelFailure($err->getMessage())) {
                break;
            }
        }

        if ($connected && self::$hostUsed !== $host) {
            self::$hostFallback = true;
            error_log(
                '[zz] دیتابیس با میزبان «' . $host . '» وصل نشد، ولی با localhost وصل شد. '
                . 'برای اینکه هر بار یک تلاش اضافه انجام نشود، db.host را در api/config.php '
                . 'به localhost تغییر دهید.'
            );
            $err = null;
        }

        if ($err !== null) {
            if (self::$throwOnConnectError) {
                throw $err;
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


    /**
     * یک تلاش برای اتصال با یک میزبان مشخص.
     * روی موفقیت self::$pdo را پر می‌کند و null برمی‌گرداند؛
     * روی خطا استثنا را (بدون پرتاب) برمی‌گرداند تا بالادست تصمیم بگیرد.
     */
    private static function attempt(array $cfg, string $host, int $try): ?PDOException
    {
        $dsn = 'mysql:host=' . $host
             . ';dbname=' . ($cfg['name'] ?? '')
             . ';charset=' . ($cfg['charset'] ?? 'utf8mb4');

        try {
            self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                /* آماده‌سازی واقعی سمت سرور — جلوی تزریق را محکم‌تر می‌گیرد */
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            self::$hostUsed = $host;
            return null;
        } catch (PDOException $e) {
            /* پیام واقعی فقط در لاگ؛ کاربر نباید نام دیتابیس را ببیند */
            error_log(
                '[zz] اتصال به دیتابیس ناموفق (میزبان ' . $host . '، تلاش ' . $try . '): '
                . $e->getMessage()
            );
            return $e;
        }
    }

    /** آیا این میزبان خودش localhost است؟ */
    private static function isLocalhost(string $host): bool
    {
        $host = strtolower(trim($host));
        return in_array($host, ['', 'localhost', '127.0.0.1', '::1', 'localhost:3306'], true);
    }

    /**
     * آیا خطا مربوط به «راه‌رسیدن به سرور دیتابیس» است (و نه رمز و
     * نام دیتابیس)؟ فقط این نوع خطا با امتحان‌کردن میزبان دیگر ممکن
     * است حل شود: میزبان اشتباه، اجازه‌نداشتن این میزبان، یا کاربری
     * که فقط از localhost معتبر است.
     */
    private static function connectionLevelFailure(string $msg): bool
    {
        foreach ([
            '[2002]', '[2003]', '[2005]', '[1130]',
            'Connection refused',
            "Can't connect",
            'No such file or directory',
            'Unknown MySQL server host',
            'getaddrinfo',
            'not allowed to connect',
            'gone away',
        ] as $needle) {
            if (stripos($msg, $needle) !== false) {
                return true;
            }
        }

        /* «Access denied … (using password: YES/NO)» می‌تواند یعنی این
           کاربر از این میزبان مجاز نیست و با localhost درست می‌شود؛
           ولی «… to database …» خطای ۱۰۴۴ است: کاربر به آن دیتابیس
           وصل نشده و عوض کردن host کمکی نمی‌کند. */
        if (stripos($msg, 'Access denied') !== false && stripos($msg, 'to database') === false) {
            return true;
        }

        return false;
    }

    /**
     * خطاهایی که «گذرا» هستند و ارزش یک تلاش دوباره را دارند:
     * پر بودن سقف اتصال، شلوغی موقت سرور. خطای رمز/نام دیتابیس
     * گذرا نیست و تلاش دوباره فقط وقت تلف می‌کند.
     */
    private static function connErrorIsTransient(string $msg): bool
    {
        foreach ([
            'Too many connections',
            'max_user_connections',
            'max_connections_per_hour',
            'max_connections',
            'Connection refused',
            "Can't connect",
            'gone away',
            'Deadlock',
            'Lock wait timeout',
            'server has gone away',
            'Resource temporarily unavailable',
        ] as $needle) {
            if (stripos($msg, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * ترجمه‌ی خطای اتصال MySQL به یک راهنمای کوتاه فارسی.
     * selftest.php و هر ابزار عیب‌یابی دیگری از همین استفاده می‌کنند
     * تا هر خطا یک جور توضیح داده شود.
     */
    public static function diagnoseConnectError(string $msg): string
    {
        /* ترتیب مهم است: پیام‌های خاص باید قبل از پیام‌های کلی بررسی شوند.
           مثلاً خطای ۱۰۴۴ و ۱۱۴۲ هر دو «Access denied … denied to user»
           دارند و اگر اول ۱۰۴۵ بررسی شود، به اشتباه «رمز غلط» گزارش می‌شود. */
        $rules = [
            /* [کلیدواژه‌ها، راهنما] */
            [
                ['Too many connections', 'max_user_connections', 'max_connections'],
                'سقف تعداد اتصال‌های دیتابیس پر شده است؛ سرور شلوغ است یا سایت '
                . 'هم‌زمان چند درخواست می‌فرستد. چند دقیقه بعد دوباره امتحان کنید و '
                . 'اگر تکرار شد از هاستینگ بخواهید سقف اتصال را بالا ببرد.',
            ],
            [
                ['Connection refused', "Can't connect", 'No such file'],
                'سرور دیتابیس جواب نمی‌دهد یا آدرس host اشتباه است؛ اگر تکرار شد '
                . 'به هاستینگ بگویید MySQL پاسخ نمی‌دهد.',
            ],
            [
                ['command denied'],                       /* ۱۱۴۲ */
                'کاربر دیتابیس برای این جدول دسترسی ندارد (خطای ۱۱۴۲) — مجوزهای '
                . 'کاربر در cPanel باید ALL PRIVILEGES باشد.',
            ],
            [
                ['is not allowed to connect'],            /* ۱۱۳۰ */
                'این میزبان اجازه‌ی اتصال به دیتابیس را ندارد (خطای ۱۱۳۰). در '
                . 'cPanel → MySQL Databases کاربر را به دیتابیس وصل کنید و اگر host '
                . 'را روی نام سرور گذاشته‌اید، آن را localhost کنید.',
            ],
            [
                ['to database'],                          /* ۱۰۴۴ */
                'کاربر دیتابیس به این دیتابیس وصل نیست یا اجازه‌ی دسترسی ندارد '
                . '(خطای ۱۰۴۴). در cPanel → MySQL Databases کاربر را با ALL PRIVILEGES '
                . 'به دیتابیس وصل کنید.',
            ],
            [
                ['Access denied'],                        /* ۱۰۴۵ */
                'نام کاربری یا رمز دیتابیس غلط است (خطای ۱۰۴۵).',
            ],
            [
                ['Unknown database'],                     /* ۱۰۴۹ */
                'دیتابیسی با این نام پیدا نشد (خطای ۱۰۴۹).',
            ],
            [
                ['gone away'],
                'اتصال وسط کار قطع شد — معمولاً شلوغی یا timeout سرور دیتابیس.',
            ],
        ];

        foreach ($rules as [$needles, $hint]) {
            foreach ($needles as $needle) {
                if (stripos($msg, $needle) !== false) {
                    return $hint;
                }
            }
        }

        return 'نام دیتابیس، کاربر و رمز را با cPanel مقایسه کنید.';
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
