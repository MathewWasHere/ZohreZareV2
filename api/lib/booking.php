<?php
/* ==========================================================================
   booking.php — تقویم، بازه‌ها و نوبت‌ها

   این فایل نسخه‌ی سروریِ assets/js/data/appointments.js است. هر
   قاعده‌ای که آن‌جا هست باید این‌جا هم باشد — با یک تفاوت مهم:

   نسخه‌ی مرورگر برای شلوغ نشان دادن تقویمِ دمو، بعضی بازه‌ها را با
   یک هش «تکمیل» می‌کرد. آن مربوط به نمایش بود و این‌جا نیست؛ روی
   سایت واقعی فقط نوبت واقعی و بستنِ دستی مدیر یک ساعت را می‌بندد.

   قاعده‌ی کلیدی جریانِ بدون درگاه پرداخت:
   درخواستِ «در انتظار تأیید» هم ساعت را نگه می‌دارد، تا وقتی مدیر
   دارد با مشتری تماس می‌گیرد کس دیگری همان ساعت را نگیرد.
   ========================================================================== */

if (!defined('ZZ_APP')) {
    http_response_code(403);
    exit('دسترسی مستقیم مجاز نیست.');
}

final class Booking
{
    /** وضعیت‌هایی که ساعت را اشغال می‌کنند */
    public static function holdingStatuses(): array
    {
        return ['confirmed', 'pending'];
    }

    /** ساعت‌های کاری */
    public static function times(): array
    {
        $t = Config::get('booking.times', []);
        return is_array($t) && $t ? array_values($t) : ['10:00'];
    }

    /* ================= تقویم ================= */

    /** آیا این روز تعطیل هفتگی است؟ (پیش‌فرض: جمعه) */
    public static function isWeeklyClosed(string $dateKey): bool
    {
        $w = (int) Config::get('booking.closed_weekday', 5);
        return (int) date('w', strtotime($dateKey . ' 12:00:00')) === $w;
    }

    /** فهرست روزهای پیش رو */
    public static function days(int $count = 0): array
    {
        $n = $count > 0 ? min($count, 60) : (int) Config::get('booking.days_ahead', 14);

        /* یک بار همه‌ی داده‌ی لازم را می‌گیریم تا داخل حلقه به ازای
           هر روز به دیتابیس نزنیم. */
        $from = date('Y-m-d');
        $to   = date('Y-m-d', strtotime('+' . $n . ' days'));

        $closed = array_column(
            Db::all('SELECT date FROM closed_days WHERE date BETWEEN ? AND ?', [$from, $to]),
            'date'
        );
        $closed = array_flip($closed);

        $blocked = [];
        foreach (Db::all(
            'SELECT date, time FROM blocked_slots WHERE date BETWEEN ? AND ?', [$from, $to]
        ) as $r) {
            $blocked[$r['date']][] = $r['time'];
        }

        $taken = [];
        $ph = implode(',', array_fill(0, count(self::holdingStatuses()), '?'));
        foreach (Db::all(
            'SELECT date, time FROM appointments
              WHERE date BETWEEN ? AND ? AND status IN (' . $ph . ')',
            array_merge([$from, $to], self::holdingStatuses())
        ) as $r) {
            $taken[$r['date']][] = $r['time'];
        }

        $times = self::times();
        $out   = [];

        for ($i = 0; $i < $n; $i++) {
            $key    = date('Y-m-d', strtotime('+' . $i . ' days'));
            $byAdmin = isset($closed[$key]);
            $isClosed = $byAdmin || self::isWeeklyClosed($key);

            $hasOpen = false;
            if (!$isClosed) {
                $busy = array_merge($blocked[$key] ?? [], $taken[$key] ?? []);
                foreach ($times as $t) {
                    if (!in_array($t, $busy, true) && !self::isPast($key, $t)) {
                        $hasOpen = true;
                        break;
                    }
                }
            }

            $out[] = [
                'key'             => $key,
                'label'           => Jalali::dayLabel($key),
                'short'           => Jalali::short($key),
                'closed'          => $isClosed,
                'closed_by_admin' => $byAdmin,
                'has_open_slot'   => $hasOpen,
            ];
        }

        return $out;
    }

    /** بازه‌های یک روز — [{time, available, reason}] */
    public static function slots(string $dateKey): array
    {
        if (!self::isValidKey($dateKey)) {
            Http::fail(422, 'تاریخ معتبر نیست.', 'date');
        }
        if (self::isWeeklyClosed($dateKey)) {
            return [];
        }
        if (Db::val('SELECT COUNT(*) FROM closed_days WHERE date = ?', [$dateKey]) > 0) {
            return [];
        }

        $blocked = array_column(
            Db::all('SELECT time FROM blocked_slots WHERE date = ?', [$dateKey]),
            'time'
        );

        $ph = implode(',', array_fill(0, count(self::holdingStatuses()), '?'));
        $booked = [];
        foreach (Db::all(
            'SELECT time, status FROM appointments
              WHERE date = ? AND status IN (' . $ph . ')',
            array_merge([$dateKey], self::holdingStatuses())
        ) as $r) {
            $booked[$r['time']] = $r['status'];
        }

        $out = [];
        foreach (self::times() as $time) {
            $available = true;
            $reason    = null;

            if (isset($booked[$time])) {
                $available = false;
                $reason = $booked[$time] === 'pending' ? 'در انتظار تأیید' : 'رزرو شده';
            }
            if ($available && in_array($time, $blocked, true)) {
                $available = false;
                $reason = 'تکمیل';
            }
            if ($available && self::isPast($dateKey, $time)) {
                $available = false;
                $reason = 'گذشته';
            }

            $out[] = ['time' => $time, 'available' => $available, 'reason' => $reason];
        }

        return $out;
    }

    /* ================= ثبت نوبت ================= */

    public static function create(array $user, array $in): array
    {
        $serviceId = (string) ($in['service_id'] ?? '');
        $variantId = (string) ($in['variant_id'] ?? '');
        $date      = (string) ($in['date'] ?? '');
        $time      = (string) ($in['time'] ?? '');
        $note      = mb_substr(trim((string) ($in['note'] ?? '')), 0, 400, 'UTF-8');

        $service = Db::one('SELECT * FROM services WHERE id = ? AND active = 1', [$serviceId]);
        if (!$service) {
            Http::fail(422, 'خدمت انتخاب‌شده معتبر نیست.', 'service');
        }

        $variant = Db::one(
            'SELECT * FROM service_variants WHERE service_id = ? AND variant_key = ?',
            [$serviceId, $variantId]
        );
        if (!$variant) {
            $variant = Db::one(
                'SELECT * FROM service_variants WHERE service_id = ? ORDER BY sort_order LIMIT 1',
                [$serviceId]
            );
        }
        if (!$variant) {
            Http::fail(422, 'برای این خدمت گزینه‌ای تعریف نشده است.', 'variant');
        }

        if (!self::isValidKey($date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            Http::fail(422, 'لطفاً روز و ساعت نوبت را انتخاب کنید.', 'date');
        }

        /* سقف درخواست‌های باز — وگرنه یک نفر می‌تواند با چند درخواست
           کل تقویم را قفل کند. */
        $maxOpen = (int) Config::get('booking.max_open_requests', 2);
        if ($maxOpen > 0) {
            $open = (int) Db::val(
                'SELECT COUNT(*) FROM appointments
                  WHERE user_id = ? AND status = "pending"
                    AND CONCAT(date, " ", time) >= ?',
                [$user['id'], date('Y-m-d H:i')]
            );
            if ($open >= $maxOpen) {
                Http::fail(
                    409,
                    'شما ' . Jalali::fa($open) . ' درخواست در انتظار تأیید دارید. '
                    . 'لطفاً تا تعیین تکلیف آن‌ها صبر کنید یا یکی را لغو کنید.'
                );
            }
        }

        /* بررسی آزاد بودن بازه */
        $slot = null;
        foreach (self::slots($date) as $s) {
            if ($s['time'] === $time) {
                $slot = $s;
                break;
            }
        }
        if (!$slot || !$slot['available']) {
            Http::fail(
                409,
                'این بازه‌ی زمانی دیگر در دسترس نیست. لطفاً ساعت دیگری انتخاب کنید.',
                'time'
            );
        }

        $id = Db::uid('apt');

        try {
            Db::run(
                'INSERT INTO appointments
                   (id, user_id, service_id, variant_key, variant_name,
                    date, time, duration_min, price, status, note, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "pending", ?, NOW())',
                [
                    $id, $user['id'], $serviceId, $variant['variant_key'], $variant['name'],
                    $date, $time, (int) $variant['duration_min'], (int) $variant['price'], $note,
                ]
            );
        } catch (PDOException $e) {
            /* کلید یکتای (date, time) روی نوبت‌های فعال — اگر دو نفر
               دقیقاً هم‌زمان ثبت کنند، دیتابیس دومی را رد می‌کند.
               بررسی بالا مسابقه‌ی زمانی را کم می‌کند؛ این‌جا حذفش
               می‌کند. */
            if ($e->getCode() === '23000') {
                Http::fail(
                    409,
                    'این بازه‌ی زمانی همین الان توسط شخص دیگری گرفته شد. لطفاً ساعت دیگری انتخاب کنید.',
                    'time'
                );
            }
            throw $e;
        }

        /* اطلاع‌رسانی — هیچ‌کدام نباید جلوی ثبت نوبت را بگیرد، پس
           تک‌تک داخل notifyAppointment مهار شده‌اند. قالبی که در
           تنظیمات خالی باشد اصلاً فرستاده نمی‌شود. */
        $row = Db::one('SELECT * FROM appointments WHERE id = ?', [$id]);
        if ($row) {
            /* ۱) رسید برای مشتری: درخواستت ثبت شد، منتظر تماس باش */
            Sms::notifyAppointment($row, 'received');

            /* ۲) خبر به سالن: یک درخواست تازه منتظر تعیین تکلیف است */
            foreach ((array) Config::get('admin_phones', []) as $adminPhone) {
                Sms::notifyAppointment($row, 'admin_alert', [
                    'name'  => ($user['name'] ?? '') !== '' ? (string) $user['name'] : 'بدون نام',
                    'phone' => (string) ($user['phone'] ?? ''),
                ], (string) $adminPhone);
            }
        }

        return self::publicOne($id);
    }

    /** لغو توسط خود مشتری */
    public static function cancel(array $user, string $id): array
    {
        $a = Db::one('SELECT * FROM appointments WHERE id = ?', [$id]);
        if (!$a) {
            Http::fail(404, 'نوبت پیدا نشد.');
        }
        if ($a['user_id'] !== $user['id']) {
            Http::fail(403, 'دسترسی مجاز نیست.');
        }
        if ($a['status'] === 'cancelled') {
            Http::fail(409, 'این نوبت قبلاً لغو شده است.');
        }
        if ($a['status'] === 'rejected') {
            Http::fail(409, 'این درخواست قبلاً رد شده است.');
        }

        /* درخواستی که هنوز تأیید نشده، هر وقت خواست قابل لغو است —
           محدودیت ۲۴ ساعته فقط برای نوبت‌های قطعی‌شده معنا دارد. */
        if ($a['status'] !== 'pending') {
            $hrs = (strtotime($a['date'] . ' ' . $a['time']) - time()) / 3600;
            $win = (int) Config::get('booking.cancel_window_hours', 24);
            if ($hrs < $win && $hrs > 0) {
                Http::fail(
                    409,
                    'لغو نوبت فقط تا ' . Jalali::fa($win) . ' ساعت قبل ممکن است. لطفاً تماس بگیرید.'
                );
            }
        }

        Db::run(
            'UPDATE appointments SET status = "cancelled", cancelled_at = NOW(),
                    cancelled_by = "user" WHERE id = ?',
            [$id]
        );

        return ['ok' => true, 'id' => $id];
    }

    /* ================= نمایش ================= */

    /** برچسب وضعیت — معادل appointments.statusLabel */
    public static function statusLabel(array $a): array
    {
        switch ($a['status']) {
            case 'pending':
                return ['در انتظار تأیید', 'badge--gold'];
            case 'rejected':
                return ['رد شده', 'badge--danger'];
            case 'cancelled':
                return ['لغو شده', 'badge--danger'];
            case 'no_show':
                return ['غیبت', 'badge--danger'];
            case 'done':
                return ['انجام شده', 'badge--muted'];
        }
        if (self::isPast($a['date'], $a['time'])) {
            return ['انجام شده', 'badge--muted'];
        }
        $hrs = (strtotime($a['date'] . ' ' . $a['time']) - time()) / 3600;
        if ($hrs < 24) {
            return ['به‌زودی', 'badge--gold'];
        }
        return ['تایید شده', 'badge--ok'];
    }

    /** یک نوبت با شناسه، در قالب عمومی */
    public static function publicOne(string $id, bool $withUser = false): array
    {
        $a = Db::one(
            'SELECT a.*, s.title AS service_title FROM appointments a
               LEFT JOIN services s ON s.id = a.service_id
              WHERE a.id = ?',
            [$id]
        );
        if (!$a) {
            Http::fail(404, 'نوبت پیدا نشد.');
        }
        return self::publicRow($a, $withUser);
    }

    /**
     * ردیف دیتابیس → قالبی که backend-bridge.js می‌فهمد.
     * نام کلیدها باید دقیقاً با mapAppointment یکی باشد.
     */
    public static function publicRow(array $a, bool $withUser = false): array
    {
        [$label, $cls] = self::statusLabel($a);
        $isPast = self::isPast($a['date'], $a['time']);

        $canCancel = !in_array($a['status'], ['cancelled', 'rejected', 'done', 'no_show'], true)
                     && !$isPast;
        if ($canCancel && $a['status'] !== 'pending') {
            $hrs = (strtotime($a['date'] . ' ' . $a['time']) - time()) / 3600;
            $win = (int) Config::get('booking.cancel_window_hours', 24);
            if ($hrs < $win) {
                $canCancel = false;
            }
        }

        $out = [
            'id'            => $a['id'],
            'service_id'    => $a['service_id'],
            'service_title' => $a['service_title'] ?? '',
            'variant_id'    => $a['variant_key'],
            'variant_name'  => $a['variant_name'],
            'date'          => $a['date'],
            'date_label'    => Jalali::long($a['date']),
            'time'          => $a['time'],
            'duration_min'  => (int) $a['duration_min'],
            'price'         => (int) $a['price'],
            'status'        => $a['status'],
            'status_label'  => $label,
            'status_class'  => $cls,
            'note'          => $a['note'] ?? '',
            'is_past'       => $isPast,
            'can_cancel'    => $canCancel,
            'created_at'    => strtotime((string) $a['created_at']) * 1000,
            /* رکورد بیعانه — تراکنش بانکی نیست، فقط ثبت چیزی است که
               مدیر حین تماس دریافت کرده. */
            'deposit'       => $a['deposit_amount']
                ? [
                    'amount' => (int) $a['deposit_amount'],
                    'method' => $a['deposit_method'],
                    'ref'    => $a['deposit_ref'] ?? '',
                    'note'   => $a['deposit_note'] ?? '',
                    'paidAt' => $a['deposit_paid_at']
                        ? strtotime((string) $a['deposit_paid_at']) * 1000 : null,
                ]
                : null,
            'reject_reason' => $a['reject_reason'] ?? null,
        ];

        if ($withUser) {
            $out['user'] = Auth::publicUser([
                'id'      => $a['user_id'],
                'phone'   => $a['phone'] ?? '',
                'name'    => $a['name'] ?? '',
                'birth_y' => $a['birth_y'] ?? null,
                'birth_m' => $a['birth_m'] ?? null,
                'birth_d' => $a['birth_d'] ?? null,
                'role'    => $a['role'] ?? 'user',
            ]);
        }

        return $out;
    }

    /** نوبت‌های یک کاربر، تفکیک‌شده */
    public static function forUser(string $userId): array
    {
        $rows = Db::all(
            'SELECT a.*, s.title AS service_title FROM appointments a
               LEFT JOIN services s ON s.id = a.service_id
              WHERE a.user_id = ?
              ORDER BY a.date, a.time',
            [$userId]
        );

        $upcoming = [];
        $past     = [];

        foreach ($rows as $r) {
            $pub    = self::publicRow($r);
            $closed = in_array($r['status'], ['cancelled', 'rejected'], true);
            if ($closed || $pub['is_past']) {
                $past[] = $pub;
            } else {
                $upcoming[] = $pub;
            }
        }

        $past = array_reverse($past);   // جدیدترین اول

        return ['upcoming' => $upcoming, 'past' => $past, 'total' => count($rows)];
    }

    /* ================= کمکی ================= */

    public static function isPast(string $dateKey, ?string $time = null): bool
    {
        $ts = $time
            ? strtotime($dateKey . ' ' . $time)
            : strtotime($dateKey . ' 23:59:59');
        return $ts < time();
    }

    public static function isValidKey(string $key): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $key)) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $key));
        return checkdate($m, $d, $y);
    }
}
