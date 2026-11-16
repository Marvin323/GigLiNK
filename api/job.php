<?php
require __DIR__ . '/_bootstrap.php';
$id = (int)($_GET['id'] ?? 0);
if (!$id) json_err('id required');

$s = $pdo->prepare("SELECT * FROM v_jobs_public WHERE id = ?");
$s->execute([$id]);
$job = $s->fetch();
if (!$job) json_err('not found', 404);

$e = $pdo->prepare("SELECT user_id, business_name, business_type, bio, location_name, avg_rating, rating_count, verified, photo_path FROM v_employer_summary WHERE user_id = ?");
$e->execute([$job['employer_id']]);
$employer = $e->fetch();

json_out(['ok' => true, 'job' => $job, 'employer' => $employer]);
