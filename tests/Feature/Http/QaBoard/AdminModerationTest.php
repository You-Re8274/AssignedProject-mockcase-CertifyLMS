<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaBoard;

use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminModerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_all_certifications_including_unpublished_and_filter_threads(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $published = Certification::factory()->published()->create(['name' => '公開資格']);
        $draft = Certification::factory()->draft()->create(['name' => '停止資格']);
        QaThread::factory()->create([
            'certification_id' => $published->id,
            'title' => '公開側の質問',
            'body' => 'キーワードを含む本文',
        ]);
        QaThread::factory()->create([
            'certification_id' => $draft->id,
            'title' => '停止資格の質問',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.qa-board.index'));

        $response->assertOk()
            ->assertViewIs('qa-thread.index')
            ->assertSee('公開側の質問')
            ->assertSee('停止資格の質問')
            ->assertSee('停止資格');

        $filtered = $this->get(route('admin.qa-board.index', [
            'certification_id' => $draft->id,
            'keyword' => '停止資格',
        ]));
        $filtered->assertOk()
            ->assertSee('停止資格の質問')
            ->assertDontSee('公開側の質問');
    }

    public function test_admin_can_view_and_delete_any_thread_and_reply(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $certification = Certification::factory()->draft()->create();
        $thread = QaThread::factory()->create(['certification_id' => $certification->id]);
        $reply = QaReply::factory()->create(['qa_thread_id' => $thread->id]);

        $this->actingAs($admin)
            ->get(route('admin.qa-board.show', $thread))
            ->assertOk()
            ->assertSee($thread->title)
            ->assertSee($reply->body);

        $this->delete(route('admin.qa-board.destroy', $thread))
            ->assertRedirect(route('admin.qa-board.index'));
        $this->assertDatabaseMissing('qa_threads', ['id' => $thread->id]);
        $this->assertDatabaseMissing('qa_replies', ['id' => $reply->id]);

        $threadWithReply = QaThread::factory()->create(['certification_id' => $certification->id]);
        $moderatedReply = QaReply::factory()->create(['qa_thread_id' => $threadWithReply->id]);
        $this->delete(route('admin.qa-board.replies.destroy', [$threadWithReply, $moderatedReply]))
            ->assertRedirect(route('admin.qa-board.show', $threadWithReply));
        $this->assertDatabaseHas('qa_threads', ['id' => $threadWithReply->id]);
        $this->assertDatabaseMissing('qa_replies', ['id' => $moderatedReply->id]);
    }

    public function test_non_admin_cannot_access_moderation_routes(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->draft()->create();
        $thread = QaThread::factory()->create(['certification_id' => $certification->id]);

        $this->actingAs($student)
            ->get(route('admin.qa-board.index'))
            ->assertForbidden();
        $this->get(route('admin.qa-board.show', $thread))->assertForbidden();
    }
}
