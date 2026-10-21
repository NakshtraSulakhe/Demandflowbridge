<?php
/**
 * DemandFlow Bridge - Slide 2 Dedicated API Endpoint
 * 
 * Fetches:
 * - Agent-wise Daily Target in Points
 * - Generated Leads, Qualified, Disqualified, Pending QA
 * - Achieved Points & Remaining to be Generated
 * - Built-in pagination support for rotating carousels (?page=1&limit=10 or ?limit=all)
 */
$_GET['action'] = 'slide2';
require_once __DIR__ . '/../slides_analytics.php';
