<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Models\User;
use App\Notifications\ActivityNotification;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ActivityNotificationQueueTest extends TestCase
{
    use RefreshDatabase;

    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        $transactionsManager = new DatabaseTransactionsManager;
        $this->app->instance('db.transactions', $transactionsManager);
        DB::connection()->setTransactionManager($transactionsManager);
        DB::table('jobs')->truncate();
    }

    public function test_activity_notification_channels_are_queued_after_transaction_commit(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.after_commit' => true,
        ]);
        $user = $this->notifiableUser();
        $notification = $this->notification();

        DB::beginTransaction();

        try {
            $user->notify($notification);
            $this->assertSame(0, DB::table('jobs')->count());
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        $this->assertSame(2, DB::table('jobs')->count());
        $this->assertSame(5, $notification->tries);
        $this->assertSame([10, 30, 60, 120], $notification->backoff);
    }

    public function test_activity_notification_is_not_queued_when_transaction_rolls_back(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.after_commit' => true,
        ]);

        DB::beginTransaction();

        try {
            $this->notifiableUser()->notify($this->notification());
            $this->assertSame(0, DB::table('jobs')->count());
            DB::rollBack();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        $this->assertSame(0, DB::table('jobs')->count());
    }

    private function notifiableUser(): User
    {
        $user = new User;
        $user->setRawAttributes([
            'id' => (string) Str::ulid(),
            'name' => '受講生',
            'email' => 'student@example.test',
        ]);

        return $user;
    }

    private function notification(): ActivityNotification
    {
        return new ActivityNotification([
            'notification_type' => 'test',
            'title' => '通知タイトル',
            'message' => '通知本文',
            'body' => '通知本文',
            'action_url' => '/notifications',
        ]);
    }
}
