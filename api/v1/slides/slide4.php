<?php
/**
 * DemandFlow Bridge - Slide 4 Dedicated API Endpoint
 * 
 * Fetches:
 * - Agent Lead Generation Timewise Heatmap
 * - 10-Minute Intervals across shift
 * - Number of leads generated per Agent per interval
 */
$_GET['action'] = 'slide4';
require_once __DIR__ . '/../slides_analytics.php';
