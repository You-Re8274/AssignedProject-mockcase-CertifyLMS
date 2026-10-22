<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

class QaBoardPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_thread_policy_enforces_owner_reply_condition_and_admin_moderation(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $admin = User::factory()->admin()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $thread = QaThread::factory()->create([
            'certification_id' => $certification->id,
            'user_id' => $student->id,
        ]);

        $this->assertTrue(Gate::forUser($student)->allows('update', $thread));
        $this->assertTrue(Gate::forUser($student)->allows('delete', $thread));
        $this->assertFalse(Gate::forUser($otherStudent)->allows('update', $thread));

        QaReply::factory()->create(['qa_thread_id' => $thread->id]);
        $this->assertFalse(Gate::forUser($student)->allows('delete', $thread));
        $this->assertTrue(Gate::forUser($admin)->allows('delete', $thread));
        $this->assertTrue(Gate::forUser($admin)->allows('view', $thread));
    }

    public function test_coach_can_view_and_reply_only_to_assigned_published_certification(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $admin = User::factory()->admin()->create();
        $assigned = Certification::factory()->published()->create();
        $unassigned = Certification::factory()->published()->create();
        $draft = Certification::factory()->draft()->create();
        $assigned->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assignedThread = QaThread::factory()->create(['certification_id' => $assigned->id]);
        $unassignedThread = QaThread::factory()->create(['certification_id' => $unassigned->id]);
        $draftThread = QaThread::factory()->create(['certification_id' => $draft->id]);

        $this->assertTrue(Gate::forUser($coach)->allows('view', $assignedThread));
        $this->assertTrue(Gate::forUser($coach)->allows('create', [QaReply::class, $assignedThread]));
        $this->assertFalse(Gate::forUser($coach)->allows('view', $unassignedThread));
        $this->assertFalse(Gate::forUser($coach)->allows('create', [QaReply::class, $unassignedThread]));
        $this->assertFalse(Gate::forUser($coach)->allows('view', $draftThread));
    }

    public function test_only_reply_author_can_edit_and_admin_can_moderate_delete(): void
    {
        $author = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $admin = User::factory()->admin()->inProgress()->create();
        $thread = QaThread::factory()->create([
            'certification_id' => Certification::factory()->published()->create()->id,
        ]);
        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $author->id,
        ]);

        $this->assertTrue(Gate::forUser($author)->allows('update', $reply));
        $this->assertTrue(Gate::forUser($author)->allows('delete', $reply));
        $this->assertFalse(Gate::forUser($otherStudent)->allows('update', $reply));
        $this->assertTrue(Gate::forUser($admin)->allows('delete', $reply));
        $this->assertFalse(Gate::forUser($admin)->allows('update', $reply));
    }
}
