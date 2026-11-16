<?php
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/../includes/job_search.php';
json_out(['ok' => true] + job_search($pdo, $_GET));

