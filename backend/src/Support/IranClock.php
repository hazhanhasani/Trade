<?php

declare(strict_types=1);

namespace Trade\Support;

/**
 * Canonical presentation clock for Trade.
 *
 * Database and exchange timestamps remain UTC. All user-facing date/time
 * conversion is done here using Asia/Tehran and the Solar Hijri calendar.
 */
final class IranClock
{
    private const TZ = 'Asia/Tehran';

    public static function timezone(): \DateTimeZone
    {
        static $tz;
        return $tz ??= new \DateTimeZone(self::TZ);
    }

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', self::timezone());
    }

    public static function nowPayload(): array
    {
        $now = self::now();
        return self::payload($now);
    }

    public static function fromUtc(string $utc): array
    {
        $utc = trim($utc);
        if ($utc === '') return self::nowPayload();
        try {
            $dt = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return self::nowPayload();
        }
        return self::payload($dt->setTimezone(self::timezone()));
    }

    public static function formatUtc(string $utc, bool $seconds = true): string
    {
        $p = self::fromUtc($utc);
        return $p[$seconds ? 'jalali_datetime' : 'jalali_datetime_minute'];
    }

    public static function formatNow(bool $seconds = true): string
    {
        $p = self::nowPayload();
        return $p[$seconds ? 'jalali_datetime' : 'jalali_datetime_minute'];
    }

    /** @return array{0:string,1:string} UTC SQL datetime boundaries for current Tehran day. */
    public static function todayUtcRange(): array
    {
        $start = self::now()->setTime(0, 0, 0);
        $end = $start->modify('+1 day');
        $utc = new \DateTimeZone('UTC');
        return [
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $end->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    public static function tehranDateFromUtc(string $utc): string
    {
        try {
            return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))
                ->setTimezone(self::timezone())
                ->format('Y-m-d');
        } catch (\Throwable) {
            return self::now()->format('Y-m-d');
        }
    }

    public static function jalaliDateFromUtc(string $utc): string
    {
        $p = self::fromUtc($utc);
        return $p['jalali_date'];
    }

    public static function payload(\DateTimeInterface $dt): array
    {
        $tehran = \DateTimeImmutable::createFromInterface($dt)->setTimezone(self::timezone());
        [$jy, $jm, $jd] = self::gregorianToJalali(
            (int)$tehran->format('Y'),
            (int)$tehran->format('n'),
            (int)$tehran->format('j')
        );
        $date = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
        $time = $tehran->format('H:i:s');
        $timeMinute = $tehran->format('H:i');
        $weekday = self::weekdayFa((int)$tehran->format('w'));

        return [
            'timezone'=>self::TZ,
            'gregorian_iso'=>$tehran->format(DATE_ATOM),
            'jalali_date'=>$date,
            'jalali_time'=>$time,
            'jalali_time_minute'=>$timeMinute,
            'jalali_datetime'=>$date . ' ' . $time,
            'jalali_datetime_minute'=>$date . ' ' . $timeMinute,
            'jalali_weekday'=>$weekday,
            'jalali_human'=>$weekday . ' ' . $date . '، ' . $time,
            'unix'=>$tehran->getTimestamp(),
        ];
    }

    public static function weekdayFa(int $w): string
    {
        return match ($w) {
            0=>'یکشنبه', 1=>'دوشنبه', 2=>'سه‌شنبه', 3=>'چهارشنبه',
            4=>'پنجشنبه', 5=>'جمعه', 6=>'شنبه', default=>'—',
        };
    }

    /**
     * Integer-only Gregorian -> Solar Hijri conversion.
     * Adapted from the widely used jalaali-js/Borkowski algorithm family.
     * No locale or system calendar extension is required on shared hosting.
     *
     * @return array{0:int,1:int,2:int}
     */
    public static function gregorianToJalali(int $gy, int $gm, int $gd): array
    {
        $gdm = [0,31,59,90,120,151,181,212,243,273,304,334];
        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = 355666 + (365 * $gy)
            + intdiv($gy2 + 3, 4)
            - intdiv($gy2 + 99, 100)
            + intdiv($gy2 + 399, 400)
            + $gd + $gdm[$gm - 1];

        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }
        return [$jy, $jm, $jd];
    }
}
