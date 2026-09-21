<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin','director','manager_director','sales_director','operations_director']);
ensureCsrfToken();
ensureDatabaseSchema();

$user = getCurrentUser();
$conn = getDbConnection();

$parseInvoiceMonth = static function (string $value): ?array {
    $value = trim($value);
    if (!preg_match('/^(\d{4})-(\d{2})$/', $value, $m)) {
        return null;
    }
    $year = (int)$m[1];
    $month = (int)$m[2];
    if ($month < 1 || $month > 12) {
        return null;
    }
    $start = sprintf('%04d-%02d-01', $year, $month);
    return [
        'month' => $value,
        'start' => $start,
        'end' => date('Y-m-t', strtotime($start)),
        'start_dt' => $start . ' 00:00:00',
        'end_dt' => date('Y-m-t', strtotime($start)) . ' 23:59:59',
    ];
};

$monthMeta = $parseInvoiceMonth(isset($_GET['month']) ? (string)$_GET['month'] : date('Y-m'));
if (!$monthMeta) {
    http_response_code(400);
    echo 'Invalid month';
    exit;
}
$monthStr = (string)$monthMeta['month'];
$start = (string)$monthMeta['start'];
$end = (string)$monthMeta['end'];
$startDt = (string)$monthMeta['start_dt'];
$endDt = (string)$monthMeta['end_dt'];

$prefCampaignId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : 0;
$statusFilter = isset($_GET['status']) ? trim((string)$_GET['status']) : 'All';
$q = trim((string)($_GET['q'] ?? ''));
$allowedStatuses = ['All','Draft','Sent','Paid','Cancelled'];
if (!in_array($statusFilter, $allowedStatuses, true)) $statusFilter = 'All';

$message = '';
$messageType = 'success';

$makeInvoiceNo = function(string $monthStr) use ($conn): string {
    $prefix = 'INV-' . str_replace('-', '', $monthStr) . '-';
    $stmt = $conn->prepare("SELECT invoice_no FROM revenue_invoices WHERE invoice_no LIKE ? ORDER BY id DESC LIMIT 1");
    $like = $prefix . '%';
    $lastNo = '';
    if ($stmt) {
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $lastNo = (string)(($stmt->get_result()->fetch_assoc() ?: [])['invoice_no'] ?? '');
        $stmt->close();
    }
    $n = 1;
    if ($lastNo !== '' && substr($lastNo, 0, strlen($prefix)) === $prefix) {
        $tail = substr($lastNo, strlen($prefix));
        if (preg_match('/^\d+$/', $tail)) $n = ((int)$tail) + 1;
    }
    return $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
};

$loadInvoiceDefaults = static function(int $userId) use ($conn): ?array {
    if ($userId <= 0) {
        return null;
    }
    $stmt = $conn->prepare("SELECT * FROM revenue_invoice_settings WHERE user_id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
};

$resolveClientByCode = static function(string $clientCode) use ($conn): array {
    $clientCode = trim($clientCode);
    if ($clientCode === '') {
        return ['id' => 0, 'name' => ''];
    }
    $stmt = $conn->prepare("SELECT id, name FROM clients WHERE client_code = ? LIMIT 1");
    if (!$stmt) {
        return ['id' => 0, 'name' => ''];
    }
    $stmt->bind_param('s', $clientCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return [
        'id' => (int)($row['id'] ?? 0),
        'name' => trim((string)($row['name'] ?? '')),
    ];
};

$loadBillToProfile = static function(int $userId, string $clientCode) use ($conn): ?array {
    $clientCode = trim($clientCode);
    if ($userId <= 0 || $clientCode === '') {
        return null;
    }
    $stmt = $conn->prepare("
        SELECT bill_to_name, bill_to_address, bill_to_contacts
        FROM revenue_invoice_billto_profiles
        WHERE user_id = ? AND client_code = ?
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('is', $userId, $clientCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
};

$buildClientContactsText = static function(int $clientId) use ($conn): string {
    if ($clientId <= 0) {
        return '';
    }
    $names = [];
    $emails = [];
    $phones = [];
    $stmt = $conn->prepare("SELECT name, email, phone FROM client_contacts WHERE client_id = ? ORDER BY id ASC LIMIT 3");
    if ($stmt) {
        $stmt->bind_param('i', $clientId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        foreach ($rows as $row) {
            $name = trim((string)($row['name'] ?? ''));
            $email = trim((string)($row['email'] ?? ''));
            $phone = trim((string)($row['phone'] ?? ''));
            if ($name !== '') $names[] = $name;
            if ($email !== '') $emails[] = $email;
            if ($phone !== '') $phones[] = $phone;
        }
    }
    $lines = [];
    if (!empty($emails)) $lines[] = 'Email: ' . implode(' / ', $emails);
    if (!empty($phones)) $lines[] = 'Phone: ' . implode(' / ', $phones);
    if (!empty($names)) $lines[] = 'Name: ' . implode(' / ', $names);
    return implode("\n", $lines);
};

$loadPrimaryClientContact = static function(int $clientId) use ($conn): array {
    if ($clientId <= 0) {
        return ['name' => '', 'email' => '', 'phone' => ''];
    }
    $stmt = $conn->prepare("
        SELECT name, email, phone
        FROM client_contacts
        WHERE client_id = ?
        ORDER BY id ASC
        LIMIT 1
    ");
    if (!$stmt) {
        return ['name' => '', 'email' => '', 'phone' => ''];
    }
    $stmt->bind_param('i', $clientId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return [
        'name' => trim((string)($row['name'] ?? '')),
        'email' => trim((string)($row['email'] ?? '')),
        'phone' => trim((string)($row['phone'] ?? '')),
    ];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = 'Invalid token.';
        $messageType = 'danger';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'delete_invoice') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $message = 'Invalid invoice.';
                $messageType = 'danger';
            } else {
                $st = $conn->prepare("SELECT status FROM revenue_invoices WHERE id = ? LIMIT 1");
                $status = '';
                if ($st) {
                    $st->bind_param('i', $id);
                    $st->execute();
                    $status = (string)(($st->get_result()->fetch_assoc() ?: [])['status'] ?? '');
                    $st->close();
                }
                if ($status === 'Paid') {
                    $message = 'Paid invoice cannot be deleted.';
                    $messageType = 'danger';
                } else {
                    $delItems = $conn->prepare("DELETE FROM revenue_invoice_items WHERE invoice_id = ?");
                    if ($delItems) {
                        $delItems->bind_param('i', $id);
                        $delItems->execute();
                        $delItems->close();
                    }
                    $delInv = $conn->prepare("DELETE FROM revenue_invoices WHERE id = ? LIMIT 1");
                    if ($delInv) {
                        $delInv->bind_param('i', $id);
                        $ok = $delInv->execute();
                        $delInv->close();
                        $message = $ok ? 'Invoice deleted.' : 'Unable to delete invoice.';
                        $messageType = $ok ? 'success' : 'danger';
                    } else {
                        $message = 'Database error.';
                        $messageType = 'danger';
                    }
                }
            }
        } elseif ($action === 'update_status') {
            $id = (int)($_POST['id'] ?? 0);
            $newStatus = trim((string)($_POST['new_status'] ?? ''));
            $allowed = ['Draft','Sent','Paid','Cancelled'];
            if ($id <= 0 || !in_array($newStatus, $allowed, true)) {
                $message = 'Invalid request.';
                $messageType = 'danger';
            } else {
                $stmt = $conn->prepare("UPDATE revenue_invoices SET status = ?, updated_by = ?, updated_at = NOW(), paid_at = CASE WHEN ? = 'Paid' THEN COALESCE(paid_at, NOW()) ELSE NULL END WHERE id = ? LIMIT 1");
                if ($stmt) {
                    $actorId = (int)($user['id'] ?? 0);
                    $stmt->bind_param('sisi', $newStatus, $actorId, $newStatus, $id);
                    $ok = $stmt->execute();
                    $stmt->close();
                    $message = $ok ? 'Status updated.' : 'Unable to update status.';
                    $messageType = $ok ? 'success' : 'danger';
                    if ($ok) {
                        $st2 = $conn->prepare("SELECT invoice_no, created_by FROM revenue_invoices WHERE id = ? LIMIT 1");
                        $invNo = '';
                        $createdBy = 0;
                        if ($st2) {
                            $st2->bind_param('i', $id);
                            $st2->execute();
                            $row = $st2->get_result()->fetch_assoc() ?: null;
                            $st2->close();
                            if ($row) {
                                $invNo = (string)($row['invoice_no'] ?? '');
                                $createdBy = (int)($row['created_by'] ?? 0);
                            }
                        }
                        if ($createdBy > 0 && $createdBy !== (int)($user['id'] ?? 0)) {
                            $ttl = 'Invoice status updated';
                            $msg = ($invNo !== '' ? $invNo : ('Invoice #' . $id)) . ' · Status: ' . $newStatus;
                            $link = 'invoice-edit?id=' . $id;
                            createNotificationSmart($createdBy, 'invoice.status_changed', $ttl, $msg, $link, [
                                'importance' => $newStatus === 'Paid' ? 'high' : 'normal',
                                'show_toast' => $newStatus === 'Paid',
                                'dedup_key' => 'inv_status:' . $id . ':' . $newStatus,
                                'dedup_window_min' => 1440,
                            ]);
                        }
                        if (function_exists('syncSalesCommissionForInvoice')) {
                            syncSalesCommissionForInvoice($id, (int)($user['id'] ?? 0));
                        }
                    }
                } else {
                    $message = 'Database error.';
                    $messageType = 'danger';
                }
            }
        } elseif ($action === 'bulk_delete' || $action === 'bulk_copy' || $action === 'bulk_status') {
            $idsRaw = trim((string)($_POST['ids'] ?? ''));
            $ids = array_values(array_filter(array_map('intval', preg_split('/\s*,\s*/', $idsRaw) ?: []), fn($v) => $v > 0));
            $ids = array_values(array_unique($ids));
            if (empty($ids)) {
                $message = 'Select at least one invoice.';
                $messageType = 'danger';
            } else {
                if ($action === 'bulk_status') {
                    $newStatus = trim((string)($_POST['new_status'] ?? ''));
                    $allowed = ['Draft','Sent','Paid','Cancelled'];
                    if (!in_array($newStatus, $allowed, true)) {
                        $message = 'Invalid status.';
                        $messageType = 'danger';
                    } else {
                        $stmt = $conn->prepare("UPDATE revenue_invoices SET status = ?, updated_by = ?, updated_at = NOW(), paid_at = CASE WHEN ? = 'Paid' THEN COALESCE(paid_at, NOW()) ELSE NULL END WHERE id = ?");
                        $updated = 0;
                        if ($stmt) {
                            $actorId = (int)($user['id'] ?? 0);
                            foreach ($ids as $id) {
                                $stmt->bind_param('sisi', $newStatus, $actorId, $newStatus, $id);
                                if ($stmt->execute()) {
                                    $updated++;
                                    if (function_exists('syncSalesCommissionForInvoice')) {
                                        syncSalesCommissionForInvoice((int)$id, (int)($user['id'] ?? 0));
                                    }
                                }
                            }
                            $stmt->close();
                        }
                        $message = 'Updated: ' . $updated;
                        $messageType = 'success';
                    }
                } elseif ($action === 'bulk_delete') {
                    $deleted = 0;
                    $skipped = 0;
                    foreach ($ids as $id) {
                        $st = $conn->prepare("SELECT status FROM revenue_invoices WHERE id = ? LIMIT 1");
                        $status = '';
                        if ($st) {
                            $st->bind_param('i', $id);
                            $st->execute();
                            $status = (string)(($st->get_result()->fetch_assoc() ?: [])['status'] ?? '');
                            $st->close();
                        }
                        if ($status === 'Paid') {
                            $skipped++;
                            continue;
                        }
                        $delItems = $conn->prepare("DELETE FROM revenue_invoice_items WHERE invoice_id = ?");
                        if ($delItems) {
                            $delItems->bind_param('i', $id);
                            $delItems->execute();
                            $delItems->close();
                        }
                        $delInv = $conn->prepare("DELETE FROM revenue_invoices WHERE id = ? LIMIT 1");
                        if ($delInv) {
                            $delInv->bind_param('i', $id);
                            if ($delInv->execute()) $deleted++;
                            $delInv->close();
                        }
                    }
                    $message = 'Deleted: ' . $deleted . ($skipped > 0 ? (' · Skipped paid: ' . $skipped) : '');
                    $messageType = $deleted > 0 ? 'success' : 'danger';
                } elseif ($action === 'bulk_copy') {
                    $copied = 0;
                    foreach ($ids as $srcId) {
                        $st = $conn->prepare("SELECT * FROM revenue_invoices WHERE id = ? LIMIT 1");
                        $src = null;
                        if ($st) {
                            $st->bind_param('i', $srcId);
                            $st->execute();
                            $src = $st->get_result()->fetch_assoc() ?: null;
                            $st->close();
                        }
                        if (!$src) continue;

                        $items = [];
                        $st = $conn->prepare("SELECT description, qty, unit_price, amount, sort_order FROM revenue_invoice_items WHERE invoice_id = ? ORDER BY sort_order, id");
                        if ($st) {
                            $st->bind_param('i', $srcId);
                            $st->execute();
                            $items = $st->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                            $st->close();
                        }

                        $issueDate = date('Y-m-d');
                        $invoiceNo = ((string)(getAppSetting('invoice.numbering.mode', 'sequence') ?? 'sequence') === 'legacy')
                            ? $makeInvoiceNo((string)($src['month_str'] ?? $monthStr))
                            : nextInvoiceNumber($issueDate);
                        $dueDate = date('Y-m-d', strtotime($issueDate . ' +15 days'));
                        $currency = (string)($src['currency'] ?? 'USD');
                        $clientIdVal = (int)($src['client_id'] ?? 0);
                        $clientCode = (string)($src['client_code'] ?? '');
                        $clientName = (string)($src['client_name'] ?? '');
                        $campaignIdVal = (int)($src['campaign_id'] ?? 0);
                        $monthVal = (string)($src['month_str'] ?? $monthStr);
                        $createdBy = (int)($user['id'] ?? 0);
                        $status = 'Draft';

                        $billToName = (string)($src['bill_to_name'] ?? '');
                        $billToAddress = (string)($src['bill_to_address'] ?? '');
                        $billToContactName = (string)($src['bill_to_contact_name'] ?? '');
                        $billToContactEmail = (string)($src['bill_to_contact_email'] ?? '');
                        $billToContactPhone = (string)($src['bill_to_contact_phone'] ?? '');
                        $billToContacts = (string)($src['bill_to_contacts'] ?? '');

                        $billFromName = (string)($src['bill_from_name'] ?? '');
                        $billFromAddress = (string)($src['bill_from_address'] ?? '');
                        $billFromCityState = (string)($src['bill_from_city_state'] ?? '');
                        $billFromCountry = (string)($src['bill_from_country'] ?? '');
                        $billFromEmail = (string)($src['bill_from_email'] ?? '');
                        $billFromPhone = (string)($src['bill_from_phone'] ?? '');

                        $bankName = (string)($src['bank_name'] ?? '');
                        $accountName = (string)($src['account_name'] ?? '');
                        $accountNumber = (string)($src['account_number'] ?? '');
                        $ifscCode = (string)($src['ifsc_code'] ?? '');
                        $swiftCode = (string)($src['swift_code'] ?? '');
                        $beneficiaryAddress = (string)($src['beneficiary_address'] ?? '');
                        $beneficiaryCityState = (string)($src['beneficiary_city_state'] ?? '');
                        $signaturePath = (string)($src['signature_path'] ?? '');
                        $salesOwnerIdVal = (int)($src['sales_owner_id'] ?? 0);
                        $salesManagerIdVal = (int)($src['sales_manager_id'] ?? 0);
                        if ($salesOwnerIdVal <= 0 && $clientIdVal > 0 && function_exists('getSalesOwnerForClient')) {
                            $own = getSalesOwnerForClient($clientIdVal);
                            $salesOwnerIdVal = (int)($own['owner_id'] ?? 0);
                            $salesManagerIdVal = (int)($own['manager_id'] ?? 0);
                        }

                        $notes = (string)($src['notes'] ?? '');
                        $taxRate = (float)($src['tax_rate'] ?? 0);

                        $subtotal = 0.0;
                        foreach ($items as $it) $subtotal += (float)($it['amount'] ?? 0);
                        $taxAmount = round(($subtotal * $taxRate) / 100.0, 2);
                        $total = $subtotal + $taxAmount;

                        $stmt = $conn->prepare("
                            INSERT INTO revenue_invoices
                            (invoice_no, status, issue_date, due_date, currency, client_id, client_code, client_name,
                             bill_to_name, bill_to_address, bill_to_contact_name, bill_to_contact_email, bill_to_contact_phone, bill_to_contacts,
                             bill_from_name, bill_from_address, bill_from_city_state, bill_from_country, bill_from_email, bill_from_phone,
                             bank_name, account_name, account_number, ifsc_code, swift_code, beneficiary_address, beneficiary_city_state, signature_path,
                             campaign_id, month_str, sales_owner_id, sales_manager_id, notes, subtotal, tax_rate, tax_amount, total, created_by, created_at)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                        ");
                        if ($stmt) {
                            $types = 'sssss' . 'i' . str_repeat('s', 22) . 'is' . 'ii' . 's' . 'dddd' . 'i';
                            $stmt->bind_param(
                                $types,
                                $invoiceNo,
                                $status,
                                $issueDate,
                                $dueDate,
                                $currency,
                                $clientIdVal,
                                $clientCode,
                                $clientName,
                                $billToName,
                                $billToAddress,
                                $billToContactName,
                                $billToContactEmail,
                                $billToContactPhone,
                                $billToContacts,
                                $billFromName,
                                $billFromAddress,
                                $billFromCityState,
                                $billFromCountry,
                                $billFromEmail,
                                $billFromPhone,
                                $bankName,
                                $accountName,
                                $accountNumber,
                                $ifscCode,
                                $swiftCode,
                                $beneficiaryAddress,
                                $beneficiaryCityState,
                                $signaturePath,
                                $campaignIdVal,
                                $monthVal,
                                $salesOwnerIdVal,
                                $salesManagerIdVal,
                                $notes,
                                $subtotal,
                                $taxRate,
                                $taxAmount,
                                $total,
                                $createdBy
                            );
                            $ok = $stmt->execute();
                            $newId = (int)$conn->insert_id;
                            $stmt->close();
                            if ($ok && $newId > 0) {
                                $ins = $conn->prepare("INSERT INTO revenue_invoice_items (invoice_id, description, qty, unit_price, amount, sort_order) VALUES (?,?,?,?,?,?)");
                                if ($ins) {
                                    foreach ($items as $it) {
                                        $desc = (string)($it['description'] ?? '');
                                        $qty = (float)($it['qty'] ?? 1);
                                        $rate = (float)($it['unit_price'] ?? 0);
                                        $amt = (float)($it['amount'] ?? ($qty * $rate));
                                        $ord = (int)($it['sort_order'] ?? 0);
                                        $ins->bind_param('isdddi', $newId, $desc, $qty, $rate, $amt, $ord);
                                        $ins->execute();
                                    }
                                    $ins->close();
                                }
                                $copied++;
                                if (function_exists('syncSalesCommissionForInvoice')) {
                                    syncSalesCommissionForInvoice($newId, (int)($user['id'] ?? 0));
                                }
                            }
                        }
                    }
                    $message = 'Copied: ' . $copied;
                    $messageType = $copied > 0 ? 'success' : 'danger';
                }
            }
        } elseif ($action === 'copy_invoice') {
            $srcId = (int)($_POST['id'] ?? 0);
            if ($srcId <= 0) {
                $message = 'Invalid invoice.';
                $messageType = 'danger';
            } else {
                $st = $conn->prepare("SELECT * FROM revenue_invoices WHERE id = ? LIMIT 1");
                $src = null;
                if ($st) {
                    $st->bind_param('i', $srcId);
                    $st->execute();
                    $src = $st->get_result()->fetch_assoc() ?: null;
                    $st->close();
                }
                if (!$src) {
                    $message = 'Invoice not found.';
                    $messageType = 'danger';
                } else {
                    $items = [];
                    $st = $conn->prepare("SELECT description, qty, unit_price, amount, sort_order FROM revenue_invoice_items WHERE invoice_id = ? ORDER BY sort_order, id");
                    if ($st) {
                        $st->bind_param('i', $srcId);
                        $st->execute();
                        $items = $st->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                        $st->close();
                    }

                    $issueDate = date('Y-m-d');
                    $invoiceNo = ((string)(getAppSetting('invoice.numbering.mode', 'sequence') ?? 'sequence') === 'legacy')
                        ? $makeInvoiceNo((string)($src['month_str'] ?? $monthStr))
                        : nextInvoiceNumber($issueDate);
                    $dueDate = date('Y-m-d', strtotime($issueDate . ' +15 days'));
                    $currency = (string)($src['currency'] ?? 'USD');
                    $clientId = (int)($src['client_id'] ?? 0);
                    $clientCode = (string)($src['client_code'] ?? '');
                    $clientName = (string)($src['client_name'] ?? '');
                    $campaignId = (int)($src['campaign_id'] ?? 0);
                    $monthVal = (string)($src['month_str'] ?? $monthStr);
                    $createdBy = (int)($user['id'] ?? 0);

                    $billToName = (string)($src['bill_to_name'] ?? '');
                    $billToAddress = (string)($src['bill_to_address'] ?? '');
                    $billToContactName = (string)($src['bill_to_contact_name'] ?? '');
                    $billToContactEmail = (string)($src['bill_to_contact_email'] ?? '');
                    $billToContactPhone = (string)($src['bill_to_contact_phone'] ?? '');
                    $billToContacts = (string)($src['bill_to_contacts'] ?? '');

                    $billFromName = (string)($src['bill_from_name'] ?? '');
                    $billFromAddress = (string)($src['bill_from_address'] ?? '');
                    $billFromCityState = (string)($src['bill_from_city_state'] ?? '');
                    $billFromCountry = (string)($src['bill_from_country'] ?? '');
                    $billFromEmail = (string)($src['bill_from_email'] ?? '');
                    $billFromPhone = (string)($src['bill_from_phone'] ?? '');

                    $bankName = (string)($src['bank_name'] ?? '');
                    $accountName = (string)($src['account_name'] ?? '');
                    $accountNumber = (string)($src['account_number'] ?? '');
                    $ifscCode = (string)($src['ifsc_code'] ?? '');
                    $swiftCode = (string)($src['swift_code'] ?? '');
                    $beneficiaryAddress = (string)($src['beneficiary_address'] ?? '');
                    $beneficiaryCityState = (string)($src['beneficiary_city_state'] ?? '');
                    $signaturePath = (string)($src['signature_path'] ?? '');
                    $salesOwnerIdVal = (int)($src['sales_owner_id'] ?? 0);
                    $salesManagerIdVal = (int)($src['sales_manager_id'] ?? 0);
                    if ($salesOwnerIdVal <= 0 && $clientId > 0 && function_exists('getSalesOwnerForClient')) {
                        $own = getSalesOwnerForClient((int)$clientId);
                        $salesOwnerIdVal = (int)($own['owner_id'] ?? 0);
                        $salesManagerIdVal = (int)($own['manager_id'] ?? 0);
                    }

                    $notes = (string)($src['notes'] ?? '');
                    $taxRate = (float)($src['tax_rate'] ?? 0);
                    $status = 'Draft';

                    $subtotal = 0.0;
                    foreach ($items as $it) {
                        $subtotal += (float)($it['amount'] ?? 0);
                    }
                    $taxAmount = round(($subtotal * $taxRate) / 100.0, 2);
                    $total = $subtotal + $taxAmount;

                    $stmt = $conn->prepare("
                        INSERT INTO revenue_invoices
                        (invoice_no, status, issue_date, due_date, currency, client_id, client_code, client_name,
                         bill_to_name, bill_to_address, bill_to_contact_name, bill_to_contact_email, bill_to_contact_phone, bill_to_contacts,
                         bill_from_name, bill_from_address, bill_from_city_state, bill_from_country, bill_from_email, bill_from_phone,
                         bank_name, account_name, account_number, ifsc_code, swift_code, beneficiary_address, beneficiary_city_state, signature_path,
                         campaign_id, month_str, sales_owner_id, sales_manager_id, notes, subtotal, tax_rate, tax_amount, total, created_by, created_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                    ");
                    if ($stmt) {
                        $clientIdVal = (int)$clientId;
                        $campaignIdVal = (int)$campaignId;
                        $types = 'sssss' . 'i' . str_repeat('s', 22) . 'is' . 'ii' . 's' . 'dddd' . 'i';
                        $stmt->bind_param(
                            $types,
                            $invoiceNo,
                            $status,
                            $issueDate,
                            $dueDate,
                            $currency,
                            $clientIdVal,
                            $clientCode,
                            $clientName,
                            $billToName,
                            $billToAddress,
                            $billToContactName,
                            $billToContactEmail,
                            $billToContactPhone,
                            $billToContacts,
                            $billFromName,
                            $billFromAddress,
                            $billFromCityState,
                            $billFromCountry,
                            $billFromEmail,
                            $billFromPhone,
                            $bankName,
                            $accountName,
                            $accountNumber,
                            $ifscCode,
                            $swiftCode,
                            $beneficiaryAddress,
                            $beneficiaryCityState,
                            $signaturePath,
                            $campaignIdVal,
                            $monthVal,
                            $salesOwnerIdVal,
                            $salesManagerIdVal,
                            $notes,
                            $subtotal,
                            $taxRate,
                            $taxAmount,
                            $total,
                            $createdBy
                        );
                        $ok = $stmt->execute();
                        $newId = (int)$conn->insert_id;
                        $stmt->close();
                        if ($ok && $newId > 0) {
                            $ins = $conn->prepare("INSERT INTO revenue_invoice_items (invoice_id, description, qty, unit_price, amount, sort_order) VALUES (?,?,?,?,?,?)");
                            if ($ins) {
                                foreach ($items as $it) {
                                    $desc = (string)($it['description'] ?? '');
                                    $qty = (float)($it['qty'] ?? 1);
                                    $rate = (float)($it['unit_price'] ?? 0);
                                    $amt = (float)($it['amount'] ?? ($qty * $rate));
                                    $ord = (int)($it['sort_order'] ?? 0);
                                    $ins->bind_param('isdddi', $newId, $desc, $qty, $rate, $amt, $ord);
                                    $ins->execute();
                                }
                                $ins->close();
                            }
                            createNotificationSmart($createdBy, 'invoice.created', 'Invoice created', $invoiceNo . ' created as a copy.', 'invoice-edit?id=' . $newId, [
                                'dedup_key' => 'inv_created:' . $newId,
                                'dedup_window_min' => 1440,
                            ]);
                            if (function_exists('syncSalesCommissionForInvoice')) {
                                syncSalesCommissionForInvoice($newId, (int)($user['id'] ?? 0));
                            }
                            header('Location: invoice-edit?id=' . $newId);
                            exit;
                        }
                        $message = 'Unable to copy invoice.';
                        $messageType = 'danger';
                    } else {
                        $message = 'Database error.';
                        $messageType = 'danger';
                    }
                }
            }
        } elseif ($action === 'create_invoice_from_campaign' || $action === 'create_invoice_from_client_code') {
            $createMonthMeta = $parseInvoiceMonth((string)($_POST['month'] ?? $monthStr));
            if (!$createMonthMeta) {
                $message = 'Select a valid month.';
                $messageType = 'danger';
            } else {
                $createMonthStr = (string)$createMonthMeta['month'];
                $createStartDt = (string)$createMonthMeta['start_dt'];
                $createEndDt = (string)$createMonthMeta['end_dt'];
                $uid = (int)($user['id'] ?? 0);
                $defaults = $loadInvoiceDefaults($uid);
                $createdBy = (int)($user['id'] ?? 0);

                $invoiceItems = [];
                $clientCode = '';
                $clientIdVal = 0;
                $clientNameVal = '';
                $campaignIdVal = 0;
                $currency = 'USD';
                $notes = '';
                $successToast = '';

                if ($action === 'create_invoice_from_campaign') {
                    $campaignId = (int)($_POST['campaign_id'] ?? 0);
                    if ($campaignId <= 0) {
                        $message = 'Select a campaign.';
                        $messageType = 'danger';
                    } else {
                        $stmt = $conn->prepare("
                            SELECT c.id, c.name, d.client_code, d.cpl, d.cpl_currency,
                                   COUNT(l.id) AS billable
                            FROM campaigns c
                            LEFT JOIN campaign_details d ON d.campaign_id = c.id
                            LEFT JOIN leads l ON l.campaign_id = c.id
                                AND l.client_delivery_status = 'Accepted'
                                AND l.created_at BETWEEN ? AND ?
                            WHERE c.id = ?
                            GROUP BY c.id, c.name, d.client_code, d.cpl, d.cpl_currency
                            LIMIT 1
                        ");
                        $info = null;
                        if ($stmt) {
                            $stmt->bind_param('ssi', $createStartDt, $createEndDt, $campaignId);
                            $stmt->execute();
                            $info = $stmt->get_result()->fetch_assoc() ?: null;
                            $stmt->close();
                        }
                        if (!$info) {
                            $message = 'Campaign not found.';
                            $messageType = 'danger';
                        } else {
                            $billable = (int)($info['billable'] ?? 0);
                            $cpl = (float)($info['cpl'] ?? 0);
                            $currency = trim((string)($info['cpl_currency'] ?? 'USD'));
                            if ($currency === '') $currency = 'USD';
                            if ($billable <= 0 || $cpl <= 0) {
                                $message = 'No billable leads with CPL found for this campaign in the selected month.';
                                $messageType = 'danger';
                            } else {
                                $campaignIdVal = $campaignId;
                                $clientCode = trim((string)($info['client_code'] ?? ''));
                                $clientMeta = $resolveClientByCode($clientCode);
                                $clientIdVal = (int)($clientMeta['id'] ?? 0);
                                $clientNameVal = (string)($clientMeta['name'] ?? '');
                                $invoiceItems[] = [
                                    'description' => 'Accepted (Billable) Leads - ' . (string)($info['name'] ?? '') . ' (' . $createMonthStr . ')',
                                    'qty' => (float)$billable,
                                    'unit_price' => (float)$cpl,
                                    'amount' => (float)$billable * (float)$cpl,
                                    'sort_order' => 0,
                                ];
                                $notes = 'Generated from accepted billable leads for ' . $createMonthStr . '.';
                                $successToast = ' created from campaign.';
                            }
                        }
                    }
                } else {
                    $clientCode = trim((string)($_POST['client_code'] ?? ''));
                    if ($clientCode === '') {
                        $message = 'Select a client code.';
                        $messageType = 'danger';
                    } else {
                        $stmt = $conn->prepare("
                            SELECT c.id, c.name, d.client_code,
                                   COALESCE(NULLIF(TRIM(d.cpl_currency), ''), 'USD') AS cpl_currency,
                                   COALESCE(d.cpl, 0) AS cpl,
                                   COUNT(l.id) AS billable
                            FROM campaigns c
                            LEFT JOIN campaign_details d ON d.campaign_id = c.id
                            LEFT JOIN leads l ON l.campaign_id = c.id
                                AND l.client_delivery_status = 'Accepted'
                                AND l.created_at BETWEEN ? AND ?
                            WHERE d.client_code = ?
                            GROUP BY c.id, c.name, d.client_code, d.cpl_currency, d.cpl
                            HAVING COUNT(l.id) > 0 AND COALESCE(d.cpl, 0) > 0
                            ORDER BY c.name
                        ");
                        $campaignRows = [];
                        if ($stmt) {
                            $stmt->bind_param('sss', $createStartDt, $createEndDt, $clientCode);
                            $stmt->execute();
                            $campaignRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                            $stmt->close();
                        }
                        if (empty($campaignRows)) {
                            $message = 'No billable campaigns with CPL found for this client code in the selected month.';
                            $messageType = 'danger';
                        } else {
                            $currencySet = [];
                            foreach ($campaignRows as $index => $row) {
                                $rowCurrency = trim((string)($row['cpl_currency'] ?? 'USD'));
                                if ($rowCurrency === '') $rowCurrency = 'USD';
                                $currencySet[strtoupper($rowCurrency)] = true;
                                $billable = (int)($row['billable'] ?? 0);
                                $cpl = (float)($row['cpl'] ?? 0);
                                $invoiceItems[] = [
                                    'description' => 'Accepted (Billable) Leads - ' . (string)($row['name'] ?? '') . ' (' . $createMonthStr . ')',
                                    'qty' => (float)$billable,
                                    'unit_price' => $cpl,
                                    'amount' => $billable * $cpl,
                                    'sort_order' => $index,
                                ];
                            }
                            if (count($currencySet) > 1) {
                                $message = 'Combined client-code invoice requires the same currency across all billable campaigns.';
                                $messageType = 'danger';
                                $invoiceItems = [];
                            } else {
                                $currency = (string)array_key_first($currencySet);
                                $clientMeta = $resolveClientByCode($clientCode);
                                $clientIdVal = (int)($clientMeta['id'] ?? 0);
                                $clientNameVal = (string)($clientMeta['name'] ?? '');
                                $notes = 'Combined client-code invoice generated from accepted billable leads for ' . $createMonthStr . '.';
                                $successToast = ' created from client code.';
                            }
                        }
                    }
                }

                if ($message === '' && !empty($invoiceItems)) {
                    $invoiceNo = ((string)(getAppSetting('invoice.numbering.mode', 'sequence') ?? 'sequence') === 'legacy')
                        ? $makeInvoiceNo($createMonthStr)
                        : nextInvoiceNumber(date('Y-m-d'));
                    $issueDate = date('Y-m-d');
                    $dueDate = date('Y-m-d', strtotime($issueDate . ' +15 days'));
                    $subtotal = 0.0;
                    foreach ($invoiceItems as $item) {
                        $subtotal += (float)($item['amount'] ?? 0);
                    }
                    $taxRate = 0.0;
                    $taxAmount = 0.0;
                    $total = $subtotal;

                    $billToName = $clientNameVal;
                    $billToAddress = '';
                    $billToContacts = '';
                    $profile = $loadBillToProfile($uid, $clientCode);
                    if ($profile) {
                        $billToName = (string)($profile['bill_to_name'] ?? $billToName);
                        $billToAddress = (string)($profile['bill_to_address'] ?? '');
                        $billToContacts = (string)($profile['bill_to_contacts'] ?? '');
                    }
                    if ($billToContacts === '' && $clientIdVal > 0) {
                        $billToContacts = $buildClientContactsText($clientIdVal);
                    }
                    $primaryContact = $loadPrimaryClientContact($clientIdVal);
                    $billToContactName = (string)($primaryContact['name'] ?? '');
                    $billToContactEmail = (string)($primaryContact['email'] ?? '');
                    $billToContactPhone = (string)($primaryContact['phone'] ?? '');

                    $billFromName = is_array($defaults) ? (string)($defaults['bill_from_name'] ?? '') : '';
                    $billFromAddress = is_array($defaults) ? (string)($defaults['bill_from_address'] ?? '') : '';
                    $billFromCityState = is_array($defaults) ? (string)($defaults['bill_from_city_state'] ?? '') : '';
                    $billFromCountry = is_array($defaults) ? (string)($defaults['bill_from_country'] ?? '') : '';
                    $billFromEmail = is_array($defaults) ? (string)($defaults['bill_from_email'] ?? '') : '';
                    $billFromPhone = is_array($defaults) ? (string)($defaults['bill_from_phone'] ?? '') : '';
                    $bankName = is_array($defaults) ? (string)($defaults['bank_name'] ?? '') : '';
                    $accountName = is_array($defaults) ? (string)($defaults['account_name'] ?? '') : '';
                    $accountNumber = is_array($defaults) ? (string)($defaults['account_number'] ?? '') : '';
                    $ifscCode = is_array($defaults) ? (string)($defaults['ifsc_code'] ?? '') : '';
                    $swiftCode = is_array($defaults) ? (string)($defaults['swift_code'] ?? '') : '';
                    $beneficiaryAddress = is_array($defaults) ? (string)($defaults['beneficiary_address'] ?? '') : '';
                    $beneficiaryCityState = is_array($defaults) ? (string)($defaults['beneficiary_city_state'] ?? '') : '';
                    $signaturePath = is_array($defaults) ? (string)($defaults['signature_path'] ?? '') : '';

                    $salesOwnerIdVal = 0;
                    $salesManagerIdVal = 0;
                    if ($clientIdVal > 0 && function_exists('getSalesOwnerForClient')) {
                        $owner = getSalesOwnerForClient($clientIdVal);
                        $salesOwnerIdVal = (int)($owner['owner_id'] ?? 0);
                        $salesManagerIdVal = (int)($owner['manager_id'] ?? 0);
                    }

                    $stmt = $conn->prepare("
                        INSERT INTO revenue_invoices
                        (invoice_no, status, issue_date, due_date, currency, client_id, client_code, client_name,
                         bill_to_name, bill_to_address, bill_to_contact_name, bill_to_contact_email, bill_to_contact_phone, bill_to_contacts,
                         bill_from_name, bill_from_address, bill_from_city_state, bill_from_country, bill_from_email, bill_from_phone,
                         bank_name, account_name, account_number, ifsc_code, swift_code, beneficiary_address, beneficiary_city_state, signature_path,
                         campaign_id, month_str, sales_owner_id, sales_manager_id, notes, subtotal, tax_rate, tax_amount, total, created_by, created_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                    ");
                    if ($stmt) {
                        $status = 'Draft';
                        $types = 'sssss' . 'i' . str_repeat('s', 22) . 'is' . 'ii' . 's' . 'dddd' . 'i';
                        $stmt->bind_param(
                            $types,
                            $invoiceNo,
                            $status,
                            $issueDate,
                            $dueDate,
                            $currency,
                            $clientIdVal,
                            $clientCode,
                            $clientNameVal,
                            $billToName,
                            $billToAddress,
                            $billToContactName,
                            $billToContactEmail,
                            $billToContactPhone,
                            $billToContacts,
                            $billFromName,
                            $billFromAddress,
                            $billFromCityState,
                            $billFromCountry,
                            $billFromEmail,
                            $billFromPhone,
                            $bankName,
                            $accountName,
                            $accountNumber,
                            $ifscCode,
                            $swiftCode,
                            $beneficiaryAddress,
                            $beneficiaryCityState,
                            $signaturePath,
                            $campaignIdVal,
                            $createMonthStr,
                            $salesOwnerIdVal,
                            $salesManagerIdVal,
                            $notes,
                            $subtotal,
                            $taxRate,
                            $taxAmount,
                            $total,
                            $createdBy
                        );
                        $ok = $stmt->execute();
                        $newId = (int)$conn->insert_id;
                        $stmt->close();
                        if ($ok && $newId > 0) {
                            $ins = $conn->prepare("INSERT INTO revenue_invoice_items (invoice_id, description, qty, unit_price, amount, sort_order) VALUES (?,?,?,?,?,?)");
                            if ($ins) {
                                foreach ($invoiceItems as $item) {
                                    $description = (string)($item['description'] ?? '');
                                    $qty = (float)($item['qty'] ?? 0);
                                    $unitPrice = (float)($item['unit_price'] ?? 0);
                                    $amount = (float)($item['amount'] ?? 0);
                                    $sortOrder = (int)($item['sort_order'] ?? 0);
                                    $ins->bind_param('isdddi', $newId, $description, $qty, $unitPrice, $amount, $sortOrder);
                                    $ins->execute();
                                }
                                $ins->close();
                            }
                            createNotificationSmart($createdBy, 'invoice.created', 'Invoice created', $invoiceNo . $successToast, 'invoice-edit?id=' . $newId, [
                                'dedup_key' => 'inv_created:' . $newId,
                                'dedup_window_min' => 1440,
                            ]);
                            if (function_exists('syncSalesCommissionForInvoice')) {
                                syncSalesCommissionForInvoice($newId, (int)($user['id'] ?? 0));
                            }
                            header('Location: invoice-edit?id=' . $newId);
                            exit;
                        }
                        $message = 'Failed to create invoice.';
                        $messageType = 'danger';
                    } else {
                        $message = 'Database error.';
                        $messageType = 'danger';
                    }
                }
            }
        }
    }
}

$campaigns = [];
$billableClients = [];
$stmt = $conn->prepare("
    SELECT c.id, c.name, d.code, d.client_code,
           COUNT(l.id) AS billable,
           COALESCE(d.cpl, 0) AS cpl,
           COALESCE(NULLIF(TRIM(d.cpl_currency), ''), 'USD') AS cpl_currency
    FROM campaigns c
    LEFT JOIN campaign_details d ON d.campaign_id = c.id
    LEFT JOIN leads l ON l.campaign_id = c.id
        AND l.client_delivery_status = 'Accepted'
        AND l.created_at BETWEEN ? AND ?
    GROUP BY c.id, c.name, d.code, d.client_code, d.cpl, d.cpl_currency
    HAVING COUNT(l.id) > 0 AND COALESCE(d.cpl, 0) > 0
    ORDER BY c.name
");
if ($stmt) {
    $stmt->bind_param('ss', $startDt, $endDt);
    $stmt->execute();
    $campaigns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT src.client_code,
           COALESCE(NULLIF(TRIM(cl.name), ''), src.client_code) AS client_name,
           COUNT(*) AS campaign_count,
           SUM(src.billable) AS total_billable,
           MAX(src.currency) AS currency,
           SUM(src.amount) AS total_amount
    FROM (
        SELECT d.client_code,
               COALESCE(NULLIF(TRIM(d.cpl_currency), ''), 'USD') AS currency,
               COUNT(l.id) AS billable,
               COUNT(l.id) * COALESCE(d.cpl, 0) AS amount
        FROM campaigns c
        LEFT JOIN campaign_details d ON d.campaign_id = c.id
        LEFT JOIN leads l ON l.campaign_id = c.id
            AND l.client_delivery_status = 'Accepted'
            AND l.created_at BETWEEN ? AND ?
        WHERE TRIM(COALESCE(d.client_code, '')) <> ''
        GROUP BY c.id, d.client_code, d.cpl, d.cpl_currency
        HAVING COUNT(l.id) > 0 AND COALESCE(d.cpl, 0) > 0
    ) src
    LEFT JOIN clients cl ON cl.client_code = src.client_code
    GROUP BY src.client_code, cl.name
    ORDER BY client_name, src.client_code
");
if ($stmt) {
    $stmt->bind_param('ss', $startDt, $endDt);
    $stmt->execute();
    $billableClients = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$rows = [];
$params = [];
$types = '';
$where = "WHERE (i.month_str = ? OR i.month_str IS NULL)";
$params[] = $monthStr;
$types .= 's';
if ($prefCampaignId > 0) {
    $where .= " AND i.campaign_id = ?";
    $params[] = $prefCampaignId;
    $types .= 'i';
}
if ($statusFilter !== 'All') {
    $where .= " AND i.status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}
if ($q !== '') {
    $where .= " AND (i.invoice_no LIKE ? OR i.client_name LIKE ? OR i.client_code LIKE ?)";
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}
$stmt = $conn->prepare("
    SELECT i.*, c.name AS campaign_name, COALESCE(ii.item_count, 0) AS item_count
    FROM revenue_invoices i
    LEFT JOIN campaigns c ON c.id = i.campaign_id
    LEFT JOIN (
        SELECT invoice_id, COUNT(*) AS item_count
        FROM revenue_invoice_items
        GROUP BY invoice_id
    ) ii ON ii.invoice_id = i.id
    {$where}
    ORDER BY i.issue_date DESC, i.id DESC
");
if ($stmt) {
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$counts = ['Draft' => 0, 'Sent' => 0, 'Paid' => 0, 'Cancelled' => 0];
$totalsByCur = [];
foreach ($rows as $r) {
    $st = (string)($r['status'] ?? 'Draft');
    if (isset($counts[$st])) $counts[$st]++;
    $cur = strtoupper(trim((string)($r['currency'] ?? 'USD')));
    if ($cur === '') $cur = 'USD';
    $val = (float)($r['total'] ?? 0);
    if (!isset($totalsByCur[$cur])) $totalsByCur[$cur] = 0.0;
    $totalsByCur[$cur] += $val;
}
$topCur = 'USD';
$topVal = 0.0;
foreach ($totalsByCur as $c => $v) {
    if ($v > $topVal) {
        $topVal = (float)$v;
        $topCur = (string)$c;
    }
}
$campaignQuery = $prefCampaignId > 0 ? ('&campaign_id=' . urlencode((string)$prefCampaignId)) : '';
$invoiceCount = count($rows);
$activeFilterCount = 0;
if ($prefCampaignId > 0) $activeFilterCount++;
if ($statusFilter !== 'All') $activeFilterCount++;
if ($q !== '') $activeFilterCount++;

$pageTitle = 'Invoices';
include __DIR__ . '/../../includes/layout/app_start.php';
?>
<div class="container-fluid px-0 sales-workspace revenue-workspace">
    <div class="sales-page-header sales-hero mb-3">
        <div>
            <div class="sales-page-eyebrow mb-2"><i class="bi bi-file-earmark-text"></i>Invoice Management</div>
            <h3 class="sales-page-title">Invoices</h3>
            <div class="sales-page-subtitle">Create draft invoices from accepted leads, manage invoice status, sync sales commission ownership, and keep billing details aligned with client and revenue data.</div>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <span class="sales-chip"><i class="bi bi-calendar3"></i><?php echo htmlspecialchars($monthStr); ?></span>
                <span class="sales-chip"><i class="bi bi-funnel"></i><?php echo htmlspecialchars($statusFilter); ?></span>
                <?php if ($prefCampaignId > 0): ?><span class="sales-chip"><i class="bi bi-megaphone"></i>Campaign filtered</span><?php endif; ?>
            </div>
        </div>
        <div class="sales-page-actions">
            <a class="btn btn-outline-primary btn-sm" href="revenue-dashboard.php?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
            <a class="btn btn-outline-secondary btn-sm" href="revenue.php?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-currency-dollar me-1"></i>Campaign Revenue</a>
            <a class="btn btn-light border btn-sm" href="billto-templates.php"><i class="bi bi-journal-bookmark me-1"></i>Bill To Templates</a>
        </div>
    </div>

    <ul class="nav nav-pills bg-light border rounded p-1 sales-pill-nav revenue-nav mb-3">
        <li class="nav-item"><a class="nav-link" href="revenue-dashboard.php?month=<?php echo urlencode($monthStr); ?>">Dashboard</a></li>
        <li class="nav-item"><a class="nav-link" href="revenue.php?month=<?php echo urlencode($monthStr); ?>">Campaign Revenue</a></li>
        <li class="nav-item"><a class="nav-link" href="expenses.php?month=<?php echo urlencode($monthStr); ?>">Expenses</a></li>
        <li class="nav-item"><a class="nav-link active" href="invoices.php?month=<?php echo urlencode($monthStr); ?>">Invoices</a></li>
        <li class="nav-item"><a class="nav-link" href="billto-templates.php">Bill To Templates</a></li>
        <li class="nav-item"><a class="nav-link" href="agent-roi.php?month=<?php echo urlencode($monthStr); ?>">Agent ROI</a></li>
    </ul>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> border-0 shadow-sm"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="revenue-callout h-100">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="text-muted small">Total Invoices</div>
                    <span class="badge bg-primary-subtle text-primary border"><i class="bi bi-file-earmark-text"></i></span>
                </div>
                <div class="revenue-callout-value mt-1"><?php echo number_format($invoiceCount); ?></div>
                <div class="text-muted small mt-1">Total: <?php echo htmlspecialchars($topCur . ' ' . number_format($topVal, 2)); ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <a class="text-decoration-none" href="?month=<?php echo urlencode($monthStr); ?>&status=Draft&q=<?php echo urlencode($q); ?><?php echo $campaignQuery; ?>">
                <div class="revenue-callout h-100">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="text-muted small">Draft</div>
                        <span class="badge bg-secondary-subtle text-secondary border"><i class="bi bi-pencil-square"></i></span>
                    </div>
                    <div class="revenue-callout-value mt-1"><?php echo number_format((int)$counts['Draft']); ?></div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a class="text-decoration-none" href="?month=<?php echo urlencode($monthStr); ?>&status=Sent&q=<?php echo urlencode($q); ?><?php echo $campaignQuery; ?>">
                <div class="revenue-callout h-100">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="text-muted small">Sent</div>
                        <span class="badge bg-primary-subtle text-primary border"><i class="bi bi-send"></i></span>
                    </div>
                    <div class="revenue-callout-value mt-1"><?php echo number_format((int)$counts['Sent']); ?></div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a class="text-decoration-none" href="?month=<?php echo urlencode($monthStr); ?>&status=Paid&q=<?php echo urlencode($q); ?><?php echo $campaignQuery; ?>">
                <div class="revenue-callout h-100">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="text-muted small">Paid</div>
                        <span class="badge bg-success-subtle text-success border border-success"><i class="bi bi-check2-circle"></i></span>
                    </div>
                    <div class="revenue-callout-value mt-1"><?php echo number_format((int)$counts['Paid']); ?></div>
                </div>
            </a>
        </div>
    </div>

    <div class="card border-0 shadow-sm sales-panel revenue-panel mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div class="fw-semibold"><i class="bi bi-plus-circle me-2"></i>Create Invoice</div>
            <button class="btn btn-sm btn-light border" type="button" data-bs-toggle="collapse" data-bs-target="#createInvoiceBox" aria-expanded="true">
                <i class="bi bi-chevron-expand"></i>
            </button>
        </div>
        <div id="createInvoiceBox" class="collapse show">
            <div class="card-body">
                <div class="revenue-create-card">
                    <div class="revenue-create-card-grid">
                        <div class="revenue-create-intro">
                            <div class="revenue-create-icon"><i class="bi bi-receipt-cutoff"></i></div>
                            <div>
                                <div class="revenue-create-title">Build Draft Invoices From Billable Delivery</div>
                                <div class="revenue-create-text">Generate either a campaign-wise draft or one combined client-code invoice for clients who want all billable campaigns merged into a single billing document.</div>
                                <div class="d-flex flex-wrap gap-2 mt-3">
                                    <span class="badge bg-success-subtle text-success border border-success">Billable only</span>
                                    <span class="badge bg-light text-body border"><?php echo number_format(count($campaigns)); ?> billable campaigns</span>
                                    <span class="badge bg-light text-body border"><?php echo number_format(count($billableClients)); ?> client-code groups</span>
                                </div>
                            </div>
                        </div>
                        <div class="d-grid gap-3">
                            <form method="post" class="row g-3 align-items-end sales-filter-form revenue-inline-form revenue-create-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="create_invoice_from_campaign">
                                <div class="col-12">
                                    <div class="revenue-filter-title">Campaign-Wise Invoice</div>
                                    <div class="revenue-filter-text">Creates one invoice for one campaign using accepted leads x CPL from the selected month.</div>
                                </div>
                                <div class="col-xl-7 col-lg-6">
                                    <label class="form-label small text-muted mb-1">Campaign</label>
                                    <select class="form-select" name="campaign_id" required>
                                        <option value="">Select billable campaign</option>
                                        <?php foreach ($campaigns as $c): ?>
                                            <option value="<?php echo (int)$c['id']; ?>" <?php echo ((int)$c['id'] === $prefCampaignId) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars(crmCampaignDisplayNameFromRow($c)); ?><?php echo isset($c['billable']) ? (' · ' . number_format((int)$c['billable']) . ' leads') : ''; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-xl-3 col-lg-3 col-md-6">
                                    <label class="form-label small text-muted mb-1">Month</label>
                                    <input type="month" class="form-control" name="month" value="<?php echo htmlspecialchars($monthStr); ?>" required>
                                </div>
                                <div class="col-xl-2 col-lg-3 col-md-6 d-grid revenue-create-actions">
                                    <button class="btn btn-primary" type="submit"><i class="bi bi-file-earmark-plus me-1"></i>Create Draft</button>
                                </div>
                            </form>
                            <form method="post" class="row g-3 align-items-end sales-filter-form revenue-inline-form revenue-create-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="create_invoice_from_client_code">
                                <div class="col-12">
                                    <div class="revenue-filter-title">Client-Code Combined Invoice</div>
                                    <div class="revenue-filter-text">Creates one invoice that combines all billable campaigns for the selected client code in the chosen month.</div>
                                </div>
                                <div class="col-xl-7 col-lg-6">
                                    <label class="form-label small text-muted mb-1">Client Code</label>
                                    <select class="form-select" name="client_code" required>
                                        <option value="">Select client code</option>
                                        <?php foreach ($billableClients as $client): ?>
                                            <option value="<?php echo htmlspecialchars((string)($client['client_code'] ?? '')); ?>">
                                                <?php echo htmlspecialchars((string)($client['client_name'] ?? ($client['client_code'] ?? ''))); ?>
                                                <?php echo !empty($client['client_code']) ? (' · ' . htmlspecialchars((string)$client['client_code'])) : ''; ?>
                                                <?php echo ' · ' . number_format((int)($client['campaign_count'] ?? 0)) . ' campaigns'; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-xl-3 col-lg-3 col-md-6">
                                    <label class="form-label small text-muted mb-1">Month</label>
                                    <input type="month" class="form-control" name="month" value="<?php echo htmlspecialchars($monthStr); ?>" required>
                                </div>
                                <div class="col-xl-2 col-lg-3 col-md-6 d-grid revenue-create-actions">
                                    <button class="btn btn-outline-primary" type="submit"><i class="bi bi-collection me-1"></i>Create Combined</button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
                        <span class="badge bg-success-subtle text-success border border-success">Billable: Accepted leads</span>
                        <span class="badge bg-light text-body border">Month-aware calculation</span>
                        <span class="badge bg-light text-body border">Client-code combine supported</span>
                        <span class="text-muted small">Only campaigns with billable leads and valid CPL are available here for invoice creation.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm sales-panel revenue-panel">
        <div class="card-header">
            <div class="revenue-list-header">
                <div>
                    <div class="fw-semibold"><i class="bi bi-list-ul me-2"></i>Invoice List</div>
                    <div class="text-muted small mt-1">Track invoice lifecycle, filter billing views quickly, and manage invoice actions from a single revenue control surface.</div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <span class="sales-chip"><i class="bi bi-files"></i><?php echo number_format($invoiceCount); ?> invoice<?php echo $invoiceCount === 1 ? '' : 's'; ?></span>
                    <span class="sales-chip"><i class="bi bi-calendar3"></i><?php echo htmlspecialchars($monthStr); ?></span>
                    <span class="sales-chip"><i class="bi bi-currency-dollar"></i><?php echo htmlspecialchars($topCur . ' ' . number_format($topVal, 2)); ?></span>
                    <?php if ($activeFilterCount > 0): ?><span class="sales-chip"><i class="bi bi-funnel"></i><?php echo number_format($activeFilterCount); ?> active filter<?php echo $activeFilterCount === 1 ? '' : 's'; ?></span><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="card-body pb-0">
            <div class="revenue-list-toolbar">
                <form method="get" class="row g-3 align-items-end sales-filter-form revenue-inline-form revenue-filter-panel">
                    <div class="col-12">
                        <div class="revenue-filter-heading">
                            <div>
                                <div class="revenue-filter-title">Filter Invoice View</div>
                                <div class="revenue-filter-text">Refine invoices by billing month, campaign, status, or client reference without leaving the page. Campaign filters list billable source campaigns for the selected month.</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-lg-6">
                        <label class="form-label small text-muted mb-1">Month</label>
                        <input type="month" class="form-control" name="month" value="<?php echo htmlspecialchars($monthStr); ?>">
                    </div>
                    <div class="col-xl-3 col-lg-6">
                        <label class="form-label small text-muted mb-1">Campaign</label>
                        <select class="form-select" name="campaign_id">
                            <option value="">All campaigns</option>
                            <?php foreach ($campaigns as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>" <?php echo $prefCampaignId === (int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars(crmCampaignDisplayNameFromRow($c)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-xl-2 col-lg-4 col-md-6">
                        <label class="form-label small text-muted mb-1">Status</label>
                        <select class="form-select" name="status">
                            <?php foreach ($allowedStatuses as $st): ?>
                                <option value="<?php echo htmlspecialchars($st); ?>" <?php echo $statusFilter === $st ? 'selected' : ''; ?>><?php echo htmlspecialchars($st); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-xl-2 col-lg-4 col-md-6">
                        <label class="form-label small text-muted mb-1">Search</label>
                        <input class="form-control" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Invoice / Client">
                    </div>
                    <div class="col-xl-2 col-lg-4 col-md-12 d-grid">
                        <button class="btn btn-outline-primary" type="submit"><i class="bi bi-search me-1"></i>Apply Filters</button>
                    </div>
                    <div class="col-12">
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <a class="btn btn-light border btn-sm" href="?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-x-circle me-1"></i>Reset</a>
                            <span class="text-muted small">Showing <?php echo number_format($invoiceCount); ?> invoice<?php echo $invoiceCount === 1 ? '' : 's'; ?> for the current view.</span>
                        </div>
                    </div>
                </form>
                <form method="post" id="bulkForm" class="revenue-bulk-panel">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="action" id="bulkAction" value="">
                    <input type="hidden" name="ids" id="bulkIds" value="">
                    <input type="hidden" name="new_status" id="bulkStatus" value="">
                    <div class="revenue-bulk-panel-head">
                        <div>
                            <div class="revenue-filter-title">Bulk Actions</div>
                            <div class="revenue-filter-text">Update status, copy drafts, or delete eligible invoices in one controlled action.</div>
                        </div>
                        <span class="small text-muted" id="invoiceSelectedCount">0 invoices selected</span>
                    </div>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-check2-square me-1"></i>Bulk Actions
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><button class="dropdown-item" type="button" data-bulk-action="bulk_copy"><i class="bi bi-files me-2"></i>Copy Selected</button></li>
                            <li><button class="dropdown-item text-danger" type="button" data-bulk-action="bulk_delete" data-confirm="Delete selected invoices?"><i class="bi bi-trash me-2"></i>Delete Selected</button></li>
                            <li><hr class="dropdown-divider"></li>
                            <li class="dropdown-header">Mark As</li>
                            <li><button class="dropdown-item" type="button" data-bulk-action="bulk_status" data-status="Draft"><i class="bi bi-pencil-square me-2"></i>Draft</button></li>
                            <li><button class="dropdown-item" type="button" data-bulk-action="bulk_status" data-status="Sent"><i class="bi bi-send me-2"></i>Sent</button></li>
                            <li><button class="dropdown-item" type="button" data-bulk-action="bulk_status" data-status="Paid"><i class="bi bi-check2-circle me-2"></i>Paid</button></li>
                            <li><button class="dropdown-item" type="button" data-bulk-action="bulk_status" data-status="Cancelled"><i class="bi bi-x-circle me-2"></i>Cancelled</button></li>
                        </ul>
                    </div>
                </form>
            </div>
        </div>
        <div class="table-responsive sales-table-responsive revenue-table-wrap invoice-table-wrap">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:40px;"><input type="checkbox" class="form-check-input" id="invCheckAll"></th>
                        <th>Invoice</th>
                        <th>Status</th>
                        <th>Client</th>
                        <th>Campaign</th>
                        <th class="text-end">Total</th>
                        <th>Issue</th>
                        <th>Due</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="9">
                                <div class="revenue-empty-state">
                                    <div class="revenue-empty-icon"><i class="bi bi-receipt"></i></div>
                                    <div class="revenue-empty-title">No invoices found for this view</div>
                                    <div class="revenue-empty-text">Try changing the billing month, campaign, status, or search filters to find invoices, or create a new draft invoice from an active campaign.</div>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <?php
                                $iid = (int)($r['id'] ?? 0);
                                $status = (string)($r['status'] ?? 'Draft');
                                $campaignName = (string)($r['campaign_name'] ?? '');
                                $clientName = trim((string)($r['client_name'] ?? ''));
                                $clientCode = trim((string)($r['client_code'] ?? ''));
                                $itemCount = (int)($r['item_count'] ?? 0);
                                $isCombinedInvoice = $campaignName === '' && $itemCount > 1;
                                $campaignDisplay = $campaignName !== '' ? $campaignName : ($isCombinedInvoice ? 'Multiple campaigns' : 'Not linked');
                                $campaignContextText = $campaignName !== ''
                                    ? 'Revenue-linked billing source'
                                    : ($isCombinedInvoice ? ($itemCount . ' line items combined under one client invoice') : 'No linked campaign on this invoice');
                                $statusClass = $status === 'Paid' ? 'is-paid'
                                    : ($status === 'Sent' ? 'is-sent'
                                    : ($status === 'Cancelled' ? 'is-cancelled' : 'is-draft'));
                            ?>
                            <tr class="revenue-invoice-row">
                                <td><input type="checkbox" class="form-check-input inv-check" value="<?php echo $iid; ?>"></td>
                                <td>
                                    <div class="revenue-invoice-meta">
                                        <a class="revenue-invoice-link" href="invoice-edit?id=<?php echo $iid; ?>"><?php echo htmlspecialchars((string)($r['invoice_no'] ?? '')); ?></a>
                                        <div class="revenue-invoice-subline">Billing month: <?php echo htmlspecialchars((string)($r['month_str'] ?? '')); ?></div>
                                    </div>
                                </td>
                                <td>
                                    <a class="text-decoration-none" href="?month=<?php echo urlencode($monthStr); ?>&status=<?php echo urlencode($status); ?>&q=<?php echo urlencode($q); ?><?php echo $campaignQuery; ?>">
                                        <span class="revenue-status-badge <?php echo htmlspecialchars($statusClass); ?>">
                                            <span class="revenue-status-dot"></span>
                                            <?php echo htmlspecialchars($status); ?>
                                        </span>
                                    </a>
                                </td>
                                <td>
                                    <div class="revenue-entity-block">
                                        <div class="revenue-entity-title"><?php echo htmlspecialchars($clientName !== '' ? $clientName : 'Client not linked'); ?></div>
                                        <div class="revenue-entity-subline">
                                            <span class="revenue-entity-chip">Client</span>
                                            <span><?php echo htmlspecialchars($clientCode !== '' ? ('Code: ' . $clientCode) : 'Code not available'); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="revenue-entity-block revenue-entity-block-campaign">
                                        <div class="revenue-entity-title"><?php echo htmlspecialchars($campaignDisplay); ?></div>
                                        <div class="revenue-entity-subline">
                                            <span class="revenue-entity-chip"><?php echo $isCombinedInvoice ? 'Combined' : 'Campaign'; ?></span>
                                            <span><?php echo htmlspecialchars($campaignContextText); ?></span>
                                        </div>
                                        <?php if ($isCombinedInvoice): ?>
                                            <div class="revenue-entity-subline mt-1">
                                                <span class="revenue-status-badge is-sent"><span class="revenue-status-dot"></span>Combined Invoice</span>
                                                <span class="revenue-status-badge is-draft"><span class="revenue-status-dot"></span>Client-Wise</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="text-end fw-semibold"><?php echo htmlspecialchars((string)($r['currency'] ?? 'USD') . ' ' . number_format((float)($r['total'] ?? 0), 2)); ?></td>
                                <td class="font-monospace"><?php echo htmlspecialchars((string)($r['issue_date'] ?? '')); ?></td>
                                <td class="font-monospace"><?php echo htmlspecialchars((string)($r['due_date'] ?? '')); ?></td>
                                <td class="text-end">
                                    <div class="revenue-row-actions ms-auto">
                                        <div class="revenue-quick-actions">
                                            <a class="revenue-action-btn revenue-action-btn-primary" href="invoice-edit?id=<?php echo $iid; ?>" data-bs-toggle="tooltip" title="Open invoice editor" aria-label="Edit invoice">
                                                <i class="bi bi-pencil"></i><span>Edit</span>
                                            </a>
                                            <a class="revenue-action-btn" href="invoice-pdf?id=<?php echo $iid; ?>&download=1" target="_blank" data-bs-toggle="tooltip" title="Download invoice PDF" aria-label="Download invoice PDF">
                                                <i class="bi bi-download"></i><span>PDF</span>
                                            </a>
                                            <form method="post" class="m-0">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="action" value="copy_invoice">
                                                <input type="hidden" name="id" value="<?php echo $iid; ?>">
                                                <button class="revenue-action-btn" type="submit" data-bs-toggle="tooltip" title="Create a copy of this invoice" aria-label="Create invoice copy">
                                                    <i class="bi bi-files"></i><span>Copy</span>
                                                </button>
                                            </form>
                                            <?php if ($status !== 'Paid'): ?>
                                                <form method="post" class="m-0" onsubmit="return confirm('Delete this invoice?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="action" value="delete_invoice">
                                                    <input type="hidden" name="id" value="<?php echo $iid; ?>">
                                                    <button class="revenue-action-btn revenue-action-btn-danger" type="submit" data-bs-toggle="tooltip" title="Delete this draft or unpaid invoice" aria-label="Delete invoice">
                                                        <i class="bi bi-trash"></i><span>Delete</span>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                        <div class="revenue-status-strip" role="group" aria-label="Invoice status actions">
                                            <span class="revenue-status-label">Status</span>
                                            <?php foreach (['Draft','Sent','Paid','Cancelled'] as $st): ?>
                                                <?php
                                                    $stIcon = $st === 'Draft' ? 'bi-pencil-square'
                                                        : ($st === 'Sent' ? 'bi-send'
                                                        : ($st === 'Paid' ? 'bi-check2-circle' : 'bi-x-circle'));
                                                    $statusBtnClass = $status === $st ? 'is-active' : '';
                                                ?>
                                                <form method="post" class="m-0">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="action" value="update_status">
                                                    <input type="hidden" name="id" value="<?php echo $iid; ?>">
                                                    <input type="hidden" name="new_status" value="<?php echo htmlspecialchars($st); ?>">
                                                    <button class="revenue-status-btn <?php echo htmlspecialchars($statusBtnClass); ?>" type="submit" <?php echo $status === $st ? 'disabled' : ''; ?> data-bs-toggle="tooltip" title="Mark as <?php echo htmlspecialchars($st); ?>" aria-label="Mark invoice as <?php echo htmlspecialchars($st); ?>">
                                                        <i class="bi <?php echo $stIcon; ?>"></i>
                                                        <span><?php echo htmlspecialchars($st); ?></span>
                                                    </button>
                                                </form>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="revenue-stat-note">Invoice status changes stay connected to commission sync, creator notifications, campaign links, and client billing templates so the revenue flow stays consistent across CRM modules.</div>
    </div>
</div>
<style>
.btn-xs{font-size:.72rem;line-height:1.1;border-radius:.25rem}
.btn-group-xs>.btn{font-size:.7rem;line-height:1.1;padding:.16rem .38rem}
.btn-group-xs>.btn i{margin-right:.18rem}
.btn-group-xs>.btn i{margin-right:0}
.invoice-table-wrap{border-radius:.6rem;overflow:hidden;border:1px solid rgba(0,0,0,.08)}
</style>
<script>
    function getSelectedInvoiceIds() {
        return Array.from(document.querySelectorAll('.inv-check:checked')).map(el => el.value).filter(Boolean);
    }
    function updateInvoiceSelectedCount() {
        const target = document.getElementById('invoiceSelectedCount');
        if (!target) return;
        const count = getSelectedInvoiceIds().length;
        target.textContent = count + ' invoice' + (count === 1 ? '' : 's') + ' selected';
    }
    const checkAll = document.getElementById('invCheckAll');
    if (checkAll) {
        checkAll.addEventListener('change', () => {
            document.querySelectorAll('.inv-check').forEach(cb => cb.checked = checkAll.checked);
            updateInvoiceSelectedCount();
        });
    }
    document.querySelectorAll('.inv-check').forEach(cb => {
        cb.addEventListener('change', () => {
            if (checkAll) {
                const all = document.querySelectorAll('.inv-check');
                const checked = document.querySelectorAll('.inv-check:checked');
                checkAll.checked = all.length > 0 && all.length === checked.length;
            }
            updateInvoiceSelectedCount();
        });
    });
    document.querySelectorAll('[data-bulk-action]').forEach(btn => {
        btn.addEventListener('click', () => {
            const ids = getSelectedInvoiceIds();
            if (!ids.length) return;
            const confirmMsg = btn.getAttribute('data-confirm');
            if (confirmMsg && !confirm(confirmMsg)) return;
            const form = document.getElementById('bulkForm');
            if (!form) return;
            const action = btn.getAttribute('data-bulk-action') || '';
            const status = btn.getAttribute('data-status') || '';
            const bulkAction = document.getElementById('bulkAction');
            const bulkIds = document.getElementById('bulkIds');
            const bulkStatus = document.getElementById('bulkStatus');
            if (bulkAction) bulkAction.value = action;
            if (bulkIds) bulkIds.value = ids.join(',');
            if (bulkStatus) bulkStatus.value = status;
            form.submit();
        });
    });
    updateInvoiceSelectedCount();
</script>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
