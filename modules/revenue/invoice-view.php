<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin','director','manager_director','sales_director','operations_director']);
ensureCsrfToken();
ensureDatabaseSchema();

$conn = getDbConnection();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo 'Invalid invoice';
    exit;
}
$invoiceNoParam = isset($_GET['invoice_no']) ? trim((string)$_GET['invoice_no']) : '';
$invoiceNoParam = $invoiceNoParam !== '' ? substr($invoiceNoParam, 0, 50) : '';

$stmt = $conn->prepare("SELECT * FROM revenue_invoices WHERE id = ? LIMIT 1");
$invoice = null;
if ($stmt) {
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}
if (!$invoice && $invoiceNoParam !== '') {
    $stmt = $conn->prepare("SELECT * FROM revenue_invoices WHERE invoice_no = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $invoiceNoParam);
        $stmt->execute();
        $invoice = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($invoice) {
            $id = (int)($invoice['id'] ?? $id);
        }
    }
}
if (!$invoice) {
    http_response_code(404);
    echo 'Invoice not found';
    exit;
}

$items = [];
$stmt = $conn->prepare("SELECT * FROM revenue_invoice_items WHERE invoice_id = ? ORDER BY sort_order, id");
if ($stmt) {
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$subtotal = 0.0;
foreach ($items as $item) {
    $subtotal += (float)($item['amount'] ?? ((float)($item['qty'] ?? 0) * (float)($item['unit_price'] ?? 0)));
}
$taxRate = (float)($invoice['tax_rate'] ?? 0);
if ($taxRate < 0) $taxRate = 0;
if ($taxRate > 100) $taxRate = 100;
$taxAmount = round(($subtotal * $taxRate) / 100.0, 2);
$total = $subtotal + $taxAmount;

$currency = strtoupper(trim((string)($invoice['currency'] ?? 'USD')));
if ($currency === '') $currency = 'USD';
$currencyLabel = static function(string $currency, float $amount): string {
    return $currency . ' ' . number_format($amount, 2);
};
$numToWords = static function(int $value) use (&$numToWords): string {
    $ones = ['zero','one','two','three','four','five','six','seven','eight','nine','ten','eleven','twelve','thirteen','fourteen','fifteen','sixteen','seventeen','eighteen','nineteen'];
    $tens = ['','','twenty','thirty','forty','fifty','sixty','seventy','eighty','ninety'];
    if ($value < 20) return $ones[$value];
    if ($value < 100) {
        $t = intdiv($value, 10);
        $r = $value % 10;
        return $tens[$t] . ($r ? '-' . $ones[$r] : '');
    }
    if ($value < 1000) {
        $h = intdiv($value, 100);
        $r = $value % 100;
        return $ones[$h] . ' hundred' . ($r ? ' ' . $numToWords($r) : '');
    }
    if ($value < 1000000) {
        $k = intdiv($value, 1000);
        $r = $value % 1000;
        return $numToWords($k) . ' thousand' . ($r ? ' ' . $numToWords($r) : '');
    }
    if ($value < 1000000000) {
        $m = intdiv($value, 1000000);
        $r = $value % 1000000;
        return $numToWords($m) . ' million' . ($r ? ' ' . $numToWords($r) : '');
    }
    $b = intdiv($value, 1000000000);
    $r = $value % 1000000000;
    return $numToWords($b) . ' billion' . ($r ? ' ' . $numToWords($r) : '');
};
$amountToWords = static function(float $amount, string $currency) use ($numToWords): string {
    $whole = (int)floor($amount + 0.00001);
    $fraction = (int)round(($amount - $whole) * 100);
    if ($fraction === 100) {
        $whole++;
        $fraction = 0;
    }
    $major = $currency === 'INR' ? 'RUPEES' : ($currency === 'USD' ? 'DOLLARS' : $currency);
    $minor = $currency === 'INR' ? 'PAISE' : ($currency === 'USD' ? 'CENTS' : 'CENTS');
    $text = strtoupper($numToWords(max(0, $whole))) . ' ' . $major;
    if ($fraction > 0) {
        $text .= ' AND ' . strtoupper($numToWords($fraction)) . ' ' . $minor;
    }
    return $text . ' ONLY';
};

$invoiceNo = trim((string)($invoice['invoice_no'] ?? ''));
if ($invoiceNo === '') $invoiceNo = 'Invoice #' . $id;
$billToName = trim((string)($invoice['bill_to_name'] ?? ($invoice['client_name'] ?? ($invoice['client_code'] ?? ''))));
$billToAddress = trim((string)($invoice['bill_to_address'] ?? ''));
$billToContacts = trim((string)($invoice['bill_to_contacts'] ?? ''));
$billFromName = trim((string)($invoice['bill_from_name'] ?? 'Taraj Global Solutions PVT. LTD.'));
$billFromAddress = trim((string)($invoice['bill_from_address'] ?? ''));
$billFromCityState = trim((string)($invoice['bill_from_city_state'] ?? ''));
$billFromCountry = trim((string)($invoice['bill_from_country'] ?? ''));
$billFromEmail = trim((string)($invoice['bill_from_email'] ?? ''));
$billFromPhone = trim((string)($invoice['bill_from_phone'] ?? ''));
$notes = trim((string)($invoice['notes'] ?? ''));
$clientCode = trim((string)($invoice['client_code'] ?? ''));
$status = trim((string)($invoice['status'] ?? 'Draft'));
$signaturePath = trim((string)($invoice['signature_path'] ?? ''));
$signatureUrl = '';
if ($signaturePath !== '') {
    $signatureUrl = appBasePath() . '/' . ltrim(str_replace('\\', '/', $signaturePath), '/');
}
$logoAbs = __DIR__ . '/../../assets/images/logos/New Taraj Logo.png';
$logoUrl = file_exists($logoAbs) ? (appBasePath() . '/assets/images/logos/New%20Taraj%20Logo.png') : '';
$contactLines = preg_split('/\r\n|\r|\n/', $billToContacts) ?: [];
$contactLines = array_values(array_filter(array_map(static fn($line) => trim((string)$line), $contactLines), static fn($line) => $line !== ''));
$isCombinedInvoice = ((int)($invoice['campaign_id'] ?? 0) <= 0) && count($items) > 1;

$pageTitle = 'Invoice View';
include __DIR__ . '/../../includes/layout/app_start.php';
?>
<style>
.invoice-word-wrap{display:grid;gap:24px}
.invoice-word-page{background:#fff;border:1px solid rgba(15,23,42,.08);border-radius:18px;box-shadow:0 18px 42px -34px rgba(15,23,42,.24);padding:28px 30px}
.invoice-word-top{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;flex-wrap:wrap;margin-bottom:22px}
.invoice-word-brand img{max-height:62px;max-width:260px}
.invoice-word-meta{min-width:220px}
.invoice-word-meta table,.invoice-word-box table,.invoice-word-items,.invoice-word-totals,.invoice-word-bank{width:100%;border-collapse:collapse}
.invoice-word-meta td{padding:4px 0;font-size:.9rem;vertical-align:top}
.invoice-word-boxes{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:22px}
.invoice-word-box{border:1px solid #dbe2ea;border-radius:14px;padding:16px}
.invoice-word-box h6{margin:0 0 10px;font-size:.88rem;text-transform:uppercase;letter-spacing:.06em;color:#475569}
.invoice-word-box p{margin:0 0 6px;white-space:pre-line}
.invoice-word-items th,.invoice-word-items td,.invoice-word-totals td,.invoice-word-bank td,.invoice-word-bank th{border:1px solid #dbe2ea;padding:10px 12px;vertical-align:top}
.invoice-word-items thead th,.invoice-word-bank thead th{background:#f8fafc;font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;color:#475569}
.invoice-word-items .text-end,.invoice-word-totals .text-end,.invoice-word-bank .text-end{text-align:right}
.invoice-word-totals{max-width:420px;margin-left:auto}
.invoice-word-note{padding:14px 16px;border:1px dashed #cbd5e1;border-radius:14px;background:#f8fafc}
.invoice-word-footer{display:grid;grid-template-columns:1.2fr .8fr;gap:18px;align-items:end}
.invoice-word-signature{text-align:right}
.invoice-word-signature img{max-height:90px;max-width:220px}
.invoice-word-page-break{page-break-before:always;break-before:page}
@media (max-width: 991.98px){
.invoice-word-boxes,.invoice-word-footer{grid-template-columns:1fr}
.invoice-word-signature{text-align:left}
}
@media print{
body{background:#fff!important}
.app-sidebar,.app-header,.sales-page-header,.nav,.btn,.card-header{display:none!important}
.card,.invoice-word-page{box-shadow:none!important;border:none!important}
.invoice-word-page{padding:0}
}
</style>

<div class="container-fluid px-0 sales-workspace revenue-workspace">
    <div class="sales-page-header sales-hero mb-3">
        <div>
            <div class="sales-page-eyebrow mb-2"><i class="bi bi-file-earmark-richtext"></i>Invoice Document</div>
            <h3 class="sales-page-title"><?php echo htmlspecialchars($invoiceNo); ?></h3>
            <div class="sales-page-subtitle">Table-based invoice view designed for easier printing, Word adaptation, and review of combined multi-campaign billing while keeping the existing invoice content intact.</div>
        </div>
        <div class="sales-page-actions">
            <a class="btn btn-light border btn-sm" href="invoice-edit?id=<?php echo (int)$id; ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
            <a class="btn btn-outline-primary btn-sm" href="invoice-pdf?id=<?php echo (int)$id; ?>&amp;invoice_no=<?php echo urlencode((string)$invoiceNo); ?>" target="_blank" rel="noopener"><i class="bi bi-filetype-pdf me-1"></i>PDF</a>
            <a class="btn btn-light border btn-sm" href="invoice-pdf?id=<?php echo (int)$id; ?>&amp;download=1&amp;invoice_no=<?php echo urlencode((string)$invoiceNo); ?>" target="_blank" rel="noopener"><i class="bi bi-download me-1"></i>Download</a>
        </div>
    </div>

    <div class="invoice-word-wrap">
        <div class="invoice-word-page">
            <div class="invoice-word-top">
                <div class="invoice-word-brand">
                    <?php if ($logoUrl !== ''): ?><img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="Company Logo"><?php endif; ?>
                </div>
                <div class="invoice-word-meta">
                    <table>
                        <tr><td><strong>Invoice No</strong></td><td><?php echo htmlspecialchars($invoiceNo); ?></td></tr>
                        <tr><td><strong>Status</strong></td><td><?php echo htmlspecialchars($status); ?></td></tr>
                        <tr><td><strong>Issue Date</strong></td><td><?php echo htmlspecialchars((string)($invoice['issue_date'] ?? '')); ?></td></tr>
                        <tr><td><strong>Due Date</strong></td><td><?php echo htmlspecialchars((string)($invoice['due_date'] ?? '')); ?></td></tr>
                        <tr><td><strong>Billing Month</strong></td><td><?php echo htmlspecialchars((string)($invoice['month_str'] ?? '')); ?></td></tr>
                        <tr><td><strong>Mode</strong></td><td><?php echo htmlspecialchars($isCombinedInvoice ? 'Combined client-code invoice' : 'Campaign-wise invoice'); ?></td></tr>
                    </table>
                </div>
            </div>

            <div class="invoice-word-boxes">
                <div class="invoice-word-box">
                    <h6>Billed By</h6>
                    <p><strong><?php echo htmlspecialchars($billFromName); ?></strong></p>
                    <?php if ($billFromAddress !== ''): ?><p><?php echo nl2br(htmlspecialchars($billFromAddress)); ?></p><?php endif; ?>
                    <?php if ($billFromCityState !== '' || $billFromCountry !== ''): ?><p><?php echo htmlspecialchars(trim($billFromCityState . ($billFromCountry !== '' ? ', ' . $billFromCountry : ''))); ?></p><?php endif; ?>
                    <?php if ($billFromEmail !== ''): ?><p>Email: <?php echo htmlspecialchars($billFromEmail); ?></p><?php endif; ?>
                    <?php if ($billFromPhone !== ''): ?><p>Phone: <?php echo htmlspecialchars($billFromPhone); ?></p><?php endif; ?>
                </div>
                <div class="invoice-word-box">
                    <h6>Bill To</h6>
                    <p><strong><?php echo htmlspecialchars($billToName !== '' ? $billToName : 'Client'); ?></strong></p>
                    <?php if ($clientCode !== ''): ?><p>Client Code: <?php echo htmlspecialchars($clientCode); ?></p><?php endif; ?>
                    <?php if ($billToAddress !== ''): ?><p><?php echo nl2br(htmlspecialchars($billToAddress)); ?></p><?php endif; ?>
                    <?php foreach ($contactLines as $line): ?><p><?php echo htmlspecialchars($line); ?></p><?php endforeach; ?>
                </div>
            </div>

            <table class="invoice-word-items">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Unit Price</th>
                        <th class="text-end">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                        <tr><td colspan="4" class="text-center text-muted">No invoice items found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($items as $item): ?>
                            <?php
                                $qty = (float)($item['qty'] ?? 0);
                                $unitPrice = (float)($item['unit_price'] ?? 0);
                                $amount = (float)($item['amount'] ?? ($qty * $unitPrice));
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)($item['description'] ?? '')); ?></td>
                                <td class="text-end"><?php echo number_format($qty, 2); ?></td>
                                <td class="text-end"><?php echo htmlspecialchars($currencyLabel($currency, $unitPrice)); ?></td>
                                <td class="text-end"><?php echo htmlspecialchars($currencyLabel($currency, $amount)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="invoice-word-page invoice-word-page-break">
            <div class="invoice-word-top">
                <div>
                    <div class="revenue-section-title mb-2">Invoice Summary</div>
                    <div class="revenue-section-text">This page keeps the totals, bank details, notes, and sign-off separate so the document is easier to print and adapt into Word while preserving the same invoice information.</div>
                </div>
                <div class="revenue-chip-row">
                    <span class="revenue-chip"><i class="bi bi-layers"></i><?php echo number_format(count($items)); ?> line item<?php echo count($items) === 1 ? '' : 's'; ?></span>
                    <?php if ($isCombinedInvoice): ?><span class="revenue-chip"><i class="bi bi-collection"></i>Combined client-code invoice</span><?php endif; ?>
                </div>
            </div>

            <table class="invoice-word-totals mb-4">
                <tr><td><strong>Subtotal</strong></td><td class="text-end"><?php echo htmlspecialchars($currencyLabel($currency, $subtotal)); ?></td></tr>
                <tr><td><strong>Tax (<?php echo number_format($taxRate, 2); ?>%)</strong></td><td class="text-end"><?php echo htmlspecialchars($currencyLabel($currency, $taxAmount)); ?></td></tr>
                <tr><td><strong>Total</strong></td><td class="text-end"><?php echo htmlspecialchars($currencyLabel($currency, $total)); ?></td></tr>
            </table>

            <div class="invoice-word-note mb-4">
                <strong>Amount In Words</strong>
                <div class="mt-2"><?php echo htmlspecialchars($amountToWords($total, $currency)); ?></div>
            </div>

            <table class="invoice-word-bank mb-4">
                <thead>
                    <tr>
                        <th colspan="2">Bank And Beneficiary Details</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td>Bank Name</td><td><?php echo htmlspecialchars((string)($invoice['bank_name'] ?? '')); ?></td></tr>
                    <tr><td>Account Name</td><td><?php echo htmlspecialchars((string)($invoice['account_name'] ?? '')); ?></td></tr>
                    <tr><td>Account Number</td><td><?php echo htmlspecialchars((string)($invoice['account_number'] ?? '')); ?></td></tr>
                    <tr><td>IFSC Code</td><td><?php echo htmlspecialchars((string)($invoice['ifsc_code'] ?? '')); ?></td></tr>
                    <tr><td>SWIFT Code</td><td><?php echo htmlspecialchars((string)($invoice['swift_code'] ?? '')); ?></td></tr>
                    <tr><td>Beneficiary Address</td><td><?php echo nl2br(htmlspecialchars((string)($invoice['beneficiary_address'] ?? ''))); ?></td></tr>
                    <tr><td>Beneficiary City / State</td><td><?php echo htmlspecialchars((string)($invoice['beneficiary_city_state'] ?? '')); ?></td></tr>
                </tbody>
            </table>

            <div class="invoice-word-footer">
                <div>
                    <div class="revenue-section-title mb-2">Notes</div>
                    <div class="invoice-word-note"><?php echo nl2br(htmlspecialchars($notes !== '' ? $notes : 'No additional notes.')); ?></div>
                </div>
                <div class="invoice-word-signature">
                    <div class="revenue-section-title mb-2">Authorised Signatory</div>
                    <?php if ($signatureUrl !== ''): ?>
                        <img src="<?php echo htmlspecialchars($signatureUrl); ?>" alt="Signature">
                    <?php else: ?>
                        <div class="invoice-word-note">Signature not uploaded</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
