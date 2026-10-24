<?php
declare(strict_types=1);
define('IS_API', true);
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/common.inc.php';

json_out(chart_payload(load_filters()));
