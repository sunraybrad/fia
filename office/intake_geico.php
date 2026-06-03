<?php
/**
 * intake_geico.php — Geico email inspection intake
 *
 * Staff pastes a raw Geico inspection request email, clicks Parse,
 * reviews/edits the pre-filled form, then saves a new inspection record.
 */

require_once 'C:/inetpub/fia_private/config.php';
require_once 'C:/inetpub/fia_private/db.php';
require_once __DIR__ . '/includes/auth.php';
init_session();
require_office();

$db = get_db();

// Flash from redirect
$flash = null;
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}


$page_title = 'New Geico Intake';
$active_nav = 'inspections';
require __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center mb-3 gap-2">
    <a href="/office/index.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Back
    </a>
    <h4 class="mb-0"><i class="bi bi-envelope-open"></i> New Geico Inspection Request</h4>
</div>

<!-- Step 1: Paste -->
<div id="step-paste" class="card mb-4">
    <div class="card-header fw-semibold">Step 1 — Paste Email Body</div>
    <div class="card-body">
        <textarea id="email-body" class="form-control font-monospace"
                  rows="12" placeholder="Paste the full Geico email body here…"></textarea>
        <div class="mt-2 d-flex gap-2 align-items-center">
            <button id="btn-parse" class="btn btn-primary">
                <i class="bi bi-magic"></i> Parse
            </button>
            <span id="parse-spinner" class="spinner-border spinner-border-sm text-primary d-none" role="status"></span>
            <span id="parse-error" class="text-danger small d-none"></span>
        </div>
    </div>
</div>

<!-- Step 2: Review form (hidden until parsed) -->
<div id="step-review" class="d-none">

<form method="POST" action="/office/save_intake.php" id="intake-form">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <!-- Raw email body stored for reference -->
    <input type="hidden" name="email_body" id="f-email_body">

    <div class="card mb-4">
        <div class="card-header fw-semibold">Step 2 — Review &amp; Save</div>
        <div class="card-body">

            <hr>

            <div class="row g-3">

                <!-- Claim / VIN row -->
                <div class="col-md-4">
                    <label class="form-label">Claim Number</label>
                    <input type="text" name="claim_number" id="f-claim_number" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Full VIN</label>
                    <input type="text" name="complete_vin" id="f-complete_vin" class="form-control" maxlength="20">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Last 8 of VIN</label>
                    <input type="text" name="vin" id="f-vin" class="form-control" maxlength="20">
                </div>

                <!-- Insured / Loss Date -->
                <div class="col-md-6">
                    <label class="form-label">Policyholder / Insured</label>
                    <input type="text" name="insured" id="f-insured" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Loss Date</label>
                    <input type="date" name="date_called_in" id="f-date_called_in" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Requested Appt Date</label>
                    <input type="date" name="eta" id="f-eta" class="form-control">
                </div>

                <!-- Vehicle -->
                <div class="col-md-2">
                    <label class="form-label">Year</label>
                    <input type="text" name="year" id="f-year" class="form-control" maxlength="10">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Make</label>
                    <input type="text" name="make" id="f-make" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Model</label>
                    <input type="text" name="model" id="f-model" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Mileage</label>
                    <input type="text" name="mileage" id="f-mileage" class="form-control" maxlength="20">
                </div>

                <!-- Shop -->
                <div class="col-md-6">
                    <label class="form-label">Repair Facility Name</label>
                    <input type="text" name="repair_shop" id="f-repair_shop" class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Contact</label>
                    <input type="text" name="contact" id="f-contact" class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" id="f-address" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">City</label>
                    <input type="text" name="city" id="f-city" class="form-control">
                </div>
                <div class="col-md-1">
                    <label class="form-label">State</label>
                    <input type="text" name="state_code" id="f-state_code" class="form-control" maxlength="10">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Zip</label>
                    <input type="text" name="zip" id="f-zip" class="form-control" maxlength="10">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone_number" id="f-phone_number" class="form-control" maxlength="30">
                </div>

                <!-- Instructions -->
                <div class="col-12">
                    <label class="form-label">Inspection Instructions</label>
                    <textarea name="customer_complaint" id="f-customer_complaint"
                              class="form-control" rows="4"></textarea>
                </div>

            </div><!-- /.row -->

        </div><!-- /.card-body -->
        <div class="card-footer d-flex gap-2">
            <button type="submit" class="btn btn-success">
                <i class="bi bi-plus-circle"></i> Create Inspection
            </button>
            <button type="button" class="btn btn-outline-secondary" id="btn-reset">
                <i class="bi bi-arrow-counterclockwise"></i> Start Over
            </button>
        </div>
    </div><!-- /.card -->

</form>
</div><!-- /#step-review -->

<script>
(function () {
    const emailBody   = document.getElementById('email-body');
    const btnParse    = document.getElementById('btn-parse');
    const spinner     = document.getElementById('parse-spinner');
    const errMsg      = document.getElementById('parse-error');
    const stepReview  = document.getElementById('step-review');
    const btnReset    = document.getElementById('btn-reset');

    // Map parsed JSON keys to form field IDs
    const FIELD_MAP = {
        claim_number:        'f-claim_number',
        complete_vin:        'f-complete_vin',
        vin:                 'f-vin',
        insured:             'f-insured',
        date_called_in:      'f-date_called_in',
        eta:                 'f-eta',
        year:                'f-year',
        make:                'f-make',
        model:               'f-model',
        mileage:             'f-mileage',
        repair_shop:         'f-repair_shop',
        address:             'f-address',
        city:                'f-city',
        state_code:          'f-state_code',
        zip:                 'f-zip',
        contact:             'f-contact',
        phone_number:        'f-phone_number',
        customer_complaint:  'f-customer_complaint',
    };

    btnParse.addEventListener('click', function () {
        const body = emailBody.value.trim();
        if (!body) {
            errMsg.textContent = 'Please paste an email body first.';
            errMsg.classList.remove('d-none');
            return;
        }
        errMsg.classList.add('d-none');
        spinner.classList.remove('d-none');
        btnParse.disabled = true;

        const fd = new FormData();
        fd.append('body', body);
        fd.append('csrf_token', document.querySelector('[name=csrf_token]') ?
            document.querySelector('[name=csrf_token]').value : '');

        fetch('/office/parse_geico_ajax.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.error) {
                    errMsg.textContent = data.error;
                    errMsg.classList.remove('d-none');
                    return;
                }

                // Populate plain fields
                for (const [key, id] of Object.entries(FIELD_MAP)) {
                    const el = document.getElementById(id);
                    if (el && data[key] !== undefined && data[key] !== null) {
                        el.value = data[key];
                    }
                }

                // Store raw email body in hidden field
                document.getElementById('f-email_body').value = body;

                // Show review section
                stepReview.classList.remove('d-none');
                stepReview.scrollIntoView({ behavior: 'smooth', block: 'start' });
            })
            .catch(() => {
                errMsg.textContent = 'Parse request failed. Check your connection and try again.';
                errMsg.classList.remove('d-none');
            })
            .finally(() => {
                spinner.classList.add('d-none');
                btnParse.disabled = false;
            });
    });

    btnReset.addEventListener('click', function () {
        stepReview.classList.add('d-none');
        emailBody.value = '';
        emailBody.focus();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
