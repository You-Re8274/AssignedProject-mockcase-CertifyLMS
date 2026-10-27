<?php

declare(strict_types=1);

namespace App\UseCases\QaBoard;

use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\Services\ActivityNotificationService;
use Illuminate\Support\Facades\DB;

final class StoreReplyAction
{
    public function __construct(
        private readonly ActivityNotificationService $notifications,
    ) {}

    /**
     * @param array{body: string} $validated
     */
    public function __invoke(User $author, QaThread $thread, array $validated): QaReply
    {
        return DB::transaction(function () use ($author, $thread, $validated): QaReply {
            $reply = $thread->replies()->create([
                'user_id' => $author->id,
                'body' => $validated['body'],
            ]);

            DB::afterCommit(function () use ($author, $thread): void {
                $owner = $thread->user;
                if ($owner !== null) {
                    $this->notifications->send(
                        recipient: $owner,
                        actor: $author,
                        type: 'qa_reply_received',
                        title: '質問掲示板に新しい回答があります',
                        message: $author->name.' さんがあなたの質問に回答しました。',
                        actionUrl: route('qa-board.show', $thread),
                    );
                }
            });

            return $reply;
        });
    }
}
