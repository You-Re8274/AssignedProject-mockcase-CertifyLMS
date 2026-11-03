<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Enrollment;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnrollmentNoteControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_coach_can_add_note_with_themselves_as_author(): void
    {
        [$admin, $coach, $student, $enrollment] = $this->assignedEnrollment();

        $response = $this->actingAs($coach)->post(route('enrollments.notes.store', $enrollment), [
            'body' => '面談前に進捗を確認する。',
            'user_id' => $admin->id,
        ]);

        $response->assertRedirect(route('enrollments.show', $enrollment));
        $this->assertDatabaseHas('enrollment_notes', [
            'enrollment_id' => $enrollment->id,
            'user_id' => $coach->id,
            'body' => '面談前に進捗を確認する。',
        ]);
    }

    public function test_admin_can_add_note_and_student_cannot_access_notes(): void
    {
        [$admin, , $student, $enrollment] = $this->assignedEnrollment();

        $this->actingAs($admin)
            ->post(route('enrollments.notes.store', $enrollment), ['body' => '管理者メモ'])
            ->assertRedirect(route('enrollments.show', $enrollment));

        $note = EnrollmentNote::query()->where('body', '管理者メモ')->firstOrFail();
        $this->assertSame($admin->id, $note->user_id);

        $this->actingAs($student)
            ->post(route('enrollments.notes.store', $enrollment), ['body' => '不可'])
            ->assertForbidden();
        $this->actingAs($student)
            ->get(route('enrollments.show', $enrollment))
            ->assertOk()
            ->assertDontSee('コーチメモ');
    }

    public function test_coach_cannot_add_note_to_unassigned_certification(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $enrollment = Enrollment::factory()->create();

        $this->actingAs($coach)
            ->post(route('enrollments.notes.store', $enrollment), ['body' => '担当外'])
            ->assertForbidden();
    }

    public function test_note_body_is_required_and_limited_to_2000_characters(): void
    {
        [, $coach, , $enrollment] = $this->assignedEnrollment();

        $this->actingAs($coach)
            ->from(route('enrollments.show', $enrollment))
            ->post(route('enrollments.notes.store', $enrollment), ['body' => ''])
            ->assertSessionHasErrors('body');

        $this->actingAs($coach)
            ->from(route('enrollments.show', $enrollment))
            ->post(route('enrollments.notes.store', $enrollment), ['body' => str_repeat('あ', 2001)])
            ->assertSessionHasErrors('body');
    }

    public function test_author_can_edit_and_delete_own_note_but_not_another_coaches_note(): void
    {
        [$admin, $coach, $student, $enrollment] = $this->assignedEnrollment();
        $otherCoach = User::factory()->coach()->inProgress()->create();
        $this->assignCoach($enrollment->certification, $otherCoach, $admin);
        $ownNote = EnrollmentNote::factory()->for($enrollment)->for($coach, 'author')->create();
        $otherNote = EnrollmentNote::factory()->for($enrollment)->for($otherCoach, 'author')->create();

        $this->actingAs($coach)
            ->get(route('enrollment-notes.edit', $ownNote))
            ->assertOk()
            ->assertViewIs('enrollment-note.edit');
        $this->actingAs($coach)
            ->patch(route('enrollment-notes.update', $ownNote), ['body' => '更新されたメモ'])
            ->assertRedirect(route('enrollments.show', $enrollment));
        $this->assertDatabaseHas('enrollment_notes', ['id' => $ownNote->id, 'body' => '更新されたメモ']);
        $this->actingAs($coach)
            ->get(route('enrollment-notes.edit', $otherNote))
            ->assertForbidden();
        $this->actingAs($coach)
            ->delete(route('enrollment-notes.destroy', $otherNote))
            ->assertForbidden();
        $this->assertDatabaseHas('enrollment_notes', ['id' => $otherNote->id]);
        $this->actingAs($coach)
            ->delete(route('enrollment-notes.destroy', $ownNote))
            ->assertRedirect(route('enrollments.show', $enrollment));
        $this->assertDatabaseMissing('enrollment_notes', ['id' => $ownNote->id]);
    }

    public function test_admin_can_edit_and_delete_any_coach_note(): void
    {
        [$admin, $coach, , $enrollment] = $this->assignedEnrollment();
        $note = EnrollmentNote::factory()->for($enrollment)->for($coach, 'author')->create();

        $this->actingAs($admin)
            ->patch(route('enrollment-notes.update', $note), ['body' => '管理者が修正'])
            ->assertRedirect(route('enrollments.show', $enrollment));
        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id, 'body' => '管理者が修正']);
        $this->actingAs($admin)
            ->delete(route('enrollment-notes.destroy', $note))
            ->assertRedirect(route('enrollments.show', $enrollment));
        $this->assertDatabaseMissing('enrollment_notes', ['id' => $note->id]);
    }

    public function test_enrollment_show_eager_loads_notes_and_authors_only_for_staff(): void
    {
        [$admin, $coach, $student, $enrollment] = $this->assignedEnrollment();
        $note = EnrollmentNote::factory()->for($enrollment)->for($coach, 'author')->create();

        $this->actingAs($coach)
            ->get(route('enrollments.show', $enrollment))
            ->assertOk()
            ->assertViewHas('enrollment', function (Enrollment $shownEnrollment) use ($note): bool {
                return $shownEnrollment->relationLoaded('notes')
                    && $shownEnrollment->notes->first()->is($note)
                    && $shownEnrollment->notes->first()->relationLoaded('author')
                    && $shownEnrollment->notes->first()->relationLoaded('enrollment');
            })
            ->assertSee($note->body);

        $this->actingAs($student)
            ->get(route('enrollments.show', $enrollment))
            ->assertOk()
            ->assertViewHas('enrollment', fn (Enrollment $shownEnrollment): bool => ! $shownEnrollment->relationLoaded('notes'))
            ->assertDontSee($note->body);

        $this->actingAs($admin)
            ->get(route('enrollments.show', $enrollment))
            ->assertOk()
            ->assertSee($note->body);
    }

    public function test_note_listing_query_count_does_not_grow_with_note_count(): void
    {
        [$admin, $coach, , $singleNoteEnrollment] = $this->assignedEnrollment();
        $multipleNoteEnrollment = Enrollment::factory()
            ->for(User::factory()->student()->inProgress()->create())
            ->for($singleNoteEnrollment->certification)
            ->create();
        EnrollmentNote::factory()->for($singleNoteEnrollment)->for($coach, 'author')->create();
        EnrollmentNote::factory()->count(5)->for($multipleNoteEnrollment)->for($coach, 'author')->create();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $this->actingAs($coach)->get(route('enrollments.show', $singleNoteEnrollment))->assertOk();
        $singleNoteQueryCount = $queryCount;

        $queryCount = 0;
        $this->actingAs($coach)->get(route('enrollments.show', $multipleNoteEnrollment))->assertOk();
        $multipleNotesQueryCount = $queryCount;

        $this->assertSame(
            $singleNoteQueryCount,
            $multipleNotesQueryCount,
            'メモ数が増えても詳細表示中のDBクエリ数は増えないこと',
        );
    }

    public function test_unauthenticated_user_cannot_create_or_view_notes(): void
    {
        $enrollment = Enrollment::factory()->create();
        $note = EnrollmentNote::factory()->for($enrollment)->create();

        $this->post(route('enrollments.notes.store', $enrollment), ['body' => '未認証'])
            ->assertRedirect(route('login'));
        $this->get(route('enrollment-notes.edit', $note))
            ->assertRedirect(route('login'));
    }

    /**
     * @return array{User, User, User, Enrollment}
     */
    private function assignedEnrollment(): array
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $this->assignCoach($certification, $coach, $admin);
        $enrollment = Enrollment::factory()->for($student)->for($certification)->create();

        return [$admin, $coach, $student, $enrollment];
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
