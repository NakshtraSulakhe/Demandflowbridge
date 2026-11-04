<?php
/**
 * DemandFlow Bridge - Form Submissions Dedicated API Endpoint
 * 
 * Fetches:
 * - Total forms submitted count
 * - Breakdown of who submitted them (by agent/form filler) with counts and lead references
 * - Recent submissions list (agent/lead id, form submission status, and timestamp)
 * - Shift interval timeline of form submissions
 */
$_GET['action'] = 'form_submissions';
require_once __DIR__ . '/../slides_analytics.php';

