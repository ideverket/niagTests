<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/vendor/autoload.php';
// Disable font cache writes in the code directory, which need not be writable on the server.
if (!defined('FPDF_CACHE_MODE')) define('FPDF_CACHE_MODE', 1);
function new_pdf(string $orientation = 'P'): tFPDF {
    foreach (['DejaVuSansCondensed.ttf','DejaVuSansCondensed-Bold.ttf'] as $font) {
        if (!is_readable(dirname(__DIR__).'/vendor/setasign/tfpdf/font/unifont/'.$font)) {
            throw new RuntimeException('Diplomets typsnitt saknas eller kan inte läsas: '.$font);
        }
    }
    $pdf = new tFPDF($orientation, 'mm', 'A4');
    $pdf->AddFont('DejaVu', '', 'DejaVuSansCondensed.ttf', true);
    $pdf->AddFont('DejaVu', 'B', 'DejaVuSansCondensed-Bold.ttf', true);
    $pdf->SetMargins(18, 18, 18);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AddPage(); $pdf->SetFont('DejaVu', '', 11);
    return $pdf;
}
function diploma_pdf(array $a): string {
    $pdf = new_pdf('L');
    $pdf->SetTitle('NIAG - '.$a['test']['title'][$a['language']]);
    $pdf->SetDrawColor(36, 119, 80); $pdf->SetLineWidth(1.3);
    $pdf->Rect(9, 9, 279, 192); $pdf->SetLineWidth(.3); $pdf->Rect(12, 12, 273, 186);
    $pdf->Image(dirname(__DIR__).'/assets/niag-logo.jpg', 123, 22, 51);
    $pdf->SetY(57); $pdf->SetFont('DejaVu', '', 22); $pdf->Cell(0, 12, 'DIPLOM / CERTIFICATE', 0, 1, 'C');
    $name = $a['person']['name'];
    $size = 30; $pdf->SetFont('DejaVu', 'B', $size);
    while ($size > 12 && $pdf->GetStringWidth($name) > 245) $pdf->SetFont('DejaVu', 'B', --$size);
    $pdf->Ln(6); $pdf->MultiCell(0, 14, $name, 0, 'C');
    $pdf->Ln(8); $pdf->SetFont('DejaVu', '', 12);
    $pdf->MultiCell(0, 8, "Har genomfört testet med godkänt resultat.\nHas successfully completed the knowledge test.", 0, 'C');
    $pdf->Ln(5); $pdf->SetFont('DejaVu', '', 11);
    $pdf->MultiCell(0, 7, $a['test']['title'][$a['language']], 0, 'C');
    $pdf->Cell(0, 10, date('Y-m-d', $a['finishedAt']), 0, 1, 'C');
    $pdf->SetY(165); $pdf->SetFont('DejaVu', 'B', 10);
    $pdf->Cell(0, 8, 'NORDIC INSPECTION & AUDIT GROUP AB', 0, 1, 'C');
    $pdf->SetFont('DejaVu', '', 8);
    $pdf->Cell(0, 6, 'TESTDIPLOM / SAMPLE CERTIFICATE - ej ett officiellt kompetensbevis', 0, 1, 'C');
    $pdf->Cell(0, 6, 'Referens: '.$a['id'], 0, 1, 'C');
    return $pdf->Output('S');
}
function bank_pdf(array $bank): string {
    $pdf = new_pdf(); $pdf->SetTitle($bank['name']);
    $pdf->SetFont('DejaVu', 'B', 16); $pdf->MultiCell(0, 9, $bank['name']);
    foreach ($bank['questions'] as $i => $q) {
        $pdf->AddPage(); $pdf->SetFont('DejaVu', 'B', 12);
        $pdf->MultiCell(0, 7, 'Fråga '.($i+1).' · '.$q['id']);
        foreach (['sv'=>'Svenska','en'=>'English'] as $lang=>$label) {
            $pdf->Ln(4); $pdf->SetFont('DejaVu', 'B', 10); $pdf->Cell(0, 7, $label, 0, 1);
            $pdf->SetFont('DejaVu', '', 11); $pdf->MultiCell(0, 6, $q['text'][$lang]); $pdf->Ln(2);
            foreach ($q['options'][$lang] as $letter=>$option) $pdf->MultiCell(0, 6, "$letter. $option");
        }
        $pdf->Ln(4); $pdf->SetFont('DejaVu', 'B', 11); $pdf->Cell(0, 7, 'Rätt svar: '.$q['correct'], 0, 1);
        if ($q['image']) {
            $file = data_path('uploads/'.$q['image']);
            $info = getimagesize($file); $width = min(75, $info[0]*.2); $height = $width*$info[1]/$info[0];
            if ($pdf->GetY()+$height > 275) $pdf->AddPage();
            $pdf->Image($file, 18, $pdf->GetY()+3, $width);
        }
    }
    return $pdf->Output('S');
}
