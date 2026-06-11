<?php
// =============================================================================
// qbo/qbo_bill.php
//
// Creates a QBO Vendor Bill for the inspector when an inspection is invoiced.
// Per-inspection billing: one inspection → one invoice (customer side) → one
// bill (vendor side). No PDF is produced — the bill exists only in QBO, with
// its identifiers written back to the inspections row.
//
// Uses IPP data objects directly — not the Facade layer (PHP 8.x incompatible).
//
// Public API:
//
//   qbo_create_vendor_bill(int $fia): array
//       Creates a QBO Bill for the inspector assigned to the inspection.
//
//       Bill amount = base_fee + additional_mileage + special_charges + total_pix
//       (the FileMaker InspectorFeeTotal formula — total_pix is a dollar value).
//
//       The single bill line posts to the expense account named by
//       QBO_BILL_EXPENSE_ACCOUNT (config.php), resolved by FullyQualifiedName
//       then by Name. DocNumber is set to "FIA-{fia}" for traceability.
//
//       Idempotent: if inspections.bill_qb_id is already set, returns success
//       with 'already_exists' => true and does NOT create a second bill.
//
//       On success returns:
//         ['success'        => true,
//          'qb_bill_no'     => 'FIA-345931',   // QBO DocNumber
//          'qb_bill_id'     => '187',          // internal QBO entity Id
//          'amount'         => 96.50,
//          'already_exists' => false]
//
//       On failure returns:
//         ['success' => false, 'error' => '...']
//
//   qbo_get_expense_account_id(DataService $qbo, string $account_name): string|false
//       Resolves a chart-of-accounts entry by FullyQualifiedName (e.g.
//       "Inspector Costs:subcontractor"), falling back to plain Name.
//       Does NOT auto-create accounts.
// =============================================================================

require_once __DIR__ . '/qbo_service.php';
require_once __DIR__ . '/qbo_sync.php';

use QuickBooksOnline\API\Data\IPPBill;
use QuickBooksOnline\API\Data\IPPLine;
use QuickBooksOnline\API\Data\IPPAccountBasedExpenseLineDetail;
use QuickBooksOnline\API\Data\IPPReferenceType;

/**
 * Create a QBO Vendor Bill for the inspector on an inspection.
 *
 * @param  int $fia  FIA number (inspections primary key).
 * @return array     See file header for return shape.
 */
function qbo_create_vendor_bill(int $fia): array
{
    $db = get_db();

    $stmt = $db->prepare("
        SELECT i.fia_number, i.inspector_id,
               i.claim_number, i.contract_number, i.insured,
               i.base_fee, i.additional_mileage, i.special_charges, i.total_pix,
               i.bill_qb_no, i.bill_qb_id,
               n.quickbooks_ref AS inspector_qbo_ref, n.full_name AS inspector_name
        FROM inspections i
        LEFT JOIN inspectors n ON n.inspector_id = i.inspector_id
        WHERE i.fia_number = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $fia);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return ['success' => false, 'error' => "Inspection {$fia} not found."];
    }

    // ---- Idempotency guard: never create a second bill for the same FIA ----
    if (trim($row['bill_qb_id'] ?? '') !== '') {
        return [
            'success'        => true,
            'qb_bill_no'     => (string)$row['bill_qb_no'],
            'qb_bill_id'     => (string)$row['bill_qb_id'],
            'amount'         => 0.0,
            'already_exists' => true,
        ];
    }

    $inspector_id = (int)($row['inspector_id'] ?? 0);
    if (!$inspector_id) {
        return ['success' => false, 'error' => "Inspection {$fia} has no inspector assigned."];
    }

    // ---- Amount: FM InspectorFeeTotal formula --------------------------------
    $amount = (float)($row['base_fee']           ?? 0)
            + (float)($row['additional_mileage'] ?? 0)
            + (float)($row['special_charges']    ?? 0)
            + (float)($row['total_pix']          ?? 0);

    if ($amount <= 0) {
        return ['success' => false, 'error' =>
            "Inspector fee total is 0.00 for FIA {$fia} "
            . "(base_fee + additional_mileage + special_charges + total_pix). "
            . "Enter the inspector fees, then regenerate."];
    }

    // ---- Ensure the inspector exists as a QBO Vendor --------------------------
    $vendor_qbo_id = trim($row['inspector_qbo_ref'] ?? '');
    if (!is_numeric($vendor_qbo_id)) {
        $sync = push_inspector_as_vendor($inspector_id);
        if (!$sync['success']) {
            return ['success' => false, 'error' => 'Failed to sync inspector to QBO: ' . $sync['error']];
        }
        $vendor_qbo_id = $sync['qbo_id'];
    }

    // ---- QBO service + expense account ----------------------------------------
    $qbo = get_qbo_service();
    if (!$qbo) {
        return ['success' => false, 'error' => 'QBO not connected. Run /qbo/connect.php first.'];
    }

    $account_id = qbo_get_expense_account_id($qbo, QBO_BILL_EXPENSE_ACCOUNT);
    if ($account_id === false) {
        return ['success' => false, 'error' =>
            'QBO expense account "' . QBO_BILL_EXPENSE_ACCOUNT . '" not found. '
            . 'Add it to the chart of accounts in QBO, then try again.'];
    }

    // ---- Build the Bill --------------------------------------------------------
    // Description: same convention as the invoice line (space-separated).
    $desc = implode(' ', array_filter([
        $row['claim_number']    ?? '',
        $row['contract_number'] ?? '',
        $row['insured']         ?? '',
        (string)$row['fia_number'],
    ]));

    $accountRef = new IPPReferenceType();
    $accountRef->value = $account_id;

    $detail = new IPPAccountBasedExpenseLineDetail();
    $detail->AccountRef = $accountRef;

    $line = new IPPLine();
    $line->Amount                        = $amount;
    $line->DetailType                    = 'AccountBasedExpenseLineDetail';
    $line->AccountBasedExpenseLineDetail = $detail;
    $line->Description                   = $desc;

    $vendorRef = new IPPReferenceType();
    $vendorRef->value = $vendor_qbo_id;

    $bill = new IPPBill();
    $bill->VendorRef = $vendorRef;
    $bill->TxnDate   = date('Y-m-d');
    $bill->DocNumber = 'FIA-' . $fia;        // QBO DocNumber max 21 chars
    $bill->Line      = [$line];

    // ---- Post to QBO -----------------------------------------------------------
    $result = $qbo->Add($bill);
    $error  = $qbo->getLastError();

    if ($error) {
        $msg = $error->getResponseBody() ?? $error->getMessage();
        error_log("QBO vendor bill creation failed (FIA {$fia}, inspector {$inspector_id}): " . $msg);
        return ['success' => false, 'error' => $msg];
    }

    $qb_bill_id = (string)$result->Id;
    $qb_bill_no = (string)($result->DocNumber ?? ('FIA-' . $fia));

    // ---- Write identifiers back to MySQL ---------------------------------------
    $upd = $db->prepare("UPDATE inspections SET bill_qb_no = ?, bill_qb_id = ? WHERE fia_number = ?");
    $upd->bind_param('ssi', $qb_bill_no, $qb_bill_id, $fia);
    $upd->execute();
    $upd->close();

    log_audit('qbo.bill.create', 'inspection', $fia, [
        'qbo_bill_id'   => $qb_bill_id,
        'doc_number'    => $qb_bill_no,
        'vendor_qbo_id' => $vendor_qbo_id,
        'inspector_id'  => $inspector_id,
        'amount'        => number_format($amount, 2, '.', ''),
    ]);

    return [
        'success'        => true,
        'qb_bill_no'     => $qb_bill_no,
        'qb_bill_id'     => $qb_bill_id,
        'amount'         => $amount,
        'already_exists' => false,
    ];
}

/**
 * Resolve a QBO chart-of-accounts entry to its Id.
 * Tries FullyQualifiedName first (handles sub-accounts like "Parent:child"),
 * then falls back to plain Name.
 *
 * @param  DataService $qbo
 * @param  string      $account_name
 * @return string|false  QBO Account Id, or false if not found.
 */
function qbo_get_expense_account_id($qbo, string $account_name): string|false
{
    static $cache = [];
    if (isset($cache[$account_name])) return $cache[$account_name];

    $escaped = str_replace("'", "\\'", $account_name);

    foreach (['FullyQualifiedName', 'Name'] as $field) {
        $query    = "SELECT * FROM Account WHERE {$field} = '{$escaped}' MAXRESULTS 5";
        $accounts = $qbo->Query($query);
        $error    = $qbo->getLastError();

        if ($error) {
            error_log("qbo_get_expense_account_id query failed for \"{$account_name}\" ({$field}): "
                . ($error->getResponseBody() ?? $error->getMessage()));
            continue;
        }
        if (!empty($accounts)) {
            $account = is_array($accounts) ? $accounts[0] : $accounts;
            return $cache[$account_name] = (string)$account->Id;
        }
    }

    return false;
}
