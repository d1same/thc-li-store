<?php
declare(strict_types=1);

namespace App;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeZone;

final class ReportingService
{
    public static function report(array $query): array
    {
        $range = self::range($query);
        $params = [$range['start_utc'], $range['end_utc']];
        $sales = Database::all(
            "SELECT o.*,u.name staff_name FROM orders o LEFT JOIN users u ON u.id=o.created_by_user_id
             WHERE o.status='completed' AND o.payment_status='paid' AND o.created_at>=? AND o.created_at<? ORDER BY o.created_at",
            $params
        );
        $history = Database::one(
            "SELECT COUNT(*) total_orders,
                    SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) cancelled_orders,
                    COALESCE(SUM(CASE WHEN status='cancelled' THEN total_cents ELSE 0 END),0) cancelled_cents
             FROM orders WHERE created_at>=? AND created_at<?",
            $params
        ) ?? ['total_orders' => 0, 'cancelled_orders' => 0, 'cancelled_cents' => 0];
        $items = Database::all(
            "SELECT oi.*,o.created_at,o.order_source,o.payment_method,o.created_by_user_id,
                    COALESCE(c.name,'Other') category_name,COALESCE(u.name,'Online') staff_name
             FROM order_items oi
             JOIN orders o ON o.id=oi.order_id
             LEFT JOIN products p ON p.id=oi.product_id
             LEFT JOIN categories c ON c.id=p.category_id
             LEFT JOIN users u ON u.id=o.created_by_user_id
             WHERE o.status='completed' AND o.payment_status='paid' AND o.created_at>=? AND o.created_at<?",
            $params
        );

        $revenue = array_sum(array_map(static fn(array $sale): int => (int) $sale['total_cents'], $sales));
        $subtotal = array_sum(array_map(static fn(array $sale): int => (int) $sale['subtotal_cents'], $sales));
        $discounts = array_sum(array_map(static fn(array $sale): int => (int) $sale['discount_cents'], $sales));
        $tax = array_sum(array_map(static fn(array $sale): int => (int) $sale['tax_cents'], $sales));
        $fees = array_sum(array_map(static fn(array $sale): int => (int) $sale['fee_cents'], $sales));
        $units = array_sum(array_map(static fn(array $item): int => (int) $item['quantity'], $items));
        $orderCount = count($sales);

        $newCustomers = (int) (Database::one(
            'SELECT COUNT(*) count FROM customer_profiles WHERE first_seen_at>=? AND first_seen_at<?',
            $params
        )['count'] ?? 0);

        return [
            'range' => $range,
            'kpis' => [
                'revenue' => $revenue,
                'subtotal' => $subtotal,
                'orders' => $orderCount,
                'units' => $units,
                'average' => $orderCount ? (int) round($revenue / $orderCount) : 0,
                'discounts' => $discounts,
                'tax' => $tax,
                'fees' => $fees,
                'cancelled_orders' => (int) $history['cancelled_orders'],
                'cancelled_cents' => (int) $history['cancelled_cents'],
                'all_orders' => (int) $history['total_orders'],
                'new_customers' => $newCustomers,
            ],
            'trend' => self::trend($sales, $range),
            'top_products' => self::rank($items, static fn(array $item): string => $item['product_name']),
            'top_variants' => self::rank($items, static fn(array $item): string => $item['product_name'] . ' · ' . $item['variant_label']),
            'top_categories' => self::rank($items, static fn(array $item): string => $item['category_name']),
            'payments' => self::breakdown($sales, static fn(array $sale): string => self::friendly((string) $sale['payment_method'])),
            'sources' => self::breakdown($sales, static fn(array $sale): string => ($sale['order_source'] ?? 'online') === 'pos' ? 'POS' : 'Online'),
            'staff' => self::breakdown($sales, static fn(array $sale): string => $sale['staff_name'] ?: 'Online'),
            'busy_hours' => self::busyHours($sales, $range['timezone']),
            'busy_days' => self::busyDays($sales, $range['timezone']),
        ];
    }

    public static function range(array $query): array
    {
        $timezone = self::timezone();
        $now = new DateTimeImmutable('now', $timezone);
        $key = in_array($query['range'] ?? '', ['today', 'yesterday', '7d', '30d', 'month', 'custom'], true)
            ? (string) $query['range'] : '30d';
        $today = $now->setTime(0, 0);
        $start = $today;
        $end = $today->modify('+1 day');
        $label = 'Today';

        if ($key === 'yesterday') {
            $start = $today->modify('-1 day');
            $end = $today;
            $label = 'Yesterday';
        } elseif ($key === '7d') {
            $start = $today->modify('-6 days');
            $label = 'Last 7 days';
        } elseif ($key === '30d') {
            $start = $today->modify('-29 days');
            $label = 'Last 30 days';
        } elseif ($key === 'month') {
            $start = $today->modify('first day of this month');
            $label = 'This month';
        } elseif ($key === 'custom') {
            $from = self::date((string) ($query['from'] ?? ''), $timezone);
            $to = self::date((string) ($query['to'] ?? ''), $timezone);
            if ($from && $to && $from <= $to) {
                $start = $from;
                $end = $to->modify('+1 day');
                $label = $start->format('M j, Y') . ' – ' . $to->format('M j, Y');
            } else {
                $key = '30d';
                $start = $today->modify('-29 days');
                $label = 'Last 30 days';
            }
        }

        $utc = new DateTimeZone('UTC');
        $days = max(1, (int) $start->diff($end)->days);
        return [
            'key' => $key,
            'label' => $label,
            'start_local' => $start,
            'end_local' => $end,
            'start_utc' => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            'end_utc' => $end->setTimezone($utc)->format('Y-m-d H:i:s'),
            'from' => $start->format('Y-m-d'),
            'to' => $end->modify('-1 day')->format('Y-m-d'),
            'days' => $days,
            'timezone' => $timezone,
            'timezone_name' => $timezone->getName(),
        ];
    }

    private static function trend(array $sales, array $range): array
    {
        $points = [];
        if ($range['days'] <= 2) {
            for ($hour = 0; $hour < 24; $hour++) {
                $points[sprintf('%02d', $hour)] = ['label' => (new DateTimeImmutable("{$hour}:00", $range['timezone']))->format('ga'), 'revenue' => 0, 'orders' => 0];
            }
            foreach ($sales as $sale) {
                $local = self::local((string) $sale['created_at'], $range['timezone']);
                $key = $local->format('H');
                $points[$key]['revenue'] += (int) $sale['total_cents'];
                $points[$key]['orders']++;
            }
        } else {
            $period = new DatePeriod($range['start_local'], new DateInterval('P1D'), $range['end_local']);
            foreach ($period as $date) {
                $key = $date->format('Y-m-d');
                $points[$key] = ['label' => $date->format($range['days'] > 45 ? 'M j' : 'D j'), 'revenue' => 0, 'orders' => 0];
            }
            foreach ($sales as $sale) {
                $key = self::local((string) $sale['created_at'], $range['timezone'])->format('Y-m-d');
                if (!isset($points[$key])) {
                    continue;
                }
                $points[$key]['revenue'] += (int) $sale['total_cents'];
                $points[$key]['orders']++;
            }
        }
        $max = max(1, ...array_column($points, 'revenue'));
        foreach ($points as &$point) {
            $point['percent'] = (int) round(((int) $point['revenue'] / $max) * 100);
        }
        unset($point);
        return array_values($points);
    }

    private static function rank(array $items, callable $key): array
    {
        $rows = [];
        foreach ($items as $item) {
            $name = $key($item);
            $rows[$name] ??= ['label' => $name, 'units' => 0, 'revenue' => 0];
            $rows[$name]['units'] += (int) $item['quantity'];
            $rows[$name]['revenue'] += (int) $item['line_total_cents'];
        }
        usort($rows, static fn(array $a, array $b): int => $b['revenue'] <=> $a['revenue']);
        return array_slice($rows, 0, 8);
    }

    private static function breakdown(array $sales, callable $key): array
    {
        $rows = [];
        foreach ($sales as $sale) {
            $name = $key($sale);
            $rows[$name] ??= ['label' => $name, 'orders' => 0, 'revenue' => 0];
            $rows[$name]['orders']++;
            $rows[$name]['revenue'] += (int) $sale['total_cents'];
        }
        usort($rows, static fn(array $a, array $b): int => $b['revenue'] <=> $a['revenue']);
        $total = max(1, array_sum(array_column($rows, 'revenue')));
        foreach ($rows as &$row) {
            $row['percent'] = (int) round(($row['revenue'] / $total) * 100);
        }
        unset($row);
        return $rows;
    }

    private static function busyHours(array $sales, DateTimeZone $timezone): array
    {
        $hours = array_fill(0, 24, 0);
        foreach ($sales as $sale) {
            $hours[(int) self::local((string) $sale['created_at'], $timezone)->format('G')]++;
        }
        arsort($hours);
        $rows = [];
        foreach (array_slice($hours, 0, 5, true) as $hour => $orders) {
            if ($orders === 0) continue;
            $rows[] = ['label' => (new DateTimeImmutable("{$hour}:00", $timezone))->format('g A'), 'orders' => $orders];
        }
        return $rows;
    }

    private static function busyDays(array $sales, DateTimeZone $timezone): array
    {
        $days = array_fill_keys(['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'], 0);
        foreach ($sales as $sale) {
            $days[self::local((string) $sale['created_at'], $timezone)->format('l')]++;
        }
        arsort($days);
        return array_map(static fn(string $label, int $orders): array => ['label' => $label, 'orders' => $orders], array_keys($days), array_values($days));
    }

    private static function timezone(): DateTimeZone
    {
        $name = (string) Store::setting('report_timezone', 'America/New_York');
        try {
            return new DateTimeZone($name);
        } catch (\Throwable) {
            return new DateTimeZone('America/New_York');
        }
    }

    private static function date(string $value, DateTimeZone $timezone): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    private static function local(string $value, DateTimeZone $timezone): DateTimeImmutable
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone($timezone);
    }

    private static function friendly(string $value): string
    {
        return ucwords(str_replace('_', ' ', $value === 'external_card' ? 'external terminal' : $value));
    }
}
