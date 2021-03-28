<?php

use Doctrine\DBAL\DriverManager;

/**
 * @file
 * Creates a test schema on the Oracle database.
 */

// Store the Drupal root path.
$root_path = realpath('');

// Initialize the autoloader.
$class_loader = require_once $root_path . '/autoload.php';

// Create Oracle database
$tmpConnection = DriverManager::getConnection(["url" => "pdo-oci://system:oracle@0.0.0.0:1521/XE"]);
$tmpConnection->exec('CREATE USER DRUDBAL IDENTIFIED BY ORACLE');
$tmpConnection->exec('GRANT DBA TO DRUDBAL');
$tmpConnection->close();
