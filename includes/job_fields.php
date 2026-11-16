<?php

function job_fields(array $input): array
{
    $text = fn($key) => is_scalar($input[$key] ?? null) ? trim((string)$input[$key]) : '';
    $title = $text('title');
    $description = $text('description');
    $amount = $text('pay_amount');
    $type = $text('pay_type');
    $workers = filter_var($text('workers_needed'), FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
    $start = $text('start_date');
    $end = $text('end_date');
    if ($title === '' || mb_strlen($title) > 150 || $description === '' || mb_strlen($description) > 16000) {
        throw new DomainException('Enter a title of up to 150 characters and a description of up to 16,000 characters.');
    }
    if (($amount !== '' && !preg_match('/\A\d{1,8}(?:\.\d{1,2})?\z/', $amount))
        || !in_array($type, ['fixed','hourly','daily','negotiable'], true) || $workers === false) {
        throw new DomainException('Check the pay amount, pay unit, and number of workers.');
    }
    foreach ([$start, $end] as $date) {
        if ($date !== '' && (!preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $date, $parts)
            || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]))) {
            throw new DomainException('Enter valid start and end dates.');
        }
    }
    if ($start && $end && $end < $start) throw new DomainException('The end date must be on or after the start date.');
    return [$title, $description, (int)$text('category_id') ?: null, (int)$text('location_id') ?: null,
        $amount === '' ? null : $amount, $type, $workers, $start ?: null, $end ?: null];
}

function job_creation_key(mixed $value): string
{
    if (!is_string($value) || !preg_match('/\A[a-f0-9]{32}\z/', $value)) {
        throw new DomainException('Refresh the page before creating a listing.');
    }
    return $value;
}

