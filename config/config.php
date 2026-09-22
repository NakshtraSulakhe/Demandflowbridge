<?php
/**
 * Environment Configuration
 * 
 * This file contains environment-specific settings.
 * For production, update these values as needed.
 */

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'leads');
define('DB_USER', 'root');
define('DB_PASS', '');

// Application Configuration
define('APP_NAME', 'DemandFlow Bridge');
define('APP_URL', 'https://app.demandflowbridge.com'); // Base URL without trailing slash

// Production Hardening
define('APP_DEBUG', true); // Set to true for development, false for production
define('APP_LOG_ERRORS', true);
define('APP_DISPLAY_ERRORS', true);

// Security Settings
define('SESSION_SECURE', false); // Set to true if using HTTPS
define('SESSION_HTTPONLY', true);
