<?php
// =============================================================================
// qbo/qbo_sync.php
// Syncs FIA entities to QuickBooks Online using IPP data objects directly
// (bypasses the Facade layer which has PHP 8.x compatibility issues).
//
// push_inspector_as_vendor($inspector_id)       — creates or updates a QBO Vendor
// push_warranty_co_as_customer($warranty_co_id) — creates or updates a QBO Customer
//
// Both store the QBO entity ID in quickbooks_ref on success.
// Returns ['success'=>true, 'qbo_id'=>'...'] or ['success'=>false, 'error'=>'...']
// =============================================================================

require_once __DIR__ . '/qbo_service.php';

use QuickBooksOnline\API\Data\IPPVendor;
use QuickBooksOnline\API\Data\IPPCustomer;
use QuickBooksOnline\API\Data\IPPPhysicalAddress;
use QuickBooksOnline\API\Data\IPPTelephoneNumber;
use QuickBooksOnline\API\Data\IPPEmailAddress;

// -----------------------------------------------------------------------------
// Inspector → QBO Vendor
// -----------------------------------------------------------------------------

function push_inspector_as_vendor(int $inspector_id): array {
    $db = get_db();

    $stmt = $db->prepare('
        SELECT inspector_id, full_name, company, email,
               phone_primary, phone_cell,
               address, city, state_code, zip, country,
               quickbooks_ref
        FROM inspectors WHERE inspector_id = ? LIMIT 1
    ');
    $stmt->bind_param('i', $inspector_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return ['success' => false, 'error' => "Inspector {$inspector_id} not found."];
    }

    $display_name = trim($row['full_name'] ?? '');
    if ($display_name === '') {
        return ['success' => false, 'error' => "Inspector {$inspector_id} has no name."];
    }

    $qbo = get_qbo_service();
    if (!$qbo) {
        return ['success' => false, 'error' => 'QBO not connected. Run /qbo/connect.php first.'];
    }

    $vendor = new IPPVendor();
    $vendor->DisplayName      = $display_name . ' [FIA-' . $inspector_id . ']';
    $vendor->PrintOnCheckName = $display_name;

    if (!empty(trim($row['company'] ?? ''))) {
        $vendor->CompanyName = trim($row['company']);
    }
    if (!empty(trim($row['email'] ?? ''))) {
        $email = new IPPEmailAddress();
        $email->Address = trim($row['email']);
        $vendor->PrimaryEmailAddr = $email;
    }
    if (!empty(trim($row['phone_primary'] ?? ''))) {
        $phone = new IPPTelephoneNumber();
        $phone->FreeFormNumber = trim($row['phone_primary']);
        $vendor->PrimaryPhone = $phone;
    }
    if (!empty(trim($row['phone_cell'] ?? ''))) {
        $mobile = new IPPTelephoneNumber();
        $mobile->FreeFormNumber = trim($row['phone_cell']);
        $vendor->Mobile = $mobile;
    }

    $addr = _build_addr_obj($row);
    if ($addr !== null) {
        $vendor->BillAddr = $addr;
    }

    // Only use quickbooks_ref as a QBO ID if it's numeric — old FileMaker values are not
    $raw_ref = trim($row['quickbooks_ref'] ?? '');
    $existing_qbo_id = is_numeric($raw_ref) ? $raw_ref : '';

    if ($existing_qbo_id !== '') {
        $vendor->Id        = $existing_qbo_id;
        $vendor->SyncToken = _get_sync_token($qbo, 'vendor', $existing_qbo_id);
        $result = $qbo->Update($vendor);
    } else {
        $result = $qbo->Add($vendor);
    }

    $error = $qbo->getLastError();
    if ($error) {
        $msg = $error->getResponseBody() ?? $error->getMessage();
        error_log("QBO vendor sync failed for inspector {$inspector_id}: " . $msg);
        return ['success' => false, 'error' => $msg];
    }

    $qbo_id = (string)$result->Id;

    $upd = $db->prepare('UPDATE inspectors SET quickbooks_ref = ? WHERE inspector_id = ?');
    $upd->bind_param('si', $qbo_id, $inspector_id);
    $upd->execute();
    $upd->close();

    log_audit('qbo.vendor.sync', 'inspector', $inspector_id, ['qbo_id' => $qbo_id]);

    return ['success' => true, 'qbo_id' => $qbo_id];
}

// -----------------------------------------------------------------------------
// Warranty Company → QBO Customer
// -----------------------------------------------------------------------------

function push_warranty_co_as_customer(int $warranty_co_id): array {
    $db = get_db();

    $stmt = $db->prepare('
        SELECT warranty_co_id, company_name, supervisor_name, supervisor_email,
               fia_phone, fax,
               address, city, state_code, zip, country,
               quickbooks_ref
        FROM warranty_co WHERE warranty_co_id = ? LIMIT 1
    ');
    $stmt->bind_param('i', $warranty_co_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return ['success' => false, 'error' => "Warranty company {$warranty_co_id} not found."];
    }

    $company_name = trim($row['company_name'] ?? '');
    if ($company_name === '') {
        return ['success' => false, 'error' => "Warranty co {$warranty_co_id} has no company name."];
    }

    $qbo = get_qbo_service();
    if (!$qbo) {
        return ['success' => false, 'error' => 'QBO not connected. Run /qbo/connect.php first.'];
    }

    $customer = new IPPCustomer();
    $customer->DisplayName = $company_name . ' [FIA-' . $warranty_co_id . ']';
    $customer->CompanyName = $company_name;

    $supervisor = trim($row['supervisor_name'] ?? '');
    if ($supervisor !== '') {
        [$first, $last] = _split_name($supervisor);
        if ($first !== '') $customer->GivenName  = $first;
        if ($last  !== '') $customer->FamilyName = $last;
    }

    if (!empty(trim($row['supervisor_email'] ?? ''))) {
        $email = new IPPEmailAddress();
        $email->Address = trim($row['supervisor_email']);
        $customer->PrimaryEmailAddr = $email;
    }
    if (!empty(trim($row['fia_phone'] ?? ''))) {
        $phone = new IPPTelephoneNumber();
        $phone->FreeFormNumber = trim($row['fia_phone']);
        $customer->PrimaryPhone = $phone;
    }
    if (!empty(trim($row['fax'] ?? ''))) {
        $fax = new IPPTelephoneNumber();
        $fax->FreeFormNumber = trim($row['fax']);
        $customer->Fax = $fax;
    }

    $addr = _build_addr_obj($row);
    if ($addr !== null) {
        $customer->BillAddr = $addr;
    }

    // Only use quickbooks_ref as a QBO ID if it's numeric — old FileMaker values are not
    $raw_ref = trim($row['quickbooks_ref'] ?? '');
    $existing_qbo_id = is_numeric($raw_ref) ? $raw_ref : '';

    if ($existing_qbo_id !== '') {
        $customer->Id        = $existing_qbo_id;
        $customer->SyncToken = _get_sync_token($qbo, 'customer', $existing_qbo_id);
        $result = $qbo->Update($customer);
    } else {
        $result = $qbo->Add($customer);
    }

    $error = $qbo->getLastError();
    if ($error) {
        $msg = $error->getResponseBody() ?? $error->getMessage();
        error_log("QBO customer sync failed for warranty_co {$warranty_co_id}: " . $msg);
        return ['success' => false, 'error' => $msg];
    }

    $qbo_id = (string)$result->Id;

    $upd = $db->prepare('UPDATE warranty_co SET quickbooks_ref = ? WHERE warranty_co_id = ?');
    $upd->bind_param('si', $qbo_id, $warranty_co_id);
    $upd->execute();
    $upd->close();

    log_audit('qbo.customer.sync', 'warranty_co', $warranty_co_id, ['qbo_id' => $qbo_id]);

    return ['success' => true, 'qbo_id' => $qbo_id];
}

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

function _build_addr_obj(array $row): ?IPPPhysicalAddress {
    $line1   = trim($row['address']    ?? '');
    $city    = trim($row['city']       ?? '');
    $state   = trim($row['state_code'] ?? '');
    $zip     = trim($row['zip']        ?? '');
    $country = trim($row['country']    ?? '');

    if ($line1 === '' && $city === '') return null;

    $addr = new IPPPhysicalAddress();
    if ($line1   !== '') $addr->Line1                  = $line1;
    if ($city    !== '') $addr->City                   = $city;
    if ($state   !== '') $addr->CountrySubDivisionCode = $state;
    if ($zip     !== '') $addr->PostalCode             = $zip;
    if ($country !== '') $addr->Country                = $country;

    return $addr;
}

function _split_name(string $name): array {
    $parts = explode(' ', trim($name), 2);
    return [$parts[0] ?? '', $parts[1] ?? ''];
}

function _get_sync_token($qbo, string $type, string $id): string {
    try {
        $entity = $type === 'vendor'
            ? $qbo->FindById(new IPPVendor(),   $id)
            : $qbo->FindById(new IPPCustomer(), $id);
        return (string)($entity->SyncToken ?? '0');
    } catch (Throwable $e) {
        return '0';
    }
}
