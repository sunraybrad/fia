<?php
 ini_set('display_errors', 1);
 error_reporting(E_ALL);

require_once('C:\inetpub\wwwroot\FIA\vendor\autoload.php'); // Autoload TCPDF and FPDI
$FM_NAME = 'inspections';
$layout = 'CMI';
require('C:\inetpub\wwwroot\FIA\Connections\public.php');

use setasign\Fpdi\Tcpdf\Fpdi;

class CustomPDF extends Fpdi {
    public $showHeader = true;
    public $fia = '';

    // Custom header
    public function Header() {
        if ($this->showHeader) {
            // Add your custom header content here
            $this->SetFont('helvetica', 'B', 10);
            $this->Cell(0, 10, 'Florida Inspection Associates Report: ' . $this->fia . ' - page ' . $this->getAliasNumPage(), 0, 1, 'C', 0, '', 0, false, 'T', 'M');
            $this->Ln(5);
        }
    }

    // Custom footer (optional)
    public function Footer() {
        // leave blank for no footer
    }
}

# find any reports to append images for
$report_find = $fm->newFindCommand($layout);
$report_find->AddFindCriterion('pdfAppended','Pending');
$report_result = $report_find->execute();
$reports   = $report_result->getRecords();
$report_count  = $report_result->getFoundSetCount();

if($report_count > 0){
    foreach($reports as $report){
        $fia = $report->getField('FIA Inspection Number');
        $uid = $report->getField('UUID');
        $recID = $report->getRecordID();
        echo "report found: ".$fia." record ID:".$recID."<br>";

        #$fia = isset($_GET['fia']) ? htmlspecialchars($_GET['fia']) : '';

        // Create a new FPDI CUSTOM object
        $pdf = new CustomPDF();
        $pdf->fia = $fia;
        // Disable the header for the existing pages
        $pdf->showHeader = false;

        // Path to the existing PDF
        $existingPdfPath = "C:/Program Files/FileMaker/FileMaker Server/Data/Documents/Reports//".$fia."_Report.pdf";
        $publicPdfPath = "C:/inetpub/wwwroot/FIA/PDFs/514098864753776//".$fia."_Report.pdf";

        // Import all pages from the existing PDF
        $pageCount = $pdf->setSourceFile($existingPdfPath);

        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            $templateId = $pdf->importPage($pageNo);
            $size = $pdf->getTemplateSize($templateId);

            // Add a page using the imported template
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($templateId);
        }
        // Enable the header for the new pages
        $pdf->showHeader = true;
        // Add a new page for images
        $pdf->AddPage('P', 'LETTER');

        // Set font
        $pdf->SetFont('helvetica', '', 12);

        // Fetch data from database
        /* $inspectionId = $_GET['id']; // Get the inspection ID from URL or form input
        $stmt = $pdo->prepare("SELECT * FROM inspection_images WHERE inspection_id = ?");
        $stmt->execute([$inspectionId]);
        $images = $stmt->fetchAll(); */
        $FM_NAME = 'Pictures';
        $layout = 'Listing';
        require('C:\inetpub\wwwroot\FIA\Connections\public.php');
        $pix_find = $fm->newFindCommand($layout);
        $pix_find->AddFindCriterion('ContractNo',$fia);
        $pix_result = $pix_find->execute();
        $images   = $pix_result->getRecords();
        $pix_count  = $pix_result->getFoundSetCount();

        echo "<br>".$pix_count." pix records found for ".$fia." :<br>";

        // Output images in 6-up format
        $imgWidth = 100; // Width of the image
        $imgHeight = 80; // Height of the image
        $x = 5; //PDF_MARGIN_LEFT;
        $y = $pdf->GetY() + 0; // Start position for images
        $column = 0;

        foreach ($images as $image) {
            echo $image->getField('ImagePath')."<br>";
            $imgPath = 'C:/pix/' . $fia . "/" . $image->getField('ImagePath');
            // Image( filename, left, top, width, height, type, link, align, resize, dpi, align, ismask, imgmask, border, fitbox, hidden, fitonpage)
            $pdf->Image($imgPath, $x, $y, $imgWidth, $imgHeight, '', '', '', true, 200, '', false, false, 1, true, false, false);

            // Update position for the next image
            $x += $imgWidth + 5; // Move to the next column
            $column++;

            if ($column == 2) { // Reset to the next row after 2 columns
                $x = 5; //PDF_MARGIN_LEFT;
                $y += $imgHeight + 0;
                $column = 0;
            }

            // Add a new page if needed
            if ($y + $imgHeight > $pdf->getPageHeight()) {
                $pdf->AddPage();
                $y = $pdf->GetY(); //PDF_MARGIN_TOP;
            }
        }

        // Output the new PDF (you can save it to a file or output directly to the browser)
        // $pdf->Output('inspection_report_combined.pdf', 'I'); // to browser
        $pdf->Output($publicPdfPath, 'F');

        echo "<br>PDF appended for report ".$fia."<br><br>";

        # send email to Geico
        include('C:\inetpub\wwwroot\FIA\pdf\emailPDF.php');

        # update FileMaker data
        $FM_NAME = 'Inspections';
        $layout = 'CMI';
        require('C:\inetpub\wwwroot\FIA\Connections\public.php');
        $edit = $fm->newEditCommand($layout, $recID);
        $edit->setField('eMailSent', 'Yes');
        $edit->setField('pdfAppended', 'Yes');
        $result = $edit->execute();

    } // end for each

} else {
    echo "no reports found";
}
?>