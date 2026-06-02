<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once('C:\inetpub\wwwroot\FIA\vendor\autoload.php'); // Autoload TCPDF and FPDI

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

$FM_NAME = 'inspections';
$layout = 'CMI';
require('C:\inetpub\wwwroot\FIA\Connections\public.php');
# find any reports to append images for
$report_find = $fm->newFindCommand($layout);
$report_find->AddFindCriterion('pdfAppended','Pending');
$report_result = $report_find->execute();
$reports = $report_result->getRecords();
$report_count = $report_result->getFoundSetCount();

if($report_count > 0){
    foreach($reports as $report){
        $fia = $report->getField('FIA Inspection Number');
        $uid = $report->getField('UUID');
        $recID = $report->getRecordID();
        echo "report found: ".$fia." record ID:".$recID."\r\n";

        // Path to the existing PDF
        $existingPdfPath = "C:/Program Files/FileMaker/FileMaker Server/Data/Documents/Reports//".$fia."_Report.pdf";
        $publicPdfPath = "C:/inetpub/wwwroot/FIA/PDFs/514098864753776//".$fia."_Report.pdf";

        // Fetch pictures from database
        $FM_NAME = 'Pictures';
        $layout = 'Listing';
        require('C:\inetpub\wwwroot\FIA\Connections\public.php');
        $pix_find = $fm->newFindCommand($layout);
        $pix_find->AddFindCriterion('ContractNo',$fia);
        $pix_result = $pix_find->execute();

        // check for existance of pictures
        if(FileMaker::isError($pix_result)){
            echo"Pictures not found for ".$fia."... processing additional files\r\n";
            // Delete the original PDF
            if (file_exists($existingPdfPath)) {
                unlink($existingPdfPath);
            }
            # update FileMaker data
            $FM_NAME = 'Inspections';
            $layout = 'CMI';
            require('C:\inetpub\wwwroot\FIA\Connections\public.php');
            $edit = $fm->newEditCommand($layout, $recID);
            $edit->setField('eMailSent', 'Cancel');
            $edit->setField('pdfAppended', 'Cancel');
            $result = $edit->execute();

            continue; // Skip to the next report in the loop
        }
        $pix_count  = $pix_result->getFoundSetCount();
        $images   = $pix_result->getRecords();

        // Create a new FPDI CUSTOM object
        $pdf = new CustomPDF();
        $pdf->fia = $fia;
        // Disable the header for the existing pages
        $pdf->showHeader = false;

        // Check if the original invoice file exists
        if (!file_exists($existingPdfPath)) {
            echo"File not found: ".$existingPdfPath."... processing additional files\r\n";
            continue; // Skip to the next report in the loop
        }

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

        echo "\r\n".$pix_count." pix records found for ".$fia." :\r\n";

        // Output images in 6-up format
        $imgWidth = 100; // Width of the image
        $imgHeight = 80; // Height of the image
        $x = 5; //PDF_MARGIN_LEFT;
        $y = $pdf->GetY() + 0; // Start position for images
        $column = 0;

        foreach ($images as $image) {
            echo $image->getField('ImagePath')."\r\n";
            $imgPath = 'C:/pix/' . $fia . "/" . $image->getField('ImagePath');

            // Get the file extension
            $fileInfo = pathinfo($imgPath);
            $fileExtension = strtolower($fileInfo['extension']); // Get file extension and convert to lowercase

            // list of movie file extensions to skip
            $movieExtensions = ['mp4', 'mov', 'avi', 'mkv', 'wmv', 'flv'];

            // Check if the file is a movie
            if (in_array($fileExtension, $movieExtensions)) {
                // Skip this file if it's a movie
                continue;
            }
    
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

        // Delete the original PDF
        if (file_exists($existingPdfPath)) {
            unlink($existingPdfPath);
        }

        echo "\r\nPDF appended for report ".$fia."\r\n";

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
        
        // die;
    } // end for each

} else {
    echo "no reports found";
}
?>