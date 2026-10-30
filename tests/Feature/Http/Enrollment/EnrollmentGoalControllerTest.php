<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Enrollment;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnrollmentGoalControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_create_update_delete_and_toggle_own_goal(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        $this->actingAs($student)
            ->post(route('enrollments.goals.store', $enrollment), [
                'title' => '模擬試験に合格する',
                'description' => '週末に復習する',
                'target_date' => now()->addMonth()->toDateString(),
            ])
            ->assertRedirect(route('enrollments.show', $enrollment));

        $goal = EnrollmentGoal::query()->where('enrollment_id', $enrollment->id)->firstOrFail();
        $this->assertSame('模擬試験に合格する', $goal->title);

        $this->get(route('enrollment-goals.edit', $goal))
            ->assertOk()
            ->assertViewIs('enrollment-goal.edit');

        $this->patch(route('enrollment-goals.update', $goal), [
            'title' => '模擬試験に合格する（更新）',
            'description' => null,
            'target_date' => null,
        ])->assertRedirect(route('enrollments.show', $enrollment));

        $this->assertSame('模擬試験に合格する（更新）', $goal->fresh()->title);
        $this->assertNull($goal->fresh()->target_date);

        $this->post(route('enrollment-goals.markAchieved', $goal))
            ->assertRedirect(route('enrollments.show', $enrollment));
        $this->assertNotNull($goal->fresh()->achieved_at);

        $this->delete(route('enrollment-goals.unmarkAchieved', $goal))
            ->assertRedirect(route('enrollments.show', $enrollment));
        $this->assertNull($goal->fresh()->achieved_at);

        $this->delete(route('enrollment-goals.destroy', $goal))
            ->assertRedirect(route('enrollments.show', $enrollment));
        $this->assertDatabaseMissing('enrollment_goals', ['id' => $goal->id]);
    }

    public function test_enrollment_detail_shows_goal_list_in_display_order(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $later = EnrollmentGoal::factory()->for($enrollment)->create([
            'title' => '期日が先',
            'target_date' => now()->addWeeks(3)->toDateString(),
        ]);
        $earlier = EnrollmentGoal::factory()->for($enrollment)->create([
            'title' => '期日が近い',
            'target_date' => now()->addWeek()->toDateString(),
        ]);
        $achieved = EnrollmentGoal::factory()->for($enrollment)->achieved()->create([
            'title' => '達成済み',
            'target_date' => now()->toDateString(),
        ]);

        $this->actingAs($student)
            ->get(route('enrollments.show', $enrollment))
            ->assertOk()
            ->assertSeeInOrder([$earlier->title, $later->title, $achieved->title])
            ->assertViewHas('enrollment', fn (Enrollment $loaded): bool => $loaded->goals
                ->pluck('id')
                ->all() === [$earlier->id, $later->id, $achieved->id]);
    }

    public function test_assigned_coach_and_admin_can_view_goals_without_mutation_controls(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $this->assignCoach($certification, $coach, $admin);
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();
        $goal = EnrollmentGoal::factory()->for($enrollment)->create(['title' => '表示可能な目標']);

        foreach ([$coach, $admin] as $viewer) {
            $this->actingAs($viewer)
                ->get(route('enrollments.show', $enrollment))
                ->assertOk()
                ->assertSeeText($goal->title)
                ->assertDontSee(route('enrollment-goals.edit', $goal));
        }
    }

    public function test_unassigned_coach_cannot_view_enrollment_goals(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $enrollment = Enrollment::factory()->learning()->create();

        $this->actingAs($coach)
            ->get(route('enrollments.show', $enrollment))
            ->assertForbidden();
    }

    public function test_other_student_and_staff_cannot_mutate_goals(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();
        $goal = EnrollmentGoal::factory()->for($enrollment)->create();

        foreach ([$otherStudent, $coach, $admin] as $user) {
            $this->actingAs($user)
                ->patchJson(route('enrollment-goals.update', $goal), ['title' => '不正な更新'])
                ->assertForbidden();
        }

        $this->actingAs($otherStudent)
            ->postJson(route('enrollments.goals.store', $enrollment), ['title' => '不正な追加'])
            ->assertForbidden();

        $this->assertNotSame('不正な更新', $goal->fresh()->title);
    }

    public function test_goal_validation_rejects_missing_or_oversized_fields_and_invalid_date(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $this->actingAs($student);

        $this->from(route('enrollments.show', $enrollment))
            ->post(route('enrollments.goals.store', $enrollment), ['title' => ''])
            ->assertSessionHasErrors('title');

        $this->from(route('enrollments.show', $enrollment))
            ->post(route('enrollments.goals.store', $enrollment), ['title' => str_repeat('a', 101)])
            ->assertSessionHasErrors('title');

        $this->from(route('enrollments.show', $enrollment))
            ->post(route('enrollments.goals.store', $enrollment), [
                'title' => '適切なタイトル',
                'description' => str_repeat('a', 1001),
            ])
            ->assertSessionHasErrors('description');

        $this->from(route('enrollments.show', $enrollment))
            ->post(route('enrollments.goals.store', $enrollment), [
                'title' => '適切なタイトル',
                'target_date' => 'not-a-date',
            ])
            ->assertSessionHasErrors('target_date');

        $goal = EnrollmentGoal::factory()->for($enrollment)->create();
        $this->from(route('enrollment-goals.edit', $goal))
            ->patch(route('enrollment-goals.update', $goal), [
                'title' => '',
                'description' => str_repeat('a', 1001),
            ])
            ->assertSessionHasErrors(['title', 'description']);

        $this->assertDatabaseCount('enrollment_goals', 1);
    }

    public function test_inactive_student_and_non_learning_enrollment_cannot_manage_goals(): void
    {
        foreach (['invited', 'graduated', 'withdrawn'] as $inactiveState) {
            $inactiveStudent = User::factory()->student()->{$inactiveState}()->create();
            $inactiveEnrollment = Enrollment::factory()->for($inactiveStudent)->learning()->create();

            $this->actingAs($inactiveStudent)
                ->get(route('enrollments.show', $inactiveEnrollment))
                ->assertForbidden();

            $this->actingAs($inactiveStudent)
                ->postJson(route('enrollments.goals.store', $inactiveEnrollment), ['title' => '禁止'])
                ->assertForbidden();
        }

        $student = User::factory()->student()->inProgress()->create();
        $passedEnrollment = Enrollment::factory()->for($student)->passed()->create();

        $this->actingAs($student)
            ->postJson(route('enrollments.goals.store', $passedEnrollment), ['title' => '禁止'])
            ->assertForbidden();
    }

    public function test_hard_deleting_enrollment_cascades_to_goals(): void
    {
        $enrollment = Enrollment::factory()->create();
        $goal = EnrollmentGoal::factory()->for($enrollment)->create();

        $enrollment->forceDelete();

        $this->assertDatabaseMissing('enrollment_goals', ['id' => $goal->id]);
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
