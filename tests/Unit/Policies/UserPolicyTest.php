<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * UserPolicy の ability × Role マトリクス検証。
 * 管理者向けユーザー運用 ability 5 種 (viewAny / view / withdraw / extendCourse / grantMeetingQuota) × 3 ロール = 15 ケースを検証する。
 * 「他者のロール / プロフィール変更」動線は LMS で提供しないため、update ability は Policy 側に存在しない。
 */
class UserPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_roles_can_access_only_their_own_settings_including_graduated_students(): void
    {
        $policy = new UserPolicy;

        foreach ([
            User::factory()->admin()->create(),
            User::factory()->coach()->create(),
            User::factory()->student()->inProgress()->create(),
            User::factory()->student()->graduated()->create(),
        ] as $user) {
            $this->assertTrue($policy->viewOwnSettings($user, $user));
            $this->assertTrue($policy->updateOwnProfile($user, $user));
            $this->assertTrue($policy->updateOwnPassword($user, $user));
            $this->assertTrue($policy->uploadOwnAvatar($user, $user));
            $this->assertTrue($policy->deleteOwnAvatar($user, $user));

            $other = User::factory()->student()->create();
            $this->assertFalse($policy->viewOwnSettings($user, $other));
            $this->assertFalse($policy->updateOwnProfile($user, $other));
            $this->assertFalse($policy->updateOwnPassword($user, $other));
            $this->assertFalse($policy->uploadOwnAvatar($user, $other));
            $this->assertFalse($policy->deleteOwnAvatar($user, $other));
        }
    }

    /**
     * 管理者向けユーザー運用 ability × Role のマトリクス検証。
     * Admin のみ全 ability で true、Coach / Student は全 ability で false が期待値。
     */
    #[DataProvider('adminOnlyAbilityMatrix')]
    public function test_admin_only_abilities_match_role_expectation(
        string $actingRole,
        string $policyMethod,
        bool $expected,
    ): void {
        // Arrange
        $actor = User::factory()->{$actingRole}()->create();
        $target = User::factory()->student()->create();
        $policy = new UserPolicy;

        // Act
        $result = $policy->{$policyMethod}($actor, $target);

        // Assert
        $this->assertSame(
            $expected,
            $result,
            "{$actingRole} が {$policyMethod} で ".($expected ? 'true' : 'false').' を返すべきだが反対の結果が返った',
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function adminOnlyAbilityMatrix(): array
    {
        $abilities = ['viewAny', 'view', 'withdraw', 'extendCourse', 'grantMeetingQuota'];
        $roles = [
            'admin' => true,
            'coach' => false,
            'student' => false,
        ];

        $cases = [];
        foreach ($roles as $role => $expected) {
            foreach ($abilities as $ability) {
                $caseKey = $expected
                    ? "{$role} は {$ability} を実行できる"
                    : "{$role} は {$ability} を実行できない";
                $cases[$caseKey] = [$role, $ability, $expected];
            }
        }

        return $cases;
    }
}
