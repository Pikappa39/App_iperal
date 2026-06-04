<?php
session_start();

$_SESSION = [];
session_destroy();

header("Location: /App_iperal-1/");
exit;