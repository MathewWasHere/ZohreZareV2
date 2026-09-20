<?php
/* ==========================================================================
   jalali.php — تقویم شمسی

   چرا دستی نوشته شده و از IntlDateFormatter استفاده نشده؟
   چون افزونه‌ی intl روی خیلی از هاست‌های اشتراکی نصب نیست و اگر
   نبود، کل تقویم سایت از کار می‌افتاد. الگوریتم زیر استاندارد است
   و به هیچ افزونه‌ای نیاز ندارد.

   خروجی این فایل باید دقیقاً با assets/js/core/utils.js یکی باشد،
   وگرنه تاریخی که سرور می‌فرستد با تاریخی که مرورگر نشان می‌دهد
   فرق می‌کند.
   ========================================================================== */

if (!defined('ZZ_APP')) {
    http_response_code(403);
    exit('دسترسی مستقیم مجاز نیست.');
}

final class Jalali
{
    /** نام ماه‌های شمسی — همان ترتیب utils.js */
    const MONTHS = [
        'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
        'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'
    ];

    /** نام روزهای هفته — اندیس با date('w') یکی است (۰ = یکشنبه) */
    const WEEKDAYS = [
        'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه',
        'پنجشنبه', 'جمعه', 'شنبه'
    ];

    private const FA_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    /* ---------------- تبدیل ---------------- */

    /**
     * میلادی → شمسی
     * @return array [سال، ماه، روز]
     */
    public static function fromGregorian(int $gy, int $gm, int $gd): array
    {
        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];

        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365 * $gy) + ((int) (($gy2 + 3) / 4))
              - ((int) (($gy2 + 99) / 100)) + ((int) (($gy2 + 399) / 400))
              + $gd + $g_d_m[$gm - 1];

        $jy = -1595 + (33 * ((int) ($days / 12053)));
        $days %= 12053;

        $jy += 4 * ((int) ($days / 1461));
        $days %= 1461;

        if ($days > 365) {
            $jy += (int) (($days - 1) / 365);
            $days = ($days - 1) % 365;
        }

        if ($days < 186) {
            $jm = 1 + (int) ($days / 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + (int) (($days - 186) / 30);
            $jd = 1 + (($days - 186) % 30);
        }

        return [$jy, $jm, $jd];
    }

    /**
     * شمسی → میلادی
     * @return array [سال، ماه، روز]
     */
    public static function toGregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;
        $days = -355668 + (365 * $jy) + (((int) ($jy / 33)) * 8)
              + ((int) ((($jy % 33) + 3) / 4)) + $jd
              + (($jm < 7) ? (($jm - 1) * 31) : ((($jm - 7) * 30) + 186));

        $gy = 400 * ((int) ($days / 146097));
        $days %= 146097;

        if ($days > 36524) {
            $gy += 100 * ((int) (--$days / 36524));
            $days %= 36524;
            if ($days >= 365) {
                $days++;
            }
        }

        $gy += 4 * ((int) ($days / 1461));
        $days %= 1461;

        if ($days > 365) {
            $gy += (int) (($days - 1) / 365);
            $days = ($days - 1) % 365;
        }

        $gd = $days + 1;
        $sal_a = [
            0, 31,
            (($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0)) ? 29 : 28,
            31, 30, 31, 30, 31, 31, 30, 31, 30, 31
        ];

        $gm = 0;
        while ($gm < 13 && $gd > $sal_a[$gm]) {
            $gd -= $sal_a[$gm];
            $gm++;
        }

        return [$gy, $gm, $gd];
    }

    /* ---------------- کمکی‌های سال شمسی ---------------- */

    /** آیا سال شمسی کبیسه است؟ (الگوریتم ۳۳ساله — همان utils.js) */
    public static function isLeap(int $jy): bool
    {
        $r = $jy % 33;
        return in_array($r, [1, 5, 9, 13, 17, 22, 26, 30], true);
    }

    /** تعداد روزهای یک ماه شمسی */
    public static function monthDays(int $jy, int $jm): int
    {
        if ($jm <= 6) {
            return 31;
        }
        if ($jm <= 11) {
            return 30;
        }
        return self::isLeap($jy) ? 30 : 29;
    }

    /** سال شمسی جاری */
    public static function currentYear(): int
    {
        [$jy, , ] = self::fromGregorian(
            (int) date('Y'), (int) date('n'), (int) date('j')
        );
        return $jy;
    }

    /* ---------------- قالب‌بندی ---------------- */

    /** ارقام لاتین → فارسی */
    public static function fa($input): string
    {
        return str_replace(
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            self::FA_DIGITS,
            (string) $input
        );
    }

    /** ارقام فارسی/عربی → لاتین */
    public static function en($input): string
    {
        $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $ar = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $la = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        return str_replace($ar, $la, str_replace($fa, $la, (string) $input));
    }

    /** «۴ مرداد» — معادل utils.faDateShort */
    public static function short(string $dateKey): string
    {
        [$jy, $jm, $jd] = self::keyToJalali($dateKey);
        return self::fa($jd) . ' ' . self::MONTHS[$jm - 1];
    }

    /** «یکشنبه ۴ مرداد» — معادل utils.faDate */
    public static function long(string $dateKey, bool $withYear = false): string
    {
        [$jy, $jm, $jd] = self::keyToJalali($dateKey);
        $out = self::weekday($dateKey) . ' ' . self::fa($jd) . ' ' . self::MONTHS[$jm - 1];
        if ($withYear) {
            $out .= ' ' . self::fa($jy);
        }
        return $out;
    }

    /** نام روز هفته */
    public static function weekday(string $dateKey): string
    {
        $ts = strtotime($dateKey . ' 12:00:00');
        return self::WEEKDAYS[(int) date('w', $ts)];
    }

    /**
     * «امروز» / «فردا» / نام روز — معادل utils.faDayLabel
     */
    public static function dayLabel(string $dateKey): string
    {
        $today = date('Y-m-d');
        if ($dateKey === $today) {
            return 'امروز';
        }
        if ($dateKey === date('Y-m-d', strtotime('+1 day'))) {
            return 'فردا';
        }
        return self::weekday($dateKey);
    }

    /** «۱۲ مرداد ۱۳۷۵» — معادل utils.formatBirth */
    public static function birthLabel(?int $y, ?int $m, ?int $d): ?string
    {
        if (!$y || !$m || !$d || $m < 1 || $m > 12) {
            return null;
        }
        return self::fa($d) . ' ' . self::MONTHS[$m - 1] . ' ' . self::fa($y);
    }

    /** آیا امروز تولد این شخص است؟ (فقط ماه و روز مهم است) */
    public static function isBirthdayToday(?int $m, ?int $d): bool
    {
        if (!$m || !$d) {
            return false;
        }
        [, $jm, $jd] = self::fromGregorian(
            (int) date('Y'), (int) date('n'), (int) date('j')
        );
        return $jm === $m && $jd === $d;
    }

    /** اعتبارسنجی تاریخ تولد — معادل utils.validBirth */
    public static function validBirth(int $jy, int $jm, int $jd): ?string
    {
        $cy = self::currentYear();
        if (!$jy || !$jm || !$jd) {
            return 'تاریخ تولد کامل نیست.';
        }
        if ($jm < 1 || $jm > 12) {
            return 'ماه معتبر نیست.';
        }
        if ($jy < $cy - 100 || $jy > $cy - 5) {
            return 'سال تولد معتبر نیست.';
        }
        $max = self::monthDays($jy, $jm);
        if ($jd < 1 || $jd > $max) {
            return self::MONTHS[$jm - 1] . ' ' . self::fa($max) . ' روز دارد.';
        }
        return null;
    }

    /* ---------------- داخلی ---------------- */

    /** 'YYYY-MM-DD' میلادی → [jy, jm, jd] */
    private static function keyToJalali(string $dateKey): array
    {
        $p = explode('-', $dateKey);
        return self::fromGregorian(
            (int) ($p[0] ?? 0), (int) ($p[1] ?? 1), (int) ($p[2] ?? 1)
        );
    }
}
