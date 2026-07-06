<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../session_bootstrap.php';
app_session_start();
require_once __DIR__ . '/../../../connection_files/connection.php';
require_once __DIR__ . '/../../../connection_files/push_lib.php';
require_once __DIR__ . '/../../../connection_files/schedule_adjustment_lib.php';
require_once __DIR__ . '/support/response.php';
require_once __DIR__ . '/permissions/adjustment_permissions.php';
require_once __DIR__ . '/support/formatters.php';
require_once __DIR__ . '/repositories/schedule_adjustment_repository.php';
require_once __DIR__ . '/repositories/extra_hour_repository.php';
