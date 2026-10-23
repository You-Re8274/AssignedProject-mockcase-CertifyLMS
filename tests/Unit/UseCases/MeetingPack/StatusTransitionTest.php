<?php

declare(strict_types=1);

namespace Tests\Unit\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Exceptions\MeetingPack\MeetingPackInvalidTransitionException;
use App\Exceptions\MeetingPack\MeetingPackNotDeletableException;
use App\Models\MeetingPack;
use App\Models\User;
use App\UseCases\MeetingPack\ArchiveAction;
use App\UseCases\MeetingPack\DestroyAction;
use App\UseCases\MeetingPack\PublishAction;
use App\UseCases\MeetingPack\UnarchiveAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('invalidTransitions')]
    public function test_invalid_transition_throws_conflict_and_preserves_status(
        string $actionName,
        string $currentStatus,
    ): void {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->create(['status' => $currentStatus]);
        $action = match ($actionName) {
            'publish' => new PublishAction,
            'archive' => new ArchiveAction,
            'unarchive' => new UnarchiveAction,
        };

        try {
            $action($pack, $admin);
            $this->fail('Expected invalid transition to throw.');
        } catch (MeetingPackInvalidTransitionException) {
            $this->assertSame(MeetingPackStatus::from($currentStatus), $pack->fresh()->status);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidTransitions(): array
    {
        return [
            '公開済を再公開できない' => ['publish', MeetingPackStatus::Published->value],
            'アーカイブ済を公開できない' => ['publish', MeetingPackStatus::Archived->value],
            '下書きをアーカイブできない' => ['archive', MeetingPackStatus::Draft->value],
            'アーカイブ済を再アーカイブできない' => ['archive', MeetingPackStatus::Archived->value],
            '下書きをアーカイブ解除できない' => ['unarchive', MeetingPackStatus::Draft->value],
            '公開中をアーカイブ解除できない' => ['unarchive', MeetingPackStatus::Published->value],
        ];
    }

    #[DataProvider('deletableStatuses')]
    public function test_only_published_pack_is_not_deletable(string $status, bool $deletable): void
    {
        $pack = MeetingPack::factory()->create(['status' => $status]);

        if (! $deletable) {
            try {
                (new DestroyAction)($pack);
                $this->fail('Expected published pack deletion to throw.');
            } catch (MeetingPackNotDeletableException) {
                $this->assertDatabaseHas('meeting_packs', ['id' => $pack->id]);
            }

            return;
        }

        (new DestroyAction)($pack);
        $this->assertDatabaseMissing('meeting_packs', ['id' => $pack->id]);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function deletableStatuses(): array
    {
        return [
            '下書きは削除可' => [MeetingPackStatus::Draft->value, true],
            '公開中は削除不可' => [MeetingPackStatus::Published->value, false],
            'アーカイブ済は削除可' => [MeetingPackStatus::Archived->value, true],
        ];
    }
}
