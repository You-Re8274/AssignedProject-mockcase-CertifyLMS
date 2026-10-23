<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserPlanLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function payload(array $override = []): array
    {
        return array_merge([
            'name' => 'スタンダードプラン',
            'description' => '標準的な受講プランです。',
            'duration_days' => 90,
            'default_meeting_quota' => 12,
            'sort_order' => 20,
        ], $override);
    }

    public function test_admin_can_view_management_pages_and_list_filters_counts_and_paginates(): void
    {
        $admin = User::factory()->admin()->create();
        $matching = Plan::factory()->draft()->create(['name' => '検索対象プラン']);
        $other = Plan::factory()->published()->create(['name' => '別の公開プラン']);
        Plan::factory()->archived()->create(['name' => 'アーカイブ済プラン']);
        Plan::factory()->draft()->count(15)->create();
        User::factory()->student()->withPlan($matching)->create();
        User::factory()->student()->withPlan($matching)->create();
        User::factory()->student()->withPlan($other)->create();

        $this->actingAs($admin)
            ->get(route('admin.plans.index', ['keyword' => '検索対象', 'status' => 'draft']))
            ->assertOk()
            ->assertViewIs('plan.management.index')
            ->assertSee('検索対象プラン')
            ->assertDontSee('別の公開プラン')
            ->assertDontSee('アーカイブ済プラン')
            ->assertViewHas('plans', function ($plans) use ($matching): bool {
                return $plans->total() === 1
                    && $plans->first()->is($matching)
                    && $plans->first()->users_count === 2;
            });

        $this->get(route('admin.plans.index', ['status' => 'draft']))
            ->assertOk()
            ->assertViewHas('plans', fn ($plans) => $plans->total() === 16 && $plans->perPage() === 15);
        $this->get(route('admin.plans.index', ['status' => 'draft', 'page' => 2]))
            ->assertOk()
            ->assertViewHas('plans', fn ($plans) => $plans->count() === 1);

        $this->get(route('admin.plans.create'))->assertOk();
        $this->get(route('admin.plans.show', $matching))->assertOk()
            ->assertViewHas('plan', fn (Plan $plan) => $plan->users->count() === 2);
        $this->get(route('admin.plans.edit', $matching))->assertOk();
    }

    public function test_admin_can_create_update_and_delete_unused_draft_plan(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.plans.store'), $this->payload())
            ->assertRedirect();

        $plan = Plan::query()->where('name', 'スタンダードプラン')->firstOrFail();
        $this->assertSame(PlanStatus::Draft, $plan->status);
        $this->assertSame($admin->id, $plan->created_by_user_id);
        $this->assertSame($admin->id, $plan->updated_by_user_id);

        $this->put(route('admin.plans.update', $plan), $this->payload([
            'name' => '改定後のプラン',
            'duration_days' => 120,
            'default_meeting_quota' => 16,
            'status' => 'published',
        ]))->assertRedirect(route('admin.plans.show', $plan));

        $plan->refresh();
        $this->assertSame('改定後のプラン', $plan->name);
        $this->assertSame(120, $plan->duration_days);
        $this->assertSame(16, $plan->default_meeting_quota);
        $this->assertSame(PlanStatus::Draft, $plan->status);
        $this->assertSame($admin->id, $plan->updated_by_user_id);

        $this->delete(route('admin.plans.destroy', $plan))
            ->assertRedirect(route('admin.plans.index'));
        $this->assertDatabaseMissing('plans', ['id' => $plan->id]);
    }

    public function test_create_and_update_validate_required_fields_limits_and_integer_ranges(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $this->post(route('admin.plans.store'), [])
            ->assertSessionHasErrors(['name', 'duration_days', 'default_meeting_quota']);

        $invalid = $this->payload([
            'name' => str_repeat('a', 101),
            'description' => str_repeat('b', 2001),
            'duration_days' => 3651,
            'default_meeting_quota' => -1,
            'sort_order' => -1,
        ]);
        $this->post(route('admin.plans.store'), $invalid)
            ->assertSessionHasErrors(['name', 'description', 'duration_days', 'default_meeting_quota', 'sort_order']);

        $plan = Plan::factory()->draft()->create();
        $this->put(route('admin.plans.update', $plan), $this->payload([
            'duration_days' => 1.5,
            'default_meeting_quota' => 'not-an-integer',
            'sort_order' => 4294967296,
        ]))->assertSessionHasErrors(['duration_days', 'default_meeting_quota', 'sort_order']);
    }

    public function test_admin_can_follow_valid_status_lifecycle(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create();

        $this->actingAs($admin)
            ->post(route('admin.plans.publish', $plan))
            ->assertRedirect(route('admin.plans.show', $plan));
        $this->assertSame(PlanStatus::Published, $plan->fresh()->status);
        $this->assertSame($admin->id, $plan->fresh()->updated_by_user_id);

        $this->post(route('admin.plans.archive', $plan))
            ->assertRedirect(route('admin.plans.show', $plan));
        $this->assertSame(PlanStatus::Archived, $plan->fresh()->status);

        $this->post(route('admin.plans.unarchive', $plan))
            ->assertRedirect(route('admin.plans.show', $plan));
        $this->assertSame(PlanStatus::Draft, $plan->fresh()->status);
    }

    public function test_invalid_status_transitions_return_conflict_without_changing_state(): void
    {
        $admin = User::factory()->admin()->create();
        $draft = Plan::factory()->draft()->create();
        $published = Plan::factory()->published()->create();
        $archived = Plan::factory()->archived()->create();
        $this->actingAs($admin);

        $this->postJson(route('admin.plans.publish', $published))->assertStatus(409);
        $this->postJson(route('admin.plans.publish', $archived))->assertStatus(409);
        $this->postJson(route('admin.plans.archive', $draft))->assertStatus(409);
        $this->postJson(route('admin.plans.archive', $archived))->assertStatus(409);
        $this->postJson(route('admin.plans.unarchive', $draft))->assertStatus(409);
        $this->postJson(route('admin.plans.unarchive', $published))->assertStatus(409);

        $this->assertSame(PlanStatus::Draft, $draft->fresh()->status);
        $this->assertSame(PlanStatus::Published, $published->fresh()->status);
        $this->assertSame(PlanStatus::Archived, $archived->fresh()->status);
    }

    public function test_plan_cannot_be_deleted_if_published_or_referenced_by_users_or_history(): void
    {
        $admin = User::factory()->admin()->create();
        $published = Plan::factory()->published()->create();
        $usedByStudent = Plan::factory()->draft()->create();
        $usedByHistory = Plan::factory()->draft()->create();
        $unusedDraft = Plan::factory()->draft()->create();

        $student = User::factory()->student()->withPlan($usedByStudent)->create();
        $student->delete();
        UserPlanLog::factory()->for($usedByHistory, 'plan')->create();

        $this->actingAs($admin)
            ->deleteJson(route('admin.plans.destroy', $published))
            ->assertStatus(409);
        $this->deleteJson(route('admin.plans.destroy', $usedByStudent))->assertStatus(409);
        $this->deleteJson(route('admin.plans.destroy', $usedByHistory))->assertStatus(409);

        $this->assertDatabaseHas('plans', ['id' => $published->id]);
        $this->assertDatabaseHas('plans', ['id' => $usedByStudent->id]);
        $this->assertDatabaseHas('plans', ['id' => $usedByHistory->id]);

        $this->delete(route('admin.plans.destroy', $unusedDraft))
            ->assertRedirect(route('admin.plans.index'));
        $this->assertDatabaseMissing('plans', ['id' => $unusedDraft->id]);
    }

    public function test_student_and_coach_are_forbidden_from_all_plan_management_operations(): void
    {
        $plan = Plan::factory()->draft()->create();

        foreach ([
            User::factory()->student()->create(),
            User::factory()->coach()->create(),
        ] as $user) {
            $this->actingAs($user);
            $this->get(route('admin.plans.index'))->assertForbidden();
            $this->get(route('admin.plans.create'))->assertForbidden();
            $this->get(route('admin.plans.show', $plan))->assertForbidden();
            $this->get(route('admin.plans.edit', $plan))->assertForbidden();
            $this->post(route('admin.plans.store'), $this->payload())->assertForbidden();
            $this->put(route('admin.plans.update', $plan), $this->payload())->assertForbidden();
            $this->delete(route('admin.plans.destroy', $plan))->assertForbidden();
            $this->post(route('admin.plans.publish', $plan))->assertForbidden();
            $this->post(route('admin.plans.archive', $plan))->assertForbidden();
            $this->post(route('admin.plans.unarchive', $plan))->assertForbidden();
        }
    }
}
