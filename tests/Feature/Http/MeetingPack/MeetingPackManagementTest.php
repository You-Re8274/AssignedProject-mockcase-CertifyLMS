<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingPackManagementTest extends TestCase
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
            'name' => '追加面談パック',
            'description' => '面談を追加で購入できます。',
            'meeting_count' => 5,
            'price' => 12000,
            'stripe_price_id' => 'price_test_123',
            'sort_order' => 20,
        ], $override);
    }

    public function test_admin_can_view_management_pages_and_list_filters_paginate(): void
    {
        $admin = User::factory()->admin()->create();
        MeetingPack::factory()->draft()->create(['name' => '検索対象の下書き']);
        MeetingPack::factory()->published()->create(['name' => '別の公開パック']);
        MeetingPack::factory()->archived()->create(['name' => 'アーカイブ済パック']);
        MeetingPack::factory()->draft()->count(15)->create();

        $this->actingAs($admin)
            ->get(route('admin.meeting-packs.index', ['keyword' => '検索対象', 'status' => 'draft']))
            ->assertOk()
            ->assertViewIs('meeting-pack.management.index')
            ->assertSee('検索対象の下書き')
            ->assertDontSee('別の公開パック')
            ->assertDontSee('アーカイブ済パック')
            ->assertViewHas('plans', fn ($plans) => $plans->total() === 1);

        $response = $this->get(route('admin.meeting-packs.index', ['status' => 'draft']));
        $response->assertOk()
            ->assertViewHas('plans', fn ($plans) => $plans->total() === 16 && $plans->perPage() === 15);

        $this->get(route('admin.meeting-packs.index', ['status' => 'draft', 'page' => 2]))
            ->assertOk()
            ->assertViewHas('plans', fn ($plans) => $plans->count() === 1);

        $pack = MeetingPack::factory()->draft()->create();
        $this->get(route('admin.meeting-packs.create'))->assertOk();
        $this->get(route('admin.meeting-packs.show', $pack))->assertOk();
        $this->get(route('admin.meeting-packs.edit', $pack))->assertOk();
    }

    public function test_admin_can_create_update_and_delete_non_published_pack(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.meeting-packs.store'), $this->payload())
            ->assertRedirect();

        $pack = MeetingPack::query()->where('name', '追加面談パック')->firstOrFail();
        $this->assertSame(MeetingPackStatus::Draft, $pack->status);
        $this->assertSame($admin->id, $pack->created_by_user_id);
        $this->assertSame($admin->id, $pack->updated_by_user_id);

        $this->patch(route('admin.meeting-packs.update', $pack), $this->payload([
            'name' => '改定後のパック',
            'price' => 15000,
            'status' => 'published',
        ]))->assertRedirect(route('admin.meeting-packs.show', $pack));

        $pack->refresh();
        $this->assertSame('改定後のパック', $pack->name);
        $this->assertSame(15000, $pack->price);
        $this->assertSame(MeetingPackStatus::Draft, $pack->status);
        $this->assertSame($admin->id, $pack->updated_by_user_id);

        $this->delete(route('admin.meeting-packs.destroy', $pack))
            ->assertRedirect(route('admin.meeting-packs.index'));
        $this->assertDatabaseMissing('meeting_packs', ['id' => $pack->id]);
    }

    public function test_create_and_update_validate_required_fields_limits_and_integer_ranges(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $this->post(route('admin.meeting-packs.store'), [])
            ->assertSessionHasErrors(['name', 'meeting_count', 'price']);

        $this->post(route('admin.meeting-packs.store'), $this->payload([
            'name' => str_repeat('a', 101),
            'description' => str_repeat('b', 2001),
            'meeting_count' => 0,
            'price' => 1000001,
            'stripe_price_id' => str_repeat('c', 256),
            'sort_order' => -1,
        ]))->assertSessionHasErrors([
            'name',
            'description',
            'meeting_count',
            'price',
            'stripe_price_id',
            'sort_order',
        ]);

        $pack = MeetingPack::factory()->draft()->create();
        $this->patch(route('admin.meeting-packs.update', $pack), $this->payload([
            'meeting_count' => 1.5,
            'price' => 'not-an-integer',
            'sort_order' => 4294967296,
        ]))->assertSessionHasErrors(['meeting_count', 'price', 'sort_order']);
    }

    public function test_admin_can_follow_valid_status_lifecycle(): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->draft()->create();

        $this->actingAs($admin)
            ->post(route('admin.meeting-packs.publish', $pack))
            ->assertRedirect(route('admin.meeting-packs.show', $pack));
        $this->assertSame(MeetingPackStatus::Published, $pack->fresh()->status);
        $this->assertSame($admin->id, $pack->fresh()->updated_by_user_id);

        $this->post(route('admin.meeting-packs.archive', $pack))
            ->assertRedirect(route('admin.meeting-packs.show', $pack));
        $this->assertSame(MeetingPackStatus::Archived, $pack->fresh()->status);

        $this->post(route('admin.meeting-packs.unarchive', $pack))
            ->assertRedirect(route('admin.meeting-packs.show', $pack));
        $this->assertSame(MeetingPackStatus::Draft, $pack->fresh()->status);
    }

    public function test_invalid_status_transitions_return_conflict_without_changing_state(): void
    {
        $admin = User::factory()->admin()->create();
        $draft = MeetingPack::factory()->draft()->create();
        $published = MeetingPack::factory()->published()->create();
        $archived = MeetingPack::factory()->archived()->create();

        $this->actingAs($admin);
        $this->postJson(route('admin.meeting-packs.publish', $published))->assertStatus(409);
        $this->postJson(route('admin.meeting-packs.publish', $archived))->assertStatus(409);
        $this->postJson(route('admin.meeting-packs.archive', $draft))->assertStatus(409);
        $this->postJson(route('admin.meeting-packs.archive', $archived))->assertStatus(409);
        $this->postJson(route('admin.meeting-packs.unarchive', $draft))->assertStatus(409);
        $this->postJson(route('admin.meeting-packs.unarchive', $published))->assertStatus(409);

        $this->assertSame(MeetingPackStatus::Draft, $draft->fresh()->status);
        $this->assertSame(MeetingPackStatus::Published, $published->fresh()->status);
        $this->assertSame(MeetingPackStatus::Archived, $archived->fresh()->status);
    }

    public function test_published_pack_cannot_be_deleted_but_draft_and_archived_can(): void
    {
        $admin = User::factory()->admin()->create();
        $published = MeetingPack::factory()->published()->create();
        $draft = MeetingPack::factory()->draft()->create();
        $archived = MeetingPack::factory()->archived()->create();

        $this->actingAs($admin)
            ->deleteJson(route('admin.meeting-packs.destroy', $published))
            ->assertStatus(409);
        $this->assertDatabaseHas('meeting_packs', ['id' => $published->id]);

        $this->delete(route('admin.meeting-packs.destroy', $draft))->assertRedirect();
        $this->delete(route('admin.meeting-packs.destroy', $archived))->assertRedirect();
        $this->assertDatabaseMissing('meeting_packs', ['id' => $draft->id]);
        $this->assertDatabaseMissing('meeting_packs', ['id' => $archived->id]);
    }

    public function test_student_and_coach_are_forbidden_from_all_management_operations(): void
    {
        $pack = MeetingPack::factory()->draft()->create();

        foreach ([
            User::factory()->student()->create(),
            User::factory()->coach()->create(),
        ] as $user) {
            $this->actingAs($user);
            $this->get(route('admin.meeting-packs.index'))->assertForbidden();
            $this->get(route('admin.meeting-packs.create'))->assertForbidden();
            $this->get(route('admin.meeting-packs.show', $pack))->assertForbidden();
            $this->get(route('admin.meeting-packs.edit', $pack))->assertForbidden();
            $this->post(route('admin.meeting-packs.store'), $this->payload())->assertForbidden();
            $this->patch(route('admin.meeting-packs.update', $pack), $this->payload())->assertForbidden();
            $this->delete(route('admin.meeting-packs.destroy', $pack))->assertForbidden();
            $this->post(route('admin.meeting-packs.publish', $pack))->assertForbidden();
            $this->post(route('admin.meeting-packs.archive', $pack))->assertForbidden();
            $this->post(route('admin.meeting-packs.unarchive', $pack))->assertForbidden();
        }
    }
}
