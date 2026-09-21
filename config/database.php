<?php
/**
 * Database Configuration
 * 
 * This file contains the database connection settings
 */

// Load environment configuration
require_once __DIR__ . '/config.php';

// Configure error reporting based on environment
if (defined('APP_DEBUG') && APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

if (defined('APP_LOG_ERRORS') && APP_LOG_ERRORS) {
    ini_set('log_errors', 1);
    ini_set('error_log', dirname(__DIR__) . '/tmp/error.log');
}

// Create connection
function getDbConnection() {
    static $conn;
    
    if ($conn === null) {
        try {
            mysqli_report(MYSQLI_REPORT_OFF);
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            
            // Check connection
            if ($conn->connect_error) {
                throw new Exception("Connection failed: " . $conn->connect_error);
            }
            
            $conn->set_charset("utf8mb4");
            
        } catch (Exception $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            die("Database connection failed. Please contact the administrator.");
        }
    }
    
    return $conn;
}
