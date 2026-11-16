<?php
function job_search(PDO $pdo, array $input): array
{
    $text = fn($key) => is_scalar($input[$key] ?? null) ? trim((string)$input[$key]) : '';
    $filters = [
        'q' => mb_substr($text('q'), 0, 120),
        'category' => max(0, (int)$text('category')),
        'location' => max(0, (int)$text('location')),
        'minpay' => max(0, (int)$text('minpay')),
        'today' => (int)($text('today') === '1'),
        'sort' => $text('sort'),
    ];
    $order = match ($filters['sort']) {
        'soonest' => 'start_date IS NULL, start_date ASC, id DESC',
        'pay' => 'pay_amount IS NULL, pay_amount DESC, id DESC',
        default => 'created_at DESC, id DESC',
    };
    if (!in_array($filters['sort'], ['soonest','pay'], true)) $filters['sort'] = '';
    $where = " WHERE status IN ('open','filled')";
    $values = [];
    if ($filters['q'] !== '') {
        $term = '%' . strtr($filters['q'], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $where .= " AND (title LIKE ? ESCAPE '!' OR description LIKE ? ESCAPE '!' OR business_name LIKE ? ESCAPE '!')";
        $values = [$term, $term, $term];
    }
    foreach (['category' => 'category_id', 'location' => 'location_id', 'minpay' => 'pay_amount'] as $key => $field) {
        if (!$filters[$key]) continue;
        $where .= ' AND ' . $field . ($key === 'minpay' ? '>=?' : '=?');
        $values[] = $filters[$key];
    }
    if ($filters['today']) $where .= ' AND start_date=CURDATE()';
    $count = $pdo->prepare('SELECT COUNT(*) FROM v_jobs_public' . $where);
    $count->execute($values);
    $total = (int)$count->fetchColumn();
    $per = min(50, max(1, $text('per') === '' ? 20 : (int)$text('per')));
    $page = min(max(1, (int)$text('p')), max(1, (int)ceil($total / $per)));
    $offset = ($page - 1) * $per;
    $query = $pdo->prepare("SELECT v.*, CASE WHEN status='open' THEN GREATEST(0,workers_needed-occupied_count)
        ELSE 0 END AS remaining_slots FROM v_jobs_public v $where ORDER BY $order LIMIT $per OFFSET $offset");
    $query->execute($values);
    return ['jobs' => $query->fetchAll(), 'total' => $total, 'page' => $page, 'per' => $per,
        'filters' => array_filter($filters, fn($value) => $value !== '' && $value !== 0)];
}

function job_pay(array $job): string
{
    if ($job['pay_amount'] === null) return $job['pay_type'] === 'negotiable' ? 'Pay negotiable' : 'Pay on request';
    $unit = match ($job['pay_type']) {
        'hourly' => ' / hour',
        'daily' => ' / day',
        'negotiable' => ' · negotiable',
        default => ' / gig',
    };
    return money($job['pay_amount']) . $unit;
}

