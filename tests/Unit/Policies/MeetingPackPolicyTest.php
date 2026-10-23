<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\MeetingPack;
use App\Models\User;
use App\Policies\MeetingPackPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MeetingPackPolicyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('managementAbilities')]
    public function test_management_abilities_are_admin_only(string $ability): void
    {
        $policy = new MeetingPackPolicy;
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $pack = MeetingPack::factory()->create();

        $result = fn (User $user): bool => $ability === 'viewAny' || $ability === 'create'
            ? $policy->{$ability}($user)
            : $policy->{$ability}($user, $pack);

        $this->assertTrue($result($admin), "admin should be allowed to {$ability}");
        $this->assertFalse($result($coach), "coach should be denied {$ability}");
        $this->assertFalse($result($student), "student should be denied {$ability}");
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function managementAbilities(): array
    {
        return [
            '一覧' => ['viewAny'],
            '詳細' => ['view'],
            '作成' => ['create'],
            '更新' => ['update'],
            '削除' => ['delete'],
            '公開' => ['publish'],
            'アーカイブ' => ['archive'],
            'アーカイブ解除' => ['unarchive'],
        ];
    }
}
