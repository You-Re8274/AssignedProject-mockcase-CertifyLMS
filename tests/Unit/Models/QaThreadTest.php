<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QaThreadTest extends TestCase
{
    use RefreshDatabase;

    public function test_thread_relations_and_status_cast_are_available(): void
    {
        $user = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();
        $thread = QaThread::factory()->resolved()->create([
            'certification_id' => $certification->id,
            'user_id' => $user->id,
        ]);
        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $user->id,
        ]);

        $this->assertSame(QaThreadStatus::Resolved, $thread->status);
        $this->assertSame($certification->id, $thread->certification->id);
        $this->assertSame($user->id, $thread->user->id);
        $this->assertSame($reply->id, $thread->replies->sole()->id);
        $this->assertSame($thread->id, $reply->thread->id);
        $this->assertSame($user->id, $reply->user->id);
    }
}
