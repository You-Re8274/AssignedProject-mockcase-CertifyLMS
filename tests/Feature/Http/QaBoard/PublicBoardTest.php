<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaBoard;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_sees_published_threads_and_can_filter_and_search(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $published = Certification::factory()->published()->create();
        $otherPublished = Certification::factory()->published()->create();
        $draft = Certification::factory()->draft()->create();

        $matching = QaThread::factory()->create([
            'certification_id' => $published->id,
            'user_id' => $student->id,
            'title' => '探索アルゴリズムの質問',
            'body' => '二分探索について知りたい',
        ]);
        QaThread::factory()->resolved()->create([
            'certification_id' => $published->id,
            'title' => '過去問の復習方法',
        ]);
        QaThread::factory()->create([
            'certification_id' => $otherPublished->id,
            'title' => '探索しない質問',
        ]);
        QaThread::factory()->create([
            'certification_id' => $draft->id,
            'title' => '非公開資格の質問',
        ]);

        $response = $this->actingAs($student)->get(route('qa-board.index', [
            'certification_id' => $published->id,
            'status' => 'unresolved',
            'keyword' => '探索',
        ]));

        $response->assertOk()
            ->assertViewIs('qa-thread.index')
            ->assertSee('探索アルゴリズムの質問')
            ->assertDontSee('過去問の復習方法')
            ->assertDontSee('非公開資格の質問');

        $threads = $response->viewData('threads');
        $this->assertSame(1, $threads->total());
        $this->assertSame(15, $threads->perPage());
        $this->assertSame($matching->id, $threads->first()->id);
    }

    public function test_coach_sees_only_assigned_published_certifications(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $admin = User::factory()->admin()->create();
        $assigned = Certification::factory()->published()->create();
        $unassigned = Certification::factory()->published()->create();
        $draft = Certification::factory()->draft()->create();
        $this->assignCoach($assigned, $coach, $admin);

        $assignedThread = QaThread::factory()->create([
            'certification_id' => $assigned->id,
            'title' => '担当資格スレッド',
        ]);
        QaThread::factory()->create([
            'certification_id' => $unassigned->id,
            'title' => '担当外スレッド',
        ]);
        QaThread::factory()->create([
            'certification_id' => $draft->id,
            'title' => '公開停止スレッド',
        ]);

        $response = $this->actingAs($coach)->get(route('qa-board.index'));

        $response->assertOk()
            ->assertSee('担当資格スレッド')
            ->assertDontSee('担当外スレッド')
            ->assertDontSee('公開停止スレッド');

        $this->actingAs($coach)->get(route('qa-board.show', $assignedThread))->assertOk();
        $this->actingAs($coach)->get(route('qa-board.show', [
            'thread' => QaThread::query()->where('certification_id', $unassigned->id)->firstOrFail(),
        ]))->assertForbidden();
    }

    public function test_student_can_create_update_resolve_and_unresolve_own_thread(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $otherCertification = Certification::factory()->published()->create();

        $this->actingAs($student)
            ->get(route('qa-board.create'))
            ->assertOk()
            ->assertSee($certification->name);

        $this->post(route('qa-board.store'), [
            'certification_id' => $certification->id,
            'title' => '作成した質問',
            'body' => '質問本文です。',
        ])->assertRedirect();

        $thread = QaThread::query()->where('title', '作成した質問')->firstOrFail();
        $this->assertSame($certification->id, $thread->certification_id);
        $this->assertSame($student->id, $thread->user_id);
        $this->assertSame(QaThreadStatus::Open, $thread->status);

        $this->get(route('qa-board.edit', $thread))->assertOk();
        $this->patch(route('qa-board.update', $thread), [
            'certification_id' => $otherCertification->id,
            'title' => '更新した質問',
            'body' => '更新後の本文です。',
        ])->assertRedirect(route('qa-board.show', $thread));
        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
            'certification_id' => $certification->id,
            'title' => '更新した質問',
        ]);

        $this->post(route('qa-board.resolve', $thread))->assertRedirect();
        $thread->refresh();
        $this->assertSame(QaThreadStatus::Resolved, $thread->status);
        $this->assertNotNull($thread->resolved_at);

        $this->post(route('qa-board.unresolve', $thread))->assertRedirect();
        $thread->refresh();
        $this->assertSame(QaThreadStatus::Open, $thread->status);
        $this->assertNull($thread->resolved_at);
    }

    public function test_thread_create_validates_fields_and_published_certification(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $published = Certification::factory()->published()->create();
        $draft = Certification::factory()->draft()->create();

        $this->actingAs($student)
            ->post(route('qa-board.store'), [])
            ->assertSessionHasErrors(['certification_id', 'title', 'body']);

        $this->post(route('qa-board.store'), [
            'certification_id' => $draft->id,
            'title' => '質問',
            'body' => '本文',
        ])->assertSessionHasErrors('certification_id');

        $this->post(route('qa-board.store'), [
            'certification_id' => $published->id,
            'title' => str_repeat('a', 201),
            'body' => str_repeat('b', 5001),
        ])->assertSessionHasErrors(['title', 'body']);
    }

    public function test_student_and_coach_can_reply_and_reply_author_can_edit_and_delete(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $answeringStudent = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();
        $this->assignCoach($certification, $coach, $admin);
        $thread = QaThread::factory()->create(['certification_id' => $certification->id, 'user_id' => $student->id]);

        $this->actingAs($answeringStudent)
            ->post(route('qa-board.replies.store', $thread), ['body' => '受講生の回答です。'])
            ->assertRedirect();
        $studentReply = QaReply::query()->where('body', '受講生の回答です。')->firstOrFail();

        $this->actingAs($coach)
            ->post(route('qa-board.replies.store', $thread), ['body' => 'コーチの回答です。'])
            ->assertRedirect();
        $coachReply = QaReply::query()->where('body', 'コーチの回答です。')->firstOrFail();

        $this->actingAs($answeringStudent)
            ->get(route('qa-board.replies.edit', [$thread, $studentReply]))
            ->assertOk();
        $this->patch(route('qa-board.replies.update', [$thread, $studentReply]), [
            'body' => '回答を編集しました。',
        ])->assertRedirect(route('qa-board.show', $thread));

        $this->actingAs($coach)
            ->delete(route('qa-board.replies.destroy', [$thread, $coachReply]))
            ->assertRedirect(route('qa-board.show', $thread));

        $this->assertDatabaseHas('qa_replies', ['id' => $studentReply->id, 'body' => '回答を編集しました。']);
        $this->assertDatabaseMissing('qa_replies', ['id' => $coachReply->id]);
    }

    public function test_only_authors_can_edit_or_delete_and_thread_requires_no_replies_for_student_delete(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $threadWithReply = QaThread::factory()->create([
            'certification_id' => $certification->id,
            'user_id' => $owner->id,
        ]);
        QaReply::factory()->create(['qa_thread_id' => $threadWithReply->id]);

        $this->actingAs($other)
            ->get(route('qa-board.edit', $threadWithReply))
            ->assertForbidden();
        $this->actingAs($owner)
            ->delete(route('qa-board.destroy', $threadWithReply))
            ->assertForbidden();

        $reply = QaReply::factory()->create(['qa_thread_id' => $threadWithReply->id]);
        $this->actingAs($other)
            ->patch(route('qa-board.replies.update', [$threadWithReply, $reply]), ['body' => '変更'])
            ->assertForbidden();

        $emptyThread = QaThread::factory()->create([
            'certification_id' => $certification->id,
            'user_id' => $owner->id,
        ]);
        $this->actingAs($owner)
            ->delete(route('qa-board.destroy', $emptyThread))
            ->assertRedirect(route('qa-board.index'));
        $this->assertDatabaseMissing('qa_threads', ['id' => $emptyThread->id]);
    }

    public function test_coach_cannot_post_thread_and_inactive_user_cannot_access_board(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $this->actingAs($coach)->get(route('qa-board.create'))->assertForbidden();
        $this->post(route('qa-board.store'), [])->assertForbidden();

        $graduated = User::factory()->student()->graduated()->create();
        $this->actingAs($graduated)->get(route('qa-board.index'))->assertForbidden();
    }

    public function test_list_is_paginated_and_loads_reply_count_without_per_thread_queries(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        QaThread::factory()->count(18)->create(['certification_id' => $certification->id]);

        $response = $this->actingAs($student)->get(route('qa-board.index'));

        $response->assertOk();
        $threads = $response->viewData('threads');
        $this->assertSame(18, $threads->total());
        $this->assertSame(15, $threads->perPage());
        $this->assertNotNull($threads->first()->replies_count);

        $response = $this->get(route('qa-board.index', ['page' => 2]));
        $this->assertCount(3, $response->viewData('threads'));
    }

    public function test_list_query_count_does_not_increase_with_thread_count(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        QaThread::factory()->create(['certification_id' => $certification->id]);

        $recording = true;
        $queryCount = 0;
        DB::listen(function () use (&$recording, &$queryCount): void {
            if ($recording) {
                $queryCount++;
            }
        });

        $this->actingAs($student)->get(route('qa-board.index'))->assertOk();
        $baseline = $queryCount;

        $recording = false;
        QaThread::factory()->count(19)->create(['certification_id' => $certification->id]);
        $queryCount = 0;
        $recording = true;

        $this->get(route('qa-board.index'))->assertOk();
        $scaled = $queryCount;

        $this->assertLessThanOrEqual(
            $baseline + 1,
            $scaled,
            "質問一覧の SQL 件数がスレッド数に応じて増加しています (基準 {$baseline} → 20 件時 {$scaled})",
        );
    }

    private function assignCoach(Certification $certification, User $coach, User $admin): void
    {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
