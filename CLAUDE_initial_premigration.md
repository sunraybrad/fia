# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**FIA (Florida Inspection Associates)** is a PHP-based web application hosted on IIS/Windows Server. It provides vehicle inspection and appraisal services with separate portals for inspectors, clients, and administrators. The application integrates with FileMaker Server as its backend database.

- **Website**: https://fiainspectors.com
- **Type**: PHP web application running on IIS (Windows Server)
- **Database**: FileMaker Server (localhost 127.0.0.1, user: webman)
- **Main Dependencies**: FileMaker PHP API, TCPDF (PDF generation), PHPMailer, Bootstrap CSS

## Architecture

### High-Level Structure

The application is organized into distinct functional areas without a traditional MVC framework:

1. **Frontend Pages** (~73 PHP files in root directory)
   - Public-facing pages: index.php, about-us.php, inspections.php, appraisals.php
   - Inspector portal: search.php, listing.php, Inspect_photos.php, Inspector_login.php
   - Client portal: search_NWC.php, Listing_NWC.php, NWCreport.php
   - Admin panel: admin_display.php, admin_login.php, admin_verify.php
   - Report generation: display_report.php, display_tire.php, display_A.php

2. **API Endpoints** (/API/ directory)
   - Token-based auth: getToken.php
   - Inspection ingestion: RequestInspection.php (bearer token validation)
   - Test endpoints: hello.php, api_test.php, aegis_login.php
   - JSON request/response format

3. **Database Layer** (/Connections/ directory)
   - public.php: Main FileMaker connection (instance: $fm)
   - public2.php: Secondary connection
   - Credentials hardcoded: 127.0.0.1, user: webman, pass: webman

4. **Shared Components** (/includes/ directory)
   - topper.php: Navigation header and login links
   - footer.php: Page footer
   - validate_session.php: Session validation with login redirect
   - custom_functions.php: Time format utilities
   - connectform.php: Conditional request form

5. **Libraries**
   - /FileMaker/, /FileMaker18/: FileMaker PHP API
   - /vendor/: Composer packages (TCPDF, FPDI)
   - /Libraries/: jQuery UI, FancyBox, FontAwesome
   - /bootstrap/: Bootstrap CSS

6. **Static Assets**
   - /css/: Custom stylesheets
   - /images/: Feature-organized images
   - /Styles/: Dreamweaver templates

### Data Flow

**Inspection/Appraisal Requests**:
- Public form (connectform.php) submits to connect_form2.php
- Email sent via PHPMailer
- Data stored in FileMaker

**Inspector Workflow**:
- Login via Inspector_login.php, session stores in $_SESSION['usrID']
- View assigned inspections: listing.php, inspect_listing.php
- Upload photos: Media_Upload.php or Media_Upload_mobile.php
- Mark complete: Inspect_photos_update.php

**Client Access**:
- Login via search_NWC.php
- View reports: NWCreport.php, NWCreport_T.php

**Report Generation**:
- Multiple display variants
- TCPDF export in /pdf/
- Templates in /Templates/

### URL Routing (web.config)

HTTPS enforced globally. Rewrite rules:
- /about-us -> about-us.php
- /inspections -> inspections.php
- /appraisals -> appraisals.php
- /opportunities -> opportunities.php
- /inspector-guidelines -> guidelines.php
- API: /API/* -> API/*.php

## Key Configuration

### FileMaker Integration

All database operations use FileMaker PHP API. Standard pattern:

```php
// Connection (Connections/public.php)
$FM_NAME = 'database_name';  // 'Contacts', 'Inspections', etc.
$FM_HOST = '127.0.0.1';
$FM_USER = 'webman';
$FM_PASS = 'webman';
$fm = new FileMaker($FM_NAME, $FM_HOST, $FM_USER, $FM_PASS);

// Find records
$findCmd = $fm->newFindCommand('layout_name');
$findCmd->AddFindCriterion('field_name', 'search_value');
$result = $findCmd->execute();
if(FileMaker::isError($result)) { /* error */ }

// Add records
$addCmd = $fm->newAddCommand('layout_name', $values_array);
$result = $addCmd->execute();
```

### Session Management

- Validated via `validate_session.php` include
- Timeout redirects: search.php (inspectors) or admin_login.php (admins)
- Key variables: $_SESSION['usrID'], $_SESSION['usrName'], $_SESSION['start']

### API Authentication

- Token-based auth for external API calls
- Tokens stored in FileMaker (Contacts DB, API_tokens layout)
- Expiration: Unix epoch time
- Bearer token from Authorization header
- Example: RequestInspection.php validates token, stores JSON data

## Development Notes

### Common Workflows

**Adding a new public page**:
- Create .php file in root directory
- Include includes/topper.php for navigation
- Include includes/footer.php for footer
- Add URL rewrite rule in web.config if friendly URL needed

**Accessing FileMaker**:
- Always require: require_once('Connections/public.php');
- Use $fm object for find/add/edit commands
- Check for errors: if(FileMaker::isError($result)) { ... }

**API Development**:
- Implement token validation like RequestInspection.php
- Return JSON: header('Content-Type: application/json');
- Get request body: $json = file_get_contents('php://input');

### Important Files

- web.config: Server routing and HTTPS enforcement
- composer.json: PDF library dependencies
- includes/validate_session.php: Session authentication check
- API/RequestInspection.php: Reference implementation for token API
- Connections/public.php: Database connection configuration

### File Naming Conventions

- Page files: lowercase_underscore (inspect_photos.php, search_nwc.php)
- Form submissions: form2 after submit (connectform.php -> connect_form2.php)
- Report variants: use suffixes (_T, _A, _2 for template/appraisal/version)
- Mobile: *_mobile.php
- Test: *_test.php

### Deprecated Code

- FileMaker18/, FileMaker18.php: Older FileMaker API version
- Aurigma_8.523/, Aurigma_8.66/: Legacy image upload libraries
- index_wp.php: WordPress version (not in use)
- _notes/, _vti_cnf/: Dreamweaver metadata (not runtime-relevant)

## Build and Deployment

### No Build Step Required

Traditional PHP application without build tooling. Files deploy directly to web root.

### Dependencies

Install via Composer:
```bash
composer install
```

Packages:
- tecnickcom/tcpdf: PDF generation
- setasign/fpdi-tcpdf: PDF manipulation (overlay/stamp)

### Local Setup

- Requires IIS with PHP support
- Requires FileMaker Server running on localhost (127.0.0.1)
- Requires HTTPS certificate (web.config redirects HTTP to HTTPS)

## Codebase Characteristics

1. **Procedural PHP**: No framework; pages directly query FileMaker without abstraction

2. **Session-based Auth**: Uses $_SESSION['usrID'] without middleware layer

3. **Report Duplication**: display_report.php, display_tire.php, display_A.php, display_T.php share code; consolidate with template variables

4. **Hardcoded Credentials**: FileMaker credentials in Connections/public.php; move to environment variables for production

5. **Inline Queries**: Database queries constructed inline in page files, not in models/repositories

6. **Short PHP Tags**: Code uses both <?php and <? tags; ensure short_open_tag=On in php.ini

## Performance Notes

- FileMaker queries: synchronous, no caching layer present
- TCPDF: CPU-intensive; consider async generation for batch processing
- Session validation: happens on every protected page load
- Large reports: load entire datasets into memory

Consider implementing query result caching for frequently-accessed data.
