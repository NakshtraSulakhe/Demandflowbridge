<?php
/**
 * DemandFlow Bridge CRM - Incentive API Authorization & Role Enforcement
 */

class IncentiveAuthZ {
    private const APPROVAL_ROLES = [
        'admin',
        'director',
        'manager_director',
        'operations_director',
        'operations_manager',
        'qa_manager',
        'qa_director',
    ];

    private const DISBURSE_ROLES = [
        'admin',
        'director',
        'manager_director',
        'operations_director',
    ];

    /**
     * Checks if actor can approve daily incentives
     */
    public static function canApprove(array $user): bool {
        $role = strtolower(trim((string)($user['role'] ?? '')));
        return in_array($role, self::APPROVAL_ROLES, true);
    }

    /**
     * Checks if actor can trigger bank/UPI disbursements
     */
    public static function canDisburse(array $user): bool {
        $role = strtolower(trim((string)($user['role'] ?? '')));
        return in_array($role, self::DISBURSE_ROLES, true);
    }

    /**
     * Checks if actor can trigger settlements
     */
    public static function canManageSettlement(array $user): bool {
        $role = strtolower(trim((string)($user['role'] ?? '')));
        return in_array($role, self::APPROVAL_ROLES, true);
    }

    /**
     * Separation of duties check: An approver cannot approve their own incentive
     */
    public static function canApproveAgent(array $user, int $agentId): bool {
        if (!self::canApprove($user)) return false;
        $userId = (int)($user['id'] ?? 0);
        // User cannot approve their own payout
        if ($userId > 0 && $userId === $agentId) {
            return false;
        }
        return true;
    }

    public static function enforceCanApprove(array $user): void {
        if (!self::canApprove($user)) {
            IncentiveResponse::forbidden('Only authorized Operations or QA Managers can approve incentive payouts.');
        }
    }

    public static function enforceCanDisburse(array $user): void {
        if (!self::canDisburse($user)) {
            IncentiveResponse::forbidden('Only Operations Directors or Admin can authorize payment disbursements.');
        }
    }

    public static function enforceCanManageSettlement(array $user): void {
        if (!self::canManageSettlement($user)) {
            IncentiveResponse::forbidden('Only authorized Operations Managers or Admin can trigger settlements.');
        }
    }
}
