<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Certificate;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use RuntimeException;

class CertificatePdfService
{
    public function store(Certificate $certificate): void
    {
        $path = $certificate->pdfStoragePath();
        if ($path === null) {
            throw new RuntimeException('修了証PDFの保存先が不正です。');
        }

        $certificate->loadMissing(['user', 'certification']);

        $temporaryDirectory = storage_path('framework/cache/mpdf');
        File::ensureDirectoryExists($temporaryDirectory);

        $pdf = new Mpdf([
            'format' => 'A4-L',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'tempDir' => $temporaryDirectory,
        ]);
        $pdf->WriteHTML(view('certificates.pdf', compact('certificate'))->render());

        $contents = $pdf->Output('', Destination::STRING_RETURN);
        if (! is_string($contents) || ! str_starts_with($contents, '%PDF-')) {
            throw new RuntimeException('修了証PDFを生成できませんでした。');
        }

        if (! Storage::disk('private')->put($path, $contents)) {
            throw new RuntimeException('修了証PDFを保存できませんでした。');
        }
    }
}
