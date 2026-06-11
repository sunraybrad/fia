<?php
// =============================================================================
// qbo/qbo_invoice.php
//
// Creates QBO Invoices for FIA warranty company billing and retrieves the
// resulting invoice metadata needed to render the invoice PDF.
//
// Uses IPP data objects directly — not the Facade layer (PHP 8.x incompatible).
//
// Public API:
//
//   qbo_create_invoice(int $warranty_co_id, array $line_items, string $inv_date = ''): array
//       Creates a QBO Invoice for the given warranty company. Each element of
//       $line_items corresponds to one inspection.
//
//       $line_items element keys:
//         inspection_type  — must match an existing QBO Service item name exactly
//         fia_number       — used in the line-item Description
//         claim_number     — used in the line-item Description
//         contract_number  — used in the line-item Description
//         insured          — used in the line-item Description
//         inspection_fee   — numeric or string amount (e.g. "135.00")
//
//       On success returns:
//         ['success'        => true,
//          'qb_invoice'     => '1042',           // QBO DocNumber
//          'qb_invoice_id'  => '145',            // internal QBO entity Id
//          'qb_inv_date'    => '06/10/2026',     // formatted M/D/Y
//          'qb_inv_duedate' => '07/10/2026',     // formatted M/D/Y (or '' if not set)
//          'terms'          => 'Net 30']          // or '' if not set on customer
//
//       On failure returns:
//         ['success' => false, 'error' => '...']
//
//   qbo_get_item_id(DataService $qbo, string $item_name): string|false
//       Looks up a QBO Service item by name. Returns the QBO Item Id string,
//       or false if the item is not found. Does NOT auto-create — items must
//       be set up manually in QBO under Settings → Products and Services.
//
//   qbo_get_customer_terms(DataService $qbo, string $customer_qbo_id): string
//       Returns the payment terms label for a QBO Customer (e.g. "Net 30"),
//       or '' if not set or unavailable.
// =============================================================================

require_once __DIR__ . '/qbo_service.php';
require_once __DIR__ . '/qbo_sync.php';

use QuickBooksOnline\API\Data\IPPInvoice;
use QuickBooksOnline\API\Data\IPPLine;
use QuickBooksOnline\API\Data\IPPSalesItemLineDetail;
use QuickBooksOnline\API\Data\IPPReferenceType;
use QuickBooksOnline\API\Data\IPPCustomer;

// ---------------------------------------------------------------------------
// Public functions
// ---------------------------------------------------------------------------

/**
 * Create a QBO Invoice for a warranty company.
 *
 * Before creating the invoice this function:
 *   1. Ensures the warranty company has a valid numeric QBO Customer ID
 *      (calls push_warranty_co_as_customer() if quickbooks_ref is missing or
 *      non-numeric).
 *   2. Resolves each unique inspection_type to a QBO Item ID.
 *
 * @param  int    $warranty_co_id
 * @param  array  $line_items  See file header for element keys.
 * @param  string $inv_date    Invoice date as 'Y-m-d'; defaults to today.
 * @return array  See file header for return shape.
 */
function qbo_create_invoice(int $warranty_co_id, array $line_items, string $inv_date = ''): array
{
    if (empty($line_items)) {
        return ['success' => false, 'error' => 'No line items provided.'];
    }

    $db = get_db();

    // ---- 1. Ensure QBO Customer exists for the warranty company ---------------
    $wstmt = $db->prepare('SELECT quickbooks_ref FROM warranty_co WHERE warranty_co_id = ? LIMIT 1');
    $wstmt->bind_param('i', $warranty_co_id);
    $wstmt->execute();
    $wrow = $wstmt->get_result()->fetch_assoc();
    $wstmt->close();

    if (!$wrow) {
        return ['success' => false, 'error' => "Warranty company {$warranty_co_id} not found."];
    }

    $customer_qbo_id = trim($wrow['quickbooks_ref'] ?? '');
    if (!is_numeric($customer_qbo_id)) {
        // Create or update the QBO Customer record
        $sync = push_warranty_co_as_customer($warranty_co_id);
        if (!$sync['success']) {
            return ['success' => false, 'error' => 'Failed to sync warranty company to QBO: ' . $sync['error']];
        }
        $customer_qbo_id = $sync['qbo_id'];
    }

    // ---- 2. Get QBO service ------------------------------------------------
    $qbo = get_qbo_service();
    if (!$qbo) {
        return ['success' => false, 'error' => 'QBO not connected. Run /qbo/connect.php first.'];
    }

    // ---- 3. Resolve inspection_type → QBO Item IDs (cache per request) -------
    $item_id_cache = [];
    foreach ($line_items as $item) {
        $type = trim($item['inspection_type'] ?? '');
        if ($type === '' || isset($item_id_cache[$type])) continue;

        $item_id = qbo_get_item_id($qbo, $type);
        if ($item_id === false) {
            return [
                'success' => false,
                'error'   => "QBO Service item \"{$type}\" not found. "
                           . "Add it under Settings → Products and Services in QBO, "
                           . "then try again.",
            ];
        }
        $item_id_cache[$type] = $item_id;
    }

    // ---- 4. Build QBO Invoice -----------------------------------------------
    $invoice = new IPPInvoice();

    $customerRef = new IPPReferenceType();
    $customerRef->value = $customer_qbo_id;
    $invoice->CustomerRef = $customerRef;

    // Invoice date
    $dateStr = $inv_date ?: date('Y-m-d');
    $invoice->TxnDate = $dateStr;

    // Line items
    $qbo_lines = [];
    foreach ($line_items as $idx => $item) {
        $type    = trim($item['inspection_type'] ?? '');
        $amount  = (float)($item['inspection_fee'] ?? 0);
        $item_id = $item_id_cache[$type] ?? null;

        // Description: matches the existing QB layout — space-separated
        $desc = implode(' ', array_filter([
            $item['claim_number']    ?? '',
            $item['contract_number'] ?? '',
            $item['insured']         ?? '',
            $item['fia_number']      ?? '',
        ]));

        $detail = new IPPSalesItemLineDetail();
        $detail->Qty      = 1;
        $detail->UnitPrice = $amount;

        if ($item_id !== null) {
            $itemRef = new IPPReferenceType();
            $itemRef->value = $item_id;
            $detail->ItemRef = $itemRef;
        }

        $line = new IPPLine();
        $line->Amount            = $amount;
        $line->DetailType        = 'SalesItemLineDetail';
        $line->SalesItemLineDetail = $detail;
        $line->Description       = $desc;
        $line->LineNum           = $idx + 1;

        $qbo_lines[] = $line;
    }
    $invoice->Line = $qbo_lines;

    // ---- 5. Post to QBO ------------------------------------------------------
    $result = $qbo->Add($invoice);
    $error  = $qbo->getLastError();

    if ($error) {
        $msg = $error->getResponseBody() ?? $error->getMessage();
        error_log("QBO invoice creation failed (warranty_co {$warranty_co_id}): " . $msg);
        return ['success' => false, 'error' => $msg];
    }

    $qbo_id       = (string)$result->Id;
    $doc_number   = (string)($result->DocNumber ?? $qbo_id);
    $txn_date     = (string)($result->TxnDate ?? $dateStr);
    $due_date_raw = (string)($result->DueDate ?? '');

    // Format dates as M/D/Y for the invoice PDF
    $inv_date_fmt = $txn_date
        ? date('n/j/Y', strtotime($txn_date))
        : date('n/j/Y');
    $due_date_fmt = $due_date_raw
        ? date('n/j/Y', strtotime($due_date_raw))
        : '';

    // ---- 6. Fetch payment terms from the Customer record -------------------
    $terms = qbo_get_customer_terms($qbo, $customer_qbo_id);

    log_audit('qbo.invoice.create', 'warranty_co', $warranty_co_id, [
        'qbo_invoice_id' => $qbo_id,
        'doc_number'     => $doc_number,
        'line_count'     => count($line_items),
    ]);

    return [
        'success'        => true,
        'qb_invoice'     => $doc_number,
        'qb_invoice_id'  => $qbo_id,
        'qb_inv_date'    => $inv_date_fmt,
        'qb_inv_duedate' => $due_date_fmt,
        'terms'          => $terms,
    ];
}

/**
 * Look up a QBO Service item by its exact name.
 *
 * @param  DataService $qbo
 * @param  string      $item_name  Exact name as configured in QBO Products & Services.
 * @return string|false            QBO Item Id string, or false if not found.
 */
function qbo_get_item_id($qbo, string $item_name): string|false
{
    // QBO name fields may contain apostrophes — escape single quotes for the query
    $escaped = str_replace("'", "\\'", $item_name);

    $query  = "SELECT * FROM Item WHERE Name = '{$escaped}' AND Type = 'Service' MAXRESULTS 5";
    $items  = $qbo->Query($query);
    $error  = $qbo->getLastError();

    if ($error) {
        error_log("qbo_get_item_id query failed for \"{$item_name}\": " . ($error->getResponseBody() ?? $error->getMessage()));
        return false;
    }

    if (empty($items)) return false;

    // Return the first match
    $item = is_array($items) ? $items[0] : $items;
    return (string)$item->Id;
}

/**
 * Retrieve the payment terms label for a QBO Customer.
 *
 * @param  DataService $qbo
 * @param  string      $customer_qbo_id  QBO Customer entity Id.
 * @return string      Terms label (e.g. "Net 30"), or '' if not set.
 */
function qbo_get_customer_terms($qbo, string $customer_qbo_id): string
{
    try {
        $customer = $qbo->FindById(new IPPCustomer(), $customer_qbo_id);
    } catch (Throwable $e) {
        error_log("qbo_get_customer_terms: FindById failed for customer {$customer_qbo_id}: " . $e->getMessage());
        return '';
    }

    $error = $qbo->getLastError();
    if ($error || !$customer) return '';

    $terms_ref = $customer->SalesTermRef ?? null;
    if (!$terms_ref) return '';

    $terms_id   = (string)($terms_ref->value ?? '');
    $terms_name = (string)($terms_ref->name  ?? '');

    // If the name is already populated in the ref, use it directly
    if ($terms_name !== '') return $terms_name;

    // Otherwise query the Term entity by Id
    if ($terms_id === '') return '';

    $query = "SELECT * FROM Term WHERE Id = '{$terms_id}' MAXRESULTS 1";
    $terms = $qbo->Query($query);
    $error = $qbo->getLastError();

    if ($error || empty($terms)) return '';

    $term = is_array($terms) ? $terms[0] : $terms;
    return (string)($term->Name ?? '');
}
