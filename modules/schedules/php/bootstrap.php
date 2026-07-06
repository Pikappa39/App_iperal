<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../session_bootstrap.php';
app_session_start();
require_once __DIR__ . '/../../../connection_files/connection.php';
require_once __DIR__ . '/shared/schedule_adjustment_lib.php';
require_once __DIR__ . '/support/response.php';
require_once __DIR__ . '/support/weeks.php';
