<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Certificate;

use App\Models\Certificate;
use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CertificateDownloadControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    public function test_graduated_student_can_download_their_own_certificate_as_an_attachment(): void
    {
        $student = User::factory()->student()->graduated()->create();
        $certificate = $this->createCertificateFor($student);
        $this->storePdf($certificate);

        $this->actingAs($student)
            ->get(route('certificates.download', $certificate))
            ->assertOk()
            ->assertDownload('certificate-'.$certificate->id.'.pdf')
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_student_cannot_download_another_students_certificate(): void
    {
        $owner = User::factory()->student()->graduated()->create();
        $certificate = $this->createCertificateFor($owner);
        $this->storePdf($certificate);
        $otherStudent = User::factory()->student()->inProgress()->create();

        $this->actingAs($otherStudent)
            ->get(route('certificates.download', $certificate))
            ->assertForbidden();
    }

    public function test_assigned_coach_can_download_but_unassigned_coach_cannot(): void
    {
        $owner = User::factory()->student()->graduated()->create();
        $certificate = $this->createCertificateFor($owner);
        $this->storePdf($certificate);
        $assignedCoach = User::factory()->coach()->inProgress()->create();
        $unassignedCoach = User::factory()->coach()->inProgress()->create();
        $admin = User::factory()->admin()->create();

        CertificationCoachAssignment::factory()->create([
            'certification_id' => $certificate->certification_id,
            'user_id' => $assignedCoach->id,
            'assigned_by_user_id' => $admin->id,
        ]);
        CertificationCoachAssignment::factory()->unassigned()->create([
            'certification_id' => $certificate->certification_id,
            'user_id' => $unassignedCoach->id,
            'assigned_by_user_id' => $admin->id,
        ]);

        $this->actingAs($assignedCoach)
            ->get(route('certificates.download', $certificate))
            ->assertOk()
            ->assertDownload('certificate-'.$certificate->id.'.pdf');

        $this->actingAs($unassignedCoach)
            ->get(route('certificates.download', $certificate))
            ->assertForbidden();
    }

    public function test_admin_can_download_any_certificate(): void
    {
        $owner = User::factory()->student()->graduated()->create();
        $certificate = $this->createCertificateFor($owner);
        $this->storePdf($certificate);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('certificates.download', $certificate))
            ->assertOk()
            ->assertDownload('certificate-'.$certificate->id.'.pdf');
    }

    public function test_missing_pdf_returns_not_found(): void
    {
        $student = User::factory()->student()->graduated()->create();
        $certificate = $this->createCertificateFor($student);

        $this->actingAs($student)
            ->get(route('certificates.download', $certificate))
            ->assertNotFound();
    }

    public function test_download_requires_authentication(): void
    {
        $student = User::factory()->student()->graduated()->create();
        $certificate = $this->createCertificateFor($student);

        $this->get(route('certificates.download', $certificate))
            ->assertRedirect(route('login'));
    }

    private function createCertificateFor(User $student): Certificate
    {
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()
            ->for($student)
            ->for($certification)
            ->passed()
            ->create();

        return Certificate::factory()
            ->forEnrollment($enrollment)
            ->create();
    }

    private function storePdf(Certificate $certificate): void
    {
        $path = $certificate->pdfStoragePath();
        $this->assertNotNull($path);
        Storage::disk('private')->put($path, '%PDF-test');
    }
}
