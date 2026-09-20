<?php
/* ==========================================================================
   auth.php — ورود با کد پیامکی و نشست کاربر

   چرا نشست دستی و نه session_start() خودِ PHP؟
     • روی هاست اشتراکی، فایل‌های نشست PHP گاهی زودتر از موعد پاک
       می‌شوند و کاربر بی‌دلیل بیرون می‌افتد.
     • با جدول دیتابیس می‌شود نشست‌ها را دید، باطل کرد و عمرشان را
       دقیق کنترل کرد.

   کد تأیید هرگز خام ذخیره نمی‌شود؛ فقط هشِ آن. اگر کسی به دیتابیس
   دسترسی پیدا کند، نمی‌تواند کد در جریان کسی را بخواند.
   ========================================================================== */

if (!defined('ZZ_APP')) {
    http_response_code(403);
    exit('دسترسی مستقیم مجاز نیست.');
}

final class Auth
{
    private const COOKIE = 'zz_session';
    private const SESSION_DAYS = 60;

    /** @var array|null کاربر فعلی — false یعنی بررسی شده و کسی نبود */
    private static $user = null;
    private static $checked = false;

    /* ================= نشست ================= */

    /** کاربر فعلی یا null */
    public static function user(): ?array
    {
        if (self::$checked) {
            return self::$user;
        }
        self::$checked = true;
        self::$user = null;

        $token = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($token) || strlen($token) !== 64
            || !ctype_xdigit($token)) {
            return null;
        }

        $row = Db::one(
            'SELECT u.* FROM sessions s
               JOIN users u ON u.id = s.user_id
              WHERE s.token = ? AND s.expires_at > NOW()
              LIMIT 1',
            [hash('sha256', $token)]
        );
        if (!$row) {
            return null;
        }

        /* آخرین بازدید را حداکثر روزی یک بار به‌روز می‌کنیم تا هر
           درخواست یک نوشتن اضافه نداشته باشد. */
        Db::run(
            'UPDATE sessions SET last_seen = NOW()
              WHERE token = ? AND (last_seen IS NULL OR last_seen < DATE_SUB(NOW(), INTERVAL 1 DAY))',
            [hash('sha256', $token)]
        );

        return self::$user = $row;
    }

    /** کاربر فعلی یا خطای ۴۰۱ */
    public static function needUser(): array
    {
        $u = self::user();
        if (!$u) {
            Http::fail(401, 'برای ادامه باید وارد حساب کاربری شوید.');
        }
        return $u;
    }

    /** مدیر یا خطا */
    public static function requireAdmin(): array
    {
        $u = self::needUser();
        if (($u['role'] ?? 'user') !== 'admin') {
            Http::fail(403, 'دسترسی به این بخش فقط برای مدیر مجاز است.');
        }
        return $u;
    }

    /** ساخت نشست و ست کردن کوکی */
    public static function startSession(string $userId): void
    {
        $token = bin2hex(random_bytes(32));

        Db::run(
            'INSERT INTO sessions (token, user_id, created_at, expires_at, ip, user_agent)
             VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ' . self::SESSION_DAYS . ' DAY), ?, ?)',
            [
                hash('sha256', $token),
                $userId,
                Http::ip(),
                mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200, 'UTF-8'),
            ]
        );

        self::setCookie($token, time() + (self::SESSION_DAYS * 86400));

        /* نظافت: نشست‌های منقضی را گاه‌به‌گاه پاک کن */
        if (random_int(1, 50) === 1) {
            Db::run('DELETE FROM sessions WHERE expires_at < NOW()');
        }
    }

    /** پایان نشست */
    public static function endSession(): void
    {
        $token = $_COOKIE[self::COOKIE] ?? '';
        if (is_string($token) && $token !== '') {
            Db::run('DELETE FROM sessions WHERE token = ?', [hash('sha256', $token)]);
        }
        self::setCookie('', time() - 3600);
        self::$user = null;
        self::$checked = true;
    }

    private static function setCookie(string $value, int $expires): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::COOKIE, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => Http::isHttps(),
            'httponly' => true,
            /* Lax کافی است: همه‌ی درخواست‌ها از خود دامنه می‌آیند و
               این‌طور CSRF از سایت دیگر روی POST بسته می‌شود. */
            'samesite' => 'Lax',
        ]);
    }

    /* ================= کد یک‌بارمصرف ================= */

    /**
     * ساخت و ارسال کد تأیید.
     * @return array [phone, is_new_user, expires_in, dev_code|null]
     */
    public static function requestCode(string $rawPhone): array
    {
        $phone = Http::normalizePhone($rawPhone);
        if (!Http::isValidPhone($phone)) {
            Http::fail(422, 'شماره موبایل معتبر نیست. مثال: ۰۹۱۲۳۴۵۶۷۸۹', 'phone');
        }

        $otp = Config::get('otp');

        /* --- محدودیت ارسال ---
           سه سد پشت سر هم: فاصله‌ی بین دو ارسال، سقف ساعتی هر شماره،
           سقف ساعتی هر آی‌پی. سومی جلوی کسی را می‌گیرد که با شماره‌های
           مختلف اعتبار پیامک را می‌سوزاند. */
        $last = Db::val(
            'SELECT UNIX_TIMESTAMP(created_at) FROM otp_codes
              WHERE phone = ? ORDER BY created_at DESC LIMIT 1',
            [$phone]
        );
        if ($last !== null) {
            $wait = (int) $otp['resend_after'] - (time() - (int) $last);
            if ($wait > 0) {
                Http::fail(
                    429,
                    'کد قبلی هنوز معتبر است. ' . Jalali::fa($wait) . ' ثانیه دیگر دوباره تلاش کنید.',
                    'phone'
                );
            }
        }

        $perHour = (int) Db::val(
            'SELECT COUNT(*) FROM otp_codes
              WHERE phone = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)',
            [$phone]
        );
        if ($perHour >= (int) $otp['per_hour']) {
            Http::fail(
                429,
                'تعداد درخواست کد برای این شماره زیاد شده است. لطفاً یک ساعت دیگر تلاش کنید.',
                'phone'
            );
        }

        $perIp = (int) Db::val(
            'SELECT COUNT(*) FROM otp_codes
              WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)',
            [Http::ip()]
        );
        if ($perIp >= (int) $otp['per_ip_hour']) {
            Http::fail(429, 'درخواست‌های زیادی از این دستگاه ارسال شده است. کمی بعد تلاش کنید.');
        }

        /* --- ساخت کد ---
           random_int امن رمزنگاری است؛ rand و mt_rand قابل‌حدس‌اند. */
        $len  = max(4, min(8, (int) $otp['length']));
        $code = '';
        for ($i = 0; $i < $len; $i++) {
            $code .= (string) random_int(0, 9);
        }

        $ttl = max(30, (int) $otp['ttl_seconds']);

        /* کدهای قبلیِ همین شماره باطل شوند تا فقط آخرین کد کار کند */
        Db::run('UPDATE otp_codes SET used_at = NOW() WHERE phone = ? AND used_at IS NULL', [$phone]);

        Db::run(
            'INSERT INTO otp_codes (phone, code_hash, expires_at, ip, created_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ' . $ttl . ' SECOND), ?, NOW())',
            [$phone, self::hashCode($phone, $code), Http::ip()]
        );

        $isNew = Db::val('SELECT COUNT(*) FROM users WHERE phone = ?', [$phone]) == 0;

        /* --- ارسال پیامک --- */
        $sms = new Sms();
        $res = $sms->sendOtp($phone, $code);

        if (!$res['ok'] && Config::get('sms.enabled')) {
            error_log('[zz] ارسال کد ناموفق برای ' . $phone . ': ' . $res['message']);
            Http::fail(
                502,
                'ارسال پیامک ممکن نشد. لطفاً چند لحظه بعد دوباره تلاش کنید یا تماس بگیرید.'
            );
        }

        return [
            'phone'       => $phone,
            'is_new_user' => $isNew,
            'expires_in'  => $ttl,
            /* وقتی پیامک خاموش است (حالت آزمایش)، کد در پاسخ می‌آید
               تا بشود بدون خرج اعتبار وارد شد. روی سایت واقعی این
               همیشه null است. */
            'dev_code'    => Config::get('sms.enabled') ? null : $code,
        ];
    }

    /**
     * بررسی کد و ورود.
     * @return array [user, is_new_user]
     */
    public static function verifyCode(string $rawPhone, string $rawCode): array
    {
        $phone = Http::normalizePhone($rawPhone);
        $code  = preg_replace('/\D/', '', Jalali::en($rawCode));

        if (!Http::isValidPhone($phone)) {
            Http::fail(422, 'شماره موبایل معتبر نیست.', 'phone');
        }
        if ($code === '') {
            Http::fail(422, 'کد تأیید را وارد کنید.', 'code');
        }

        $otpCfg = Config::get('otp');

        $row = Db::one(
            'SELECT * FROM otp_codes
              WHERE phone = ? AND used_at IS NULL
              ORDER BY created_at DESC LIMIT 1',
            [$phone]
        );

        if (!$row) {
            Http::fail(422, 'کدی برای این شماره در جریان نیست. دوباره درخواست کد بدهید.', 'code');
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            Http::fail(422, 'کد منقضی شده است. لطفاً دوباره درخواست کد بدهید.', 'code');
        }

        if ((int) $row['attempts'] >= (int) $otpCfg['max_attempts']) {
            Db::run('UPDATE otp_codes SET used_at = NOW() WHERE id = ?', [$row['id']]);
            Http::fail(429, 'تعداد تلاش‌های نادرست زیاد شد. لطفاً دوباره درخواست کد بدهید.', 'code');
        }

        /* hash_equals زمان‌ثابت است — جلوی حدس زدن کد از روی زمان
           پاسخ را می‌گیرد. */
        if (!hash_equals((string) $row['code_hash'], self::hashCode($phone, $code))) {
            Db::run('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
            $left = (int) $otpCfg['max_attempts'] - ((int) $row['attempts'] + 1);
            Http::fail(
                422,
                $left > 0
                    ? 'کد وارد‌شده درست نیست. ' . Jalali::fa($left) . ' تلاش دیگر باقی مانده است.'
                    : 'کد وارد‌شده درست نیست.',
                'code'
            );
        }

        Db::run('UPDATE otp_codes SET used_at = NOW() WHERE id = ?', [$row['id']]);

        /* --- ساخت یا یافتن کاربر --- */
        $user  = Db::one('SELECT * FROM users WHERE phone = ? LIMIT 1', [$phone]);
        $isNew = false;

        if (!$user) {
            $isNew = true;
            $id    = Db::uid('usr');
            Db::run(
                'INSERT INTO users (id, phone, name, role, created_at, last_login_at)
                 VALUES (?, ?, "", ?, NOW(), NOW())',
                [$id, $phone, Config::isAdminPhone($phone) ? 'admin' : 'user']
            );
            $user = Db::one('SELECT * FROM users WHERE id = ?', [$id]);
        } else {
            /* اگر شماره بعداً به فهرست مدیران اضافه شد، نقشش ارتقا
               پیدا کند — و اگر حذف شد، پایین بیاید. */
            $should = Config::isAdminPhone($phone) ? 'admin' : 'user';
            if (($user['role'] ?? 'user') !== $should) {
                Db::run('UPDATE users SET role = ? WHERE id = ?', [$should, $user['id']]);
                $user['role'] = $should;
            }
            Db::run('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
        }

        self::startSession((string) $user['id']);

        return ['user' => $user, 'is_new_user' => $isNew];
    }

    /**
     * هش کد — شماره هم داخل هش می‌رود تا هشِ یک کد برای دو شماره
     * یکسان نباشد.
     */
    private static function hashCode(string $phone, string $code): string
    {
        return hash('sha256', $phone . '|' . $code . '|zz-otp');
    }

    /* ================= نمایش کاربر ================= */

    /** ردیف دیتابیس → قالبی که backend-bridge.js انتظار دارد */
    public static function publicUser(?array $u): ?array
    {
        if (!$u) {
            return null;
        }

        $y = $u['birth_y'] !== null ? (int) $u['birth_y'] : null;
        $m = $u['birth_m'] !== null ? (int) $u['birth_m'] : null;
        $d = $u['birth_d'] !== null ? (int) $u['birth_d'] : null;

        return [
            'id'                 => $u['id'],
            'phone'              => $u['phone'],
            'name'               => $u['name'] ?? '',
            'birth'              => ($y && $m && $d) ? ['y' => $y, 'm' => $m, 'd' => $d] : null,
            'birth_label'        => Jalali::birthLabel($y, $m, $d),
            'is_birthday_today'  => Jalali::isBirthdayToday($m, $d),
            'role'               => $u['role'] ?? 'user',
            /* تاریخ عضویت (میلی‌ثانیه) — پنل کاربری «عضویت از» را نشان می‌دهد */
            'member_since'       => !empty($u['created_at'])
                ? strtotime((string) $u['created_at']) * 1000
                : null,
        ];
    }
}
