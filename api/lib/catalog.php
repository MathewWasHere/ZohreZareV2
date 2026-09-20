<?php
/* ==========================================================================
   catalog.php — خدمات

   محتوای صفحه‌ی هر خدمت (متن‌ها، فهرست‌ها، پرسش‌ها) در ستون‌های JSON
   نگهداری می‌شود. چرا JSON و نه جدول جدا؟ چون این‌ها فقط فهرست‌های
   متنیِ نمایشی‌اند، هیچ‌وقت جداگانه جست‌وجو یا join نمی‌شوند، و
   نگه‌داشتنشان در یک ستون، ویرایش از پنل مدیریت را ساده می‌کند.
   ========================================================================== */

if (!defined('ZZ_APP')) {
    http_response_code(403);
    exit('دسترسی مستقیم مجاز نیست.');
}

final class Catalog
{
    /** فهرست خدمات فعال، همراه گزینه‌ها */
    public static function all(bool $includeInactive = false): array
    {
        $rows = Db::all(
            'SELECT * FROM services' . ($includeInactive ? '' : ' WHERE active = 1')
            . ' ORDER BY sort_order, title'
        );
        if (!$rows) {
            return [];
        }

        /* همه‌ی گزینه‌ها را یک‌جا می‌گیریم تا به ازای هر خدمت یک
           پرس‌وجوی جدا نزنیم. */
        $variants = [];
        foreach (Db::all('SELECT * FROM service_variants ORDER BY sort_order, id') as $v) {
            $variants[$v['service_id']][] = [
                'id'           => $v['variant_key'],
                'name'         => $v['name'],
                'note'         => $v['note'] ?? '',
                'duration_min' => (int) $v['duration_min'],
                'price'        => (int) $v['price'],
            ];
        }

        $out = [];
        foreach ($rows as $s) {
            $out[] = self::publicRow($s, $variants[$s['id']] ?? []);
        }
        return $out;
    }

    public static function bySlug(string $slug): ?array
    {
        $s = Db::one('SELECT * FROM services WHERE slug = ? AND active = 1', [$slug]);
        if (!$s) {
            return null;
        }
        $variants = [];
        foreach (Db::all(
            'SELECT * FROM service_variants WHERE service_id = ? ORDER BY sort_order, id',
            [$s['id']]
        ) as $v) {
            $variants[] = [
                'id'           => $v['variant_key'],
                'name'         => $v['name'],
                'note'         => $v['note'] ?? '',
                'duration_min' => (int) $v['duration_min'],
                'price'        => (int) $v['price'],
            ];
        }
        return self::publicRow($s, $variants);
    }

    /** ردیف → قالب mapService در backend-bridge.js */
    private static function publicRow(array $s, array $variants): array
    {
        return [
            'id'           => $s['id'],
            'slug'         => $s['slug'],
            'title'        => $s['title'],
            'short'        => $s['short_text'] ?? '',
            'image'        => $s['image'] ?? '',
            'icon'         => $s['icon'] ?? '',
            'ig_link'      => $s['ig_link'] ?: null,
            /* وضعیت فعال/غیرفعال — صفحه‌ی عمومی فقط خدمت‌های فعال را
               می‌گیرد (همیشه ۱)، اما پنل مدیریت فهرست کامل را می‌گیرد
               و باید بداند کدام غیرفعال است. */
            'is_active'    => (int) ($s['active'] ?? 1),
            'duration_min' => (int) $s['duration_min'],
            'price_from'   => (int) $s['price_from'],
            'variants'     => $variants,
            'description'  => self::json($s['description']),
            'includes'     => self::json($s['includes_json']),
            'aftercare'    => self::json($s['aftercare']),
            'good_for'     => self::json($s['good_for']),
            'faq'          => self::json($s['faq']),
        ];
    }

    /** ستون JSON → آرایه، با تحمل مقدار خراب */
    private static function json($raw): array
    {
        if (!$raw) {
            return [];
        }
        $v = json_decode((string) $raw, true);
        return is_array($v) ? $v : [];
    }

    /**
     * ویرایش خدمت از پنل مدیریت.
     * فقط فیلدهایی که فرستاده شده‌اند تغییر می‌کنند.
     */
    public static function update(string $id, array $in): array
    {
        $s = Db::one('SELECT * FROM services WHERE id = ?', [$id]);
        if (!$s) {
            Http::fail(404, 'خدمت پیدا نشد.');
        }

        $sets   = [];
        $params = [];

        $textFields = [
            'title'   => 'title',
            'short'   => 'short_text',
            'image'   => 'image',
            'icon'    => 'icon',
            'ig_link' => 'ig_link',
        ];
        foreach ($textFields as $key => $col) {
            if (array_key_exists($key, $in)) {
                $sets[]   = $col . ' = ?';
                $params[] = mb_substr(trim((string) $in[$key]), 0, 500, 'UTF-8');
            }
        }

        foreach (['duration_min', 'price_from'] as $col) {
            if (array_key_exists($col, $in)) {
                $sets[]   = $col . ' = ?';
                $params[] = max(0, (int) preg_replace('/\D/', '', Jalali::en((string) $in[$col])));
            }
        }

        $listFields = [
            'description' => 'description',
            'includes'    => 'includes_json',
            'aftercare'   => 'aftercare',
            'good_for'    => 'good_for',
            'faq'         => 'faq',
        ];
        foreach ($listFields as $key => $col) {
            if (array_key_exists($key, $in) && is_array($in[$key])) {
                $sets[]   = $col . ' = ?';
                $params[] = json_encode($in[$key], JSON_UNESCAPED_UNICODE);
            }
        }

        if (array_key_exists('active', $in)) {
            $sets[]   = 'active = ?';
            $params[] = $in['active'] ? 1 : 0;
        }

        if ($sets) {
            $params[] = $id;
            Db::run('UPDATE services SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
        }

        /* گزینه‌ها (قیمت و مدت) — کل فهرست جایگزین می‌شود */
        if (isset($in['variants']) && is_array($in['variants'])) {
            foreach ($in['variants'] as $v) {
                if (!isset($v['id'])) {
                    continue;
                }
                Db::run(
                    'UPDATE service_variants
                        SET name = COALESCE(?, name),
                            note = COALESCE(?, note),
                            duration_min = COALESCE(?, duration_min),
                            price = COALESCE(?, price)
                      WHERE service_id = ? AND variant_key = ?',
                    [
                        isset($v['name']) ? mb_substr((string) $v['name'], 0, 120, 'UTF-8') : null,
                        isset($v['note']) ? mb_substr((string) $v['note'], 0, 300, 'UTF-8') : null,
                        isset($v['duration_min'])
                            ? max(0, (int) preg_replace('/\D/', '', Jalali::en((string) $v['duration_min'])))
                            : null,
                        isset($v['price'])
                            ? max(0, (int) preg_replace('/\D/', '', Jalali::en((string) $v['price'])))
                            : null,
                        $id,
                        (string) $v['id'],
                    ]
                );
            }
        }

        /* «از … تومان» روی کارت‌ها باید همیشه با ارزان‌ترین گزینه
           بخواند، وگرنه قیمت نمایش‌داده‌شده با واقعیت فرق می‌کند. */
        $min = Db::val('SELECT MIN(price) FROM service_variants WHERE service_id = ?', [$id]);
        if ($min !== null && !array_key_exists('price_from', $in)) {
            Db::run('UPDATE services SET price_from = ? WHERE id = ?', [(int) $min, $id]);
        }

        /* ردیف تازه را همیشه برگردان — حتی اگر همین الان غیرفعال شده
           باشد (bySlug فقط خدمت‌های فعال را برمی‌گرداند و پنل بعد از
           غیرفعال‌ کردن نباید پاسخ خالی بگیرد). */
        $variants = [];
        foreach (Db::all(
            'SELECT * FROM service_variants WHERE service_id = ? ORDER BY sort_order, id',
            [$id]
        ) as $v) {
            $variants[] = [
                'id'           => $v['variant_key'],
                'name'         => $v['name'],
                'note'         => $v['note'] ?? '',
                'duration_min' => (int) $v['duration_min'],
                'price'        => (int) $v['price'],
            ];
        }
        return self::publicRow((Db::one('SELECT * FROM services WHERE id = ?', [$id]) ?: $s), $variants);
    }
}
