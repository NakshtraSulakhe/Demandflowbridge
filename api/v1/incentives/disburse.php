<?php
/**
 * DemandFlow Bridge CRM - Bank / UPI Payout Disbursement API
 * POST /api/v1/incentives/disburse.php
 * 
 * Note: Automatic incentive payment feature has been removed from this project.
 */

require_once __DIR__ . '/lib/bootstrap.php';

IncentiveResponse::error('Automated incentive payment feature has been removed from this project.', 'PAYMENT_FEATURE_REMOVED', 410);
