<?php
/* ==========================================================================
   sms.php — درایور پنل پیامکی نیازپرداز

   مستندات: https://niazpardaz-sms.com/webservice
   وب‌سرویس REST:  http://in.payamak-service.ir/api/v2/RestWebApi

   ── نکته‌ی مهم درباره‌ی «پترن» ────────────────────────────────────────
   نیازپرداز متد جداگانه‌ای برای ارسال با پترن ندارد (برخلاف کاوه‌نگار
   یا SMS.ir). مدلش این است:

       ۱. متن الگو را یک بار در پنل ثبت و تأیید می‌کنید
       ۲. بعد متنِ کاملِ ساخته‌شده را با همان متد معمولی می‌فرستید
       ۳. سامانه متن را با الگوهای تأییدشده‌ی شما تطبیق می‌دهد

   اگر متن با هیچ الگویی نخواند، کد ۱۸ («مغایرت متن با قالب») برمی‌گردد.
   پس متن قالب در config باید کاراکتر‌به‌کاراکتر همان چیزی باشد که در
   پنل تأیید شده است.
   ──────────────────────────────────────────────────────────────────────

   احراز هویت: مستندات REST نام کاربری و رمز عبور می‌خواهد، ولی کدهای
   خطای ۲۵ و ۷- مربوط به ApiKey هستند و SDK رسمی فقط با کلید کار می‌کند.
   یعنی سامانه هر دو را می‌پذیرد. تنظیم auth_mode تعیین می‌کند کدام
   یک در جای رمز عبور بنشیند:
       'username_password' → رمز پنل   (حالت پیش‌فرض و مستندشده)
       'api_key'           → کلید API
   اگر یکی جواب نداد، فقط همین یک مقدار را در config عوض کنید.
   ========================================================================== */

declare(strict_types=1);

final class Sms
{
    /** @var array<string,mixed> */
    private array $cfg;

    /**
     * جزئیات آخرین درخواست وب‌سرویس — فقط برای صفحه‌ی تشخیص.
     * شامل آدرس، کد HTTP، بدنه‌ی خام پاسخ و بدنه‌ی درخواست (بدون رمز).
     *
     * @var array<string,mixed>
     */
    private array $last = [];

    /**
     * @param array<string,mixed>|null $cfg بخش sms از config —
     *        اگر ندهید، خودش از Config می‌خواند.
     */
    public function __construct(?array $cfg = null)
    {
        $this->cfg = $cfg ?? (array) Config::get('sms', []);
    }

    /* ------------------------------------------------------------------
       جدول کد خطاهای نیازپرداز
       ------------------------------------------------------------------ */

    /** @var array<int,string> */
    private const RESULT = [
        -20 => 'به علت وارد کردن رمز اشتباه، پنل مسدود شده است.',
        -7  => 'کلید API معتبر نیست.',
        -6  => 'آی‌پی سرور موقتاً بلاک شده است.',
        -2  => 'کاربر پنل غیرفعال است.',
        -1  => 'نام کاربری یا رمز عبور درست نیست.',
        0   => 'ارسال با موفقیت انجام شد.',
        1   => 'نام کاربری یا کلمه‌ی عبور نامعتبر است.',
        2   => 'کاربر مسدود شده است.',
        3   => 'شماره‌ی فرستنده نامعتبر است.',
        4   => 'محدودیت در ارسال روزانه.',
        5   => 'تعداد گیرندگان حداکثر ۱۰۰ شماره است.',
        6   => 'خط فرستنده غیرفعال است.',
        7   => 'متن پیامک شامل کلمات فیلترشده است.',
        8   => 'اعتبار پنل پیامکی کافی نیست.',
        9   => 'سامانه‌ی پیامک در حال به‌روزرسانی است.',
        10  => 'وب‌سرویس پنل پیامکی غیرفعال است.',
        11  => 'این متد پیاده‌سازی نشده است.',
        12  => 'تعداد پیام‌ها و شماره‌ها باید یکسان باشد.',
        13  => 'تعداد پیام‌ها حداکثر ۱۰۰ پیام است.',
        14  => 'تعرفه‌ای برای این کاربر تعریف نشده است.',
        15  => 'ارسال تکراری همین متن به همین شماره در بازه‌ی کوتاه.',
        16  => 'شماره در لیست سیاه است و خط تبلیغاتی به آن ارسال نمی‌کند.',
        17  => 'متن پیامک خالی است.',
        18  => 'متن پیامک با الگوی تأییدشده نمی‌خواند.',
        19  => 'تاریخ انقضای پنل پیامکی گذشته است.',
        20  => 'وضعیت کاربر فعال نیست.',
        21  => 'یکی از پارامترهای ورودی معتبر نیست.',
        22  => 'آی‌پی موقتاً بلاک شده است.',
        23  => 'خطای موقت سامانه. چند دقیقه بعد دوباره تلاش کنید.',
        24  => 'درخواست کاملاً تکراری در چند ثانیه‌ی اخیر.',
        25  => 'کلید API نامعتبر است.',
        26  => 'خطا در ساخت فایل صوتی.',
    ];

    public static function describe(int $code): string
    {
        return self::RESULT[$code] ?? ('کد نتیجه‌ی ناشناخته: ' . $code);
    }

    /** آیا این کد یعنی موفقیت؟ */
    public static function isOk(int $code): bool
    {
        return $code === 0;
    }

    /** جزئیات آخرین فراخوانی وب‌سرویس — برای sms-test.php */
    public function lastCall(): array
    {
        return $this->last;
    }

    /**
     * خواندن یک کلید از پاسخ، بدون حساسیت به بزرگی و کوچکی حروف
     * و بدون حساسیت به خط زیر — چون پنل‌های مختلف یکسان جواب نمی‌دهند.
     *
     * @param array<string,mixed> $res
     * @param array<int,string>   $names
     */
    private static function field(array $res, array $names, $default = null)
    {
        $flat = [];
        foreach ($res as $k => $v) {
            $flat[strtolower(str_replace('_', '', (string) $k))] = $v;
        }
        foreach ($names as $name) {
            $key = strtolower(str_replace('_', '', $name));
            if (array_key_exists($key, $flat)) {
                return $flat[$key];
            }
        }
        return $default;
    }

    /**
     * کد نتیجه را از پاسخ بیرون می‌کشد.
     *
     * نیازپرداز ResultCode می‌دهد که صفرش یعنی موفق. بعضی پنل‌های
     * هم‌خانواده به جایش RetStatus می‌دهند که در آن‌ها یک یعنی موفق.
     * این تابع دومی را به قرارداد اولی ترجمه می‌کند تا بقیه‌ی کد
     * فقط یک قرارداد بشناسد.
     *
     * @param array<string,mixed> $res
     */
    private static function codeOf(array $res): int
    {
        $code = self::field($res, ['ResultCode', 'Result', 'Status', 'Code']);
        if ($code !== null && is_numeric($code)) {
            return (int) $code;
        }

        $ret = self::field($res, ['RetStatus']);
        if ($ret !== null && is_numeric($ret)) {
            return ((int) $ret) === 1 ? 0 : (int) $ret;
        }

        return -100;
    }

    /* ------------------------------------------------------------------
       ساخت متن از روی قالب
       ------------------------------------------------------------------ */

    /**
     * متن یک قالب را با مقادیر داده‌شده می‌سازد.
     *
     * @param array<string,string|int> $vars
     * @return string|null اگر قالب تعریف نشده باشد null — یعنی این نوع
     *                     پیامک هنوز الگوی تأییدشده ندارد و نباید فرستاده شود
     */
    public static function render(string $tpl, array $vars = []): string
    {
        foreach ($vars as $k => $v) {
            $tpl = str_replace('{' . $k . '}', (string) $v, $tpl);
        }
        return $tpl;
    }

    /**
     * متن یک قالبِ نام‌گذاری‌شده را می‌سازد.
     * اگر قالب در تنظیمات خالی باشد null برمی‌گرداند — یعنی این نوع
     * پیامک هنوز الگوی تأییدشده ندارد و نباید فرستاده شود.
     */
    public function template(string $key, array $vars = []): ?string
    {
        $tpl = $this->cfg['templates'][$key] ?? null;
        if (!is_string($tpl) || $tpl === '') {
            return null;
        }
        return self::render($tpl, $vars);
    }

    /* ------------------------------------------------------------------
       ارسال
       ------------------------------------------------------------------ */

    /**
     * ارسال یک پیامک.
     *
     * @return array{ok:bool, code:int, message:string, id:int|null, skipped?:bool}
     */
    public function send(string $to, string $text, string $tag = ''): array
    {
        $to = self::normalizePhone($to);
        if ($to === null) {
            return ['ok' => false, 'code' => 21, 'message' => 'شماره‌ی گیرنده معتبر نیست.', 'id' => null];
        }
        if (trim($text) === '') {
            return ['ok' => false, 'code' => 17, 'message' => self::describe(17), 'id' => null];
        }

        /* حالت خاموش — برای توسعه و تست بدون خرج کردن اعتبار */
        if (empty($this->cfg['enabled'])) {
            error_log('[sms:disabled] to=' . $to . ' text=' . $text);
            return [
                'ok' => true, 'code' => 0, 'id' => null, 'skipped' => true,
                'message' => 'ارسال پیامک خاموش است؛ متن فقط در لاگ ثبت شد.',
            ];
        }

        $res = $this->call('SendBatchSms', [
            'fromNumber'     => (string) ($this->cfg['from'] ?? ''),
            'toNumbers'      => $to,
            'messageContent' => $text,
            'isFlash'        => false,
        ]);

        if (isset($res['error'])) {
            $out = ['ok' => false, 'code' => -100, 'message' => $res['error'], 'id' => null];
            self::log($to, $tag, $text, $out);
            return $out;
        }

        $code = self::codeOf($res);
        $id   = self::field($res, ['BatchSmsId', 'SmsId', 'MessageId', 'Value']);
        $out  = [
            'ok'      => self::isOk($code),
            'code'    => $code,
            'message' => self::describe($code),
            'id'      => is_numeric($id) ? (int) $id : null,
        ];
        self::log($to, $tag, $text, $out);
        return $out;
    }

    /** ارسال کد یک‌بارمصرف با قالب تأییدشده */
    public function sendOtp(string $to, string $code): array
    {
        $text = $this->template('otp', ['code' => $code]);
        if ($text === null) {
            return ['ok' => false, 'code' => 17, 'id' => null,
                    'message' => 'قالب پیامک کد تأیید در تنظیمات خالی است.'];
        }
        return $this->send($to, $text, 'otp');
    }

    /**
     * پیامک اطلاع‌رسانی مربوط به یک نوبت.
     *
     * متغیرهای همیشه در دسترس: {date} {time} {service}
     * با $extra می‌شود متغیر دیگری هم اضافه کرد (مثل {reason}).
     *
     * اگر قالب در تنظیمات خالی باشد هیچ کاری نمی‌کند — یعنی الگویش
     * هنوز در پنل تأیید نشده و فرستادنش فقط خطای ۱۸ می‌دهد و
     * اعتبار می‌سوزاند.
     *
     * شکست ارسال هرگز نباید کار اصلی را برگرداند؛ نوبت از پیامک
     * مهم‌تر است. پس فقط لاگ می‌شود.
     *
     * @param array<string,mixed>        $appt سطر جدول appointments
     * @param array<string,string|int>   $extra
     * @param string|null                $to   اگر بدهید به جای مشتری
     *                                         به همین شماره می‌رود
     */
    public static function notifyAppointment(
        array $appt,
        string $key,
        array $extra = [],
        ?string $to = null
    ): void {
        $tpl = Config::get('sms.templates.' . $key);
        if (!is_string($tpl) || $tpl === '') {
            return;
        }

        $phone = $to ?? Db::val('SELECT phone FROM users WHERE id = ?', [$appt['user_id']]);
        if (!$phone) {
            return;
        }

        try {
            $vars = array_merge([
                'date'    => Jalali::long((string) $appt['date']),
                'time'    => Jalali::fa((string) $appt['time']),
                'service' => (string) Db::val(
                    'SELECT title FROM services WHERE id = ?',
                    [$appt['service_id']]
                ),
            ], $extra);

            (new self())->send((string) $phone, self::render($tpl, $vars), $key);
        } catch (Throwable $e) {
            error_log('[zz] پیامک اطلاع‌رسانی ناموفق: ' . $e->getMessage());
        }
    }

    /**
     * ثبت در جدول sms_log.
     *
     * متنِ کد تأیید عمداً ذخیره نمی‌شود — اگر روزی کسی به دیتابیس
     * دسترسی پیدا کند، نباید بتواند کد در جریان کسی را از لاگ بخواند.
     */
    private static function log(string $to, string $tag, string $text, array $res): void
    {
        if (!class_exists('Db')) {
            return;
        }
        try {
            Db::run(
                'INSERT INTO sms_log (phone, tag, body, result_code, message, batch_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())',
                [
                    $to,
                    mb_substr($tag, 0, 20, 'UTF-8'),
                    $tag === 'otp' ? '(کد تأیید — ثبت نمی‌شود)' : mb_substr($text, 0, 400, 'UTF-8'),
                    (int) $res['code'],
                    mb_substr((string) $res['message'], 0, 200, 'UTF-8'),
                    $res['id'],
                ]
            );
        } catch (Throwable $e) {
            /* نبودن جدول لاگ نباید جلوی ارسال پیامک را بگیرد */
        }
    }

    /* ------------------------------------------------------------------
       گزارش‌ها — برای صفحه‌ی تشخیص و هشدار کمبود اعتبار
       ------------------------------------------------------------------ */

    /** @return array{ok:bool, credit:float|null, message:string} */
    public function credit(): array
    {
        $res = $this->call('GetCredit', []);
        if (isset($res['error'])) {
            return ['ok' => false, 'credit' => null, 'message' => $res['error']];
        }
        $code   = self::codeOf($res);
        $amount = self::field($res, ['Credit', 'Value', 'Amount']);
        return [
            'ok'      => self::isOk($code),
            'credit'  => is_numeric($amount) ? (float) $amount : null,
            'message' => self::describe($code),
        ];
    }

    /** @return array{ok:bool, senders:array<int,string>, message:string} */
    public function senders(): array
    {
        $res = $this->call('GetSenderNumbers', []);
        if (isset($res['error'])) {
            return ['ok' => false, 'senders' => [], 'message' => $res['error']];
        }
        $code = self::codeOf($res);
        return [
            'ok'      => self::isOk($code),
            'senders' => array_map('strval', (array) self::field($res, ['Senders', 'SenderNumbers', 'Numbers', 'Value'], [])),
            'message' => self::describe($code),
        ];
    }

    /** بررسی اینکه متن کلمه‌ی فیلترشده ندارد */
    public function checkContent(string $text): array
    {
        $res = $this->call('CheckSmsContent', ['message' => $text]);
        if (isset($res['error'])) {
            return ['ok' => false, 'valid' => null, 'message' => $res['error']];
        }
        $code  = self::codeOf($res);
        $valid = self::field($res, ['IsValid', 'Valid']);
        return [
            'ok'      => self::isOk($code),
            'valid'   => $valid === null ? null : (bool) $valid,
            'message' => self::describe($code),
        ];
    }

    /* ------------------------------------------------------------------
       لایه‌ی HTTP
       ------------------------------------------------------------------ */

    /**
     * فراخوانی یک متد وب‌سرویس.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>  در صورت خطای شبکه کلید error دارد
     */
    private function call(string $method, array $params, array $opts = []): array
    {
        $base = rtrim((string) ($opts['base_url'] ?? $this->cfg['base_url'] ?? ''), '/');
        $url  = $base . '/' . $method;

        /* auth_mode تعیین می‌کند رمز پنل برود یا کلید API — توضیحش
           بالای فایل */
        $mode   = (string) ($opts['auth_mode'] ?? $this->cfg['auth_mode'] ?? 'username_password');
        $secret = $mode === 'api_key'
            ? (string) ($this->cfg['api_key'] ?? '')
            : (string) ($this->cfg['password'] ?? '');

        $body = array_merge([
            'userName' => (string) ($this->cfg['username'] ?? ''),
            'password' => $secret,
        ], $params);

        /* بعضی پیاده‌سازی‌ها کلید را جدا از رمز می‌خواهند */
        if (!empty($opts['with_api_key']) && !empty($this->cfg['api_key'])) {
            $body['apiKey'] = (string) $this->cfg['api_key'];
        }

        $form = !empty($opts['form']);
        if ($form) {
            $payload = http_build_query($body);
            $headers = [
                'Content-Type: application/x-www-form-urlencoded; charset=utf-8',
                'Accept: application/json',
            ];
        } else {
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
            if ($payload === false) {
                return ['error' => 'ساخت درخواست JSON ناموفق بود.'];
            }
            $headers = [
                'Content-Type: application/json; charset=utf-8',
                'Accept: application/json',
            ];
        }

        /* آنچه در صفحه‌ی تشخیص نشان داده می‌شود — رمز عمداً پاک شده */
        $shown = $body;
        foreach (['password', 'apiKey'] as $secretKey) {
            if (isset($shown[$secretKey])) {
                $shown[$secretKey] = $shown[$secretKey] === '' ? '(خالی)' : '(پنهان)';
            }
        }
        $this->last = [
            'url'      => $url,
            'encoding' => $form ? 'form' : 'json',
            'sent'     => $shown,
            'http'     => 0,
            'raw'      => '',
            'error'    => '',
        ];

        if (!function_exists('curl_init')) {
            return ['error' => 'افزونه‌ی cURL روی این هاست فعال نیست.'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => (int) ($opts['timeout'] ?? $this->cfg['timeout'] ?? 15),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->last['http'] = $http;
        $this->last['raw']  = is_string($raw) ? mb_substr($raw, 0, 2000, 'UTF-8') : '';

        if ($raw === false || $err !== '') {
            $this->last['error'] = $err;
            return ['error' => 'ارتباط با سامانه‌ی پیامک برقرار نشد: ' . $err];
        }
        if ($http < 200 || $http >= 300) {
            return ['error' => 'سامانه‌ی پیامک کد HTTP ' . $http . ' برگرداند.'];
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            return ['error' => 'پاسخ سامانه‌ی پیامک JSON نبود: ' . mb_substr(trim((string) $raw), 0, 200, 'UTF-8')];
        }

        /* ── پوسته‌ی واقعی نیازپرداز ──────────────────────────────
           مستندات فقط بخش داخلی را نشان می‌دهد، ولی سرور در عمل
           همه‌چیز را داخل یک پاکت می‌گذارد:

             {"success":true,
              "result":{"credit":115.23,"resultCode":0},
              "errorMessage":"","adminErrorMessage":""}

           پس اول پاکت را باز می‌کنیم و اگر success دروغ بود، همان
           errorMessage فارسیِ خود سامانه را به بالا برمی‌گردانیم. */
        if (array_key_exists('success', $data) && array_key_exists('result', $data)) {
            $ok    = !empty($data['success']);
            $inner = $data['result'];
            $msg   = trim((string) ($data['errorMessage'] ?? ''));
            if ($msg === '') {
                $msg = trim((string) ($data['adminErrorMessage'] ?? ''));
            }

            if (!$ok || !is_array($inner)) {
                return ['error' => $msg !== '' ? $msg : 'سامانه‌ی پیامک درخواست را نپذیرفت.'];
            }
            $data = $inner;
        }

        /* بعضی سرویس‌ها پاسخ را یک یا دو لایه بسته‌بندی می‌کنند —
           مثل {"GetCreditResult":{...}} یا {"d":{...}} یا {"data":{...}}.
           تا وقتی پوسته فقط یک کلید دارد، لایه‌ها را باز می‌کنیم. */
        for ($i = 0; $i < 3; $i++) {
            if (count($data) !== 1) {
                break;
            }
            $only  = array_key_first($data);
            $inner = $data[$only];
            $isWrapper = is_string($only) && (
                strcasecmp($only, 'd') === 0 ||
                strcasecmp($only, 'data') === 0 ||
                strcasecmp($only, 'result') === 0 ||
                substr_compare($only, 'Result', -6, 6, true) === 0
            );
            if (!$isWrapper || !is_array($inner)) {
                break;
            }
            $data = $inner;
        }

        /* بعضی متدها کلیدها را با حرف کوچک برمی‌گردانند */
        $out = [];
        foreach ($data as $k => $v) {
            $out[is_string($k) ? ucfirst($k) : $k] = $v;
        }
        $this->last['parsed'] = $out;
        return $out;
    }

    /**
     * یک فراخوانی آزمایشی با تنظیمات دلخواه — فقط برای sms-test.php.
     * پاسخ خام را هم برمی‌گرداند تا بشود دید سامانه دقیقاً چه می‌گوید.
     *
     * @param array<string,mixed> $params
     * @param array<string,mixed> $opts   base_url / auth_mode / form / with_api_key
     */
    public function probe(string $method, array $params = [], array $opts = []): array
    {
        $res = $this->call($method, $params, $opts);
        return [
            'code'    => isset($res['error']) ? -100 : self::codeOf($res),
            'error'   => $res['error'] ?? '',
            'call'    => $this->last,
        ];
    }

    /* ------------------------------------------------------------------
       کمکی
       ------------------------------------------------------------------ */

    /**
     * شماره را به شکل 09xxxxxxxxx در می‌آورد.
     * ارقام فارسی و عربی، +98، 0098 و 98 را هم می‌پذیرد.
     */
    public static function normalizePhone(string $raw): ?string
    {
        $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        $ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $en = ['0','1','2','3','4','5','6','7','8','9'];
        $s  = str_replace($fa, $en, $raw);
        $s  = str_replace($ar, $en, $s);
        $s  = preg_replace('/\D+/', '', $s) ?? '';

        /* عمداً از str_starts_with استفاده نشده: آن تابع PHP 8 است و
           خیلی از هاست‌های اشتراکی هنوز روی 7.4 هستند. */
        if (strncmp($s, '0098', 4) === 0) {
            $s = '0' . substr($s, 4);
        } elseif (strncmp($s, '98', 2) === 0 && strlen($s) === 12) {
            $s = '0' . substr($s, 2);
        } elseif (strlen($s) === 10 && $s[0] === '9') {
            $s = '0' . $s;
        }

        return preg_match('/^09\d{9}$/', $s) === 1 ? $s : null;
    }
}
