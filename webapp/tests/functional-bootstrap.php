<?php

$autoloadPath = getenv('FUNCTIONAL_VENDOR_AUTOLOAD');

if (!$autoloadPath) {
    $autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';
}

if (!is_file($autoloadPath)) {
    throw new RuntimeException('Functional test autoload file not found: ' . $autoloadPath);
}

require_once $autoloadPath;