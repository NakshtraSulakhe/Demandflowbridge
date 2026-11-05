<?php
/**
 * DemandFlow Bridge - QA Team Daily Audits & Status Breakdown API
 * Endpoint: GET /api/v1/slides/qa_audits.php
 * 
 * Provides real-time feeds for the Slides App & Incentive Calculation Engine:
 * - Quality Team Members (ID, full name, email, department, role)
 * - Audited Leads Breakdown with QA statuses (Qualified, Disqualified, Pending, Rework Needed, etc.)
 * - Client Code & Campaign hierarchy breakdown (campaign_id, campaign_name, client_code)
 * - Volume rates (qualification rate %, disqualification rate %)
 * - Live Payout Simulation (Base Target, Base Reward, Tier Slab Accelerators)
 * 
 * Query Parameters:
 * - date: 'YYYY-MM-DD' (defaults to today's date in local operational timezone)
 * - date_from: 'YYYY-MM-DD' (start date for custom or monthly range)
 * - date_to: 'YYYY-MM-DD' (end date for custom or monthly range)
 * - frequency: 'daily' | 'monthly' | 'custom' (default: 'daily')
 * - qa_id / user_id: Specific QA analyst ID (optional)
 * - client_code: Client account filter e.g. 'CL-FIN', 'CL-SAAS' (optional)
 * - campaign_id: Specific campaign ID filter (optional)
 * - status: 'Qualified' | 'Disqualified' | 'Pending' (optional)
 * - include_leads: 1 | 0 (default: 1, whether to include individual lead items)
 * - base_target: int (e.g. 50, for live incentive calculation)
 * - base_reward: float (e.g. 500.0, for live incentive calculation)
 * - tiers: JSON array or string (e.g. [{"min":51,"max":60,"rate":15},{"min":61,"max":70,"rate":20}])
 */

$_GET['action'] = 'qa_audits';
require_once __DIR__ . '/../slides_analytics.php';

