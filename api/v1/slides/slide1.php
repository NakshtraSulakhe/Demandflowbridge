<?php
/**
 * DemandFlow Bridge - Slide 1 Dedicated API Endpoint
 * 
 * Fetches:
 * - 4 Attendance KPI Cards (Total Agents, Present Today, Absent Today, Late Comers)
 * - Live Campaign-wise Table (Daily Target, Generated, Qualified, Disqualified, Pending QA, Remaining Leads)
 * - Late Comers List (Name, punch in, late minutes, status)
 */
$_GET['action'] = 'slide1';
require_once __DIR__ . '/../slides_analytics.php';
