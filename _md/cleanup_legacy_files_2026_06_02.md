# Legacy File Cleanup — 2026-06-02

Audit and removal of legacy FileMaker-era files and orphaned assets as part of the migration to MySQL-driven inspector/, client/, and office/ portals.

---

## Asset Folders Removed

| Folder | Reason |
|---|---|
| `jquery/` | Entirely orphaned — no references anywhere (jquery-1.6.2, jquery-ui-1.8.15) |
| `Libraries/fancybox/` | No references found in any PHP or HTML file |
| `Libraries/jquery-ui/` | No references found in any active page |
| `Libraries/fontawesome/` | Only referenced by deleted FM-era pages |
| `Libraries/jquery/` | Only referenced by deleted FM-era pages (jquery-1.4.3) |
| `Libraries/` | Empty after subfolders removed |
| `Magic/` | Only referenced by deleted FM-era pages (MagicZoom, MagicThumb) |

## js/ Orphans Removed

| File | Reason |
|---|---|
| `js/default.js` | Old flexslider gallery initializer, not loaded anywhere |
| `js/jquery.flexslider.js` | Only referenced from deleted default.js |
| `js/respond.min.js` | IE8 HTML5 polyfill — obsolete |
| `js/LICENSE-flexSlider.txt` | License for removed library |

**Remaining in js/:** `fia-media.js` (active), `recaptchav2.js` (active), `usableforms.js` (active)

---

## Legacy Root PHP Files Removed

### FM-Referencing Pages (41 files)
Any page referencing `FileMaker/` or `Connections/` was confirmed legacy — replaced by the new MySQL-driven portals.

```
admin_display.php          display_T2.php             NWCreport.php
admin_verify.php           display_tire.php           NWCreport_2.php
Confirm.php                employ_form2-BU.php        NWCreport_T.php
Confirm_Add.php            employ_form2.php           NWC_add.php
Confirm_Complete.php       employ_form_cap.php        ShopWsheet.php
connect_form2.php          FileMaker.php              ShopWsheet_T.php
display1.php               FileMaker18.php            sysmonitor.php
display2.php               inspect_listing.php        Verify_Inspect.php
display_A.php              inspect_message.php        Verify_Warco.php
display_report.php         Inspect_photos.php         listing.php
display_report_test.php    Inspect_photos_captions.php        Listing_NWC.php
display_T.php              Inspect_photos_captions_full.php   Media_UploadResult.php
display_T1.php             Inspect_photos_mobile.php  messaging_Client.php
                           Inspect_photos_update.php  messaging_Inspect.php
```

### Additional Legacy Portal Pages (16 files)
No FM reference but part of old portals, replaced by inspector/, client/, office/.

```
Inspector_login.php        admin_login.php            search_NWC.php
logout_Inspect.php         admin_search.php           search_NWC-new.php
Media_Upload.php           admin_photos.php           search-new.php
Media_Upload_mobile.php    admin_photo_report.php     search2.php
NWC_photos.php             Confirm_Update.php         index_wp.php
```

### Other Legacy/Orphaned Files

| File | Reason |
|---|---|
| `emailtest.php` | Dev test file using old PHPMailer-master, debug mode on |
| `employ_form.htm` | Submitted to deleted employ_form2.php |
| `employ_form.html` | Submitted to deleted employ_form2.php |
| `employment.htm` | Old Dreamweaver static page, superseded by opportunities.php |
| `makedir.php` | Created C:\pix\{fia} dirs for old Aurigma upload system |
| `EmailRequest.php` | No references found anywhere |
| `WorkRequest.php` | No references found anywhere |
| `google4ebe6967afed6b01.html` | Old Google Search Console verification file |
| `googlebb958b91876bca1a.html` | Old Google Search Console verification file |

---

## Duplicate Files Consolidated

| File | Action |
|---|---|
| `fia1.pdf` (root) | Deleted — duplicate of Docs/fia1.pdf |
| `images/PDFs/fia1.pdf` | Deleted — duplicate of Docs/fia1.pdf |
| `Docs/fia1.pdf` | Kept as canonical location |

**References updated:**
- `guidelines.php` lines 39 and 288: `fia1.pdf` → `/Docs/fia1.pdf`
- `opportunities.php` line 162: already pointed to `/Docs/fia1.pdf`, no change needed

---

## Subdirectory Files Kept (FM-referencing but retained)

| File | Reason |
|---|---|
| `pdf/append_pdf.php` | PDF generation — kept pending migration |
| `pdf/append_pdf_2.php` | PDF generation — kept pending migration |
| `pdf/append_test.php` | PDF test — kept pending migration |
| `office/tools/import_inspectors.php` | Data import tool — kept pending migration |

---

## Outstanding Items

- `pdf/emailPDF.php` line 18: hardcoded `NWCreport.php` URL in customer emails sent to GEICO — must be updated to new `client/` portal URL before fully retiring NWCreport.php workflow.
- `employ_form2.php`: to be restored (processes employment application form submissions from `employ_form.php`).

---

## What Remains in Root (Active Public Pages)

```
about-us.php               guidelines.php             thankyou.php
appraisals.php             index.php                  thankyou_resume.php
appraisal-choose-right.php inspections.php            employ_form.php
connectform.php            opportunities.php          AppRequest.php
```
