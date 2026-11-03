<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use App\Policies\EnrollmentNotePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnrollmentNotePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_and_assigned_coaches_can_view_or_create_notes(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $otherCoach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $this->assignCoach($certification, $coach, $admin);
        $enrollment = Enrollment::factory()->for($student)->for($certification)->create();
        $note = EnrollmentNote::factory()->for($enrollment)->for($coach, 'author')->create();
        $policy = app(EnrollmentNotePolicy::class);

        foreach ([$admin, $coach] as $allowedUser) {
            $this->assertTrue($policy->viewAny($allowedUser, $enrollment));
            $this->assertTrue($policy->view($allowedUser, $note));
            $this->assertTrue($policy->create($allowedUser, $enrollment));
        }

        foreach ([$student, $otherCoach] as $deniedUser) {
            $this->assertFalse($policy->viewAny($deniedUser, $enrollment));
            $this->assertFalse($policy->view($deniedUser, $note));
            $this->assertFalse($policy->create($deniedUser, $enrollment));
        }
    }

    public function test_coaches_can_manage_only_their_own_notes_and_admin_can_manage_any_note(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $otherCoach = User::factory()->coach()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $this->assignCoach($certification, $coach, $admin);
        $this->assignCoach($certification, $otherCoach, $admin);
        $enrollment = Enrollment::factory()->for($student)->for($certification)->create();
        $ownNote = EnrollmentNote::factory()->for($enrollment)->for($coach, 'author')->create();
        $otherNote = EnrollmentNote::factory()->for($enrollment)->for($otherCoach, 'author')->create();
        $policy = app(EnrollmentNotePolicy::class);

        $this->assertTrue($policy->update($coach, $ownNote));
        $this->assertTrue($policy->delete($coach, $ownNote));
        $this->assertFalse($policy->update($coach, $otherNote));
        $this->assertFalse($policy->delete($coach, $otherNote));
        $this->assertTrue($policy->update($admin, $otherNote));
        $this->assertTrue($policy->delete($admin, $otherNote));
        $this->assertFalse($policy->update($student, $ownNote));
        $this->assertFalse($policy->delete($student, $ownNote));
    }

    public function test_deleted_enrollment_notes_are_not_accessible(): void
    {
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->create();
        $note = EnrollmentNote::factory()->for($enrollment)->create();
        $enrollment->delete();
        $policy = app(EnrollmentNotePolicy::class);

        $this->assertFalse($policy->view($admin, $note));
        $this->assertFalse($policy->update($admin, $note));
        $this->assertFalse($policy->delete($admin, $note));
    }

    private function assignCoach(Certification $certification, User $coach, User $admin): void
    {
        CertificationCoachAssignment::query()->create([
            'id' => (string) Str::ulid(),
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);
    }
}
