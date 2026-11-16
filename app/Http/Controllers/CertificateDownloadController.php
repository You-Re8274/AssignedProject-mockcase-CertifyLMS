<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Certificate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CertificateDownloadController extends Controller
{
    public function __invoke(Certificate $certificate): StreamedResponse
    {
        $this->authorize('download', $certificate);

        $path = $certificate->pdfStoragePath();
        if ($path === null || ! $certificate->hasPdfFile()) {
            abort(404);
        }

        return Storage::disk('private')->download(
            $path,
            'certificate-'.$certificate->id.'.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }
}
