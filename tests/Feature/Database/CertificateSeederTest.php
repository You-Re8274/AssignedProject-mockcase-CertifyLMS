<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Certificate;
use App\Models\Certification;
use App\Models\User;
use Database\Seeders\CertificateSeeder;
use Database\Seeders\EnrollmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CertificateSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_graduated_student_seeder_creates_certificate_pdf_in_private_storage(): void
    {
        Storage::fake('private');
        $student = User::factory()->student()->graduated()->create([
            'plan_expires_at' => now()->subDays(30),
        ]);
        Certification::factory()->published()->create();

        app(CertificateSeeder::class)->run();

        $certificate = Certificate::query()
            ->where('user_id', $student->id)
            ->sole();

        $this->assertSame(
            $certificate->issued_at->toDateTimeString(),
            $certificate->enrollment->passed_at->toDateTimeString(),
        );
        $this->assertTrue($certificate->hasPdfFile());
        $this->assertStringStartsWith(
            '%PDF-',
            Storage::disk('private')->get($certificate->pdfStoragePath()),
        );
    }

    public function test_enrollment_seeder_creates_pdf_for_passed_demo_enrollments(): void
    {
        Storage::fake('private');
        Certification::factory()->published()->create();

        for ($i = 0; $i < 8; $i++) {
            User::factory()->student()->inProgress()->create([
                'email' => "certificate-seeder-{$i}@certify-lms.test",
            ]);
        }

        app(EnrollmentSeeder::class)->run();

        $certificates = Certificate::query()->get();
        $this->assertNotEmpty($certificates);

        foreach ($certificates as $certificate) {
            $this->assertTrue($certificate->hasPdfFile());
            $this->assertStringStartsWith(
                '%PDF-',
                Storage::disk('private')->get($certificate->pdfStoragePath()),
            );
        }
    }
}
