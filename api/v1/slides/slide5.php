<?php
/**
 * DemandFlow Bridge - Slide 5 Dedicated API Endpoint
 * 
 * Fetches:
 * - Comparative Analytics (Agent Generation vs Quality Auditing & Qualification over time)
 * - Interval-by-interval counts and backlog lag
 * - Shift cumulative progression metrics
 */
$_GET['action'] = 'slide5';
require_once __DIR__ . '/../slides_analytics.php';
