<?php
/**
 * DemandFlow Bridge - Slide 3 Dedicated API Endpoint
 * 
 * Fetches:
 * - Quality Team Timewise Heatmap
 * - 10-Minute Intervals across shift (configurable via ?interval=10 or 5)
 * - Number of leads qualified and audited per Quality Analyst per interval
 */
$_GET['action'] = 'slide3';
require_once __DIR__ . '/../slides_analytics.php';
