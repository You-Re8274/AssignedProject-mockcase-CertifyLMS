<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Announcement;
use App\Models\User;
use App\Policies\AnnouncementPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnouncementPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_view_or_create_announcements(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create();
        $announcement = Announcement::factory()->create(['created_by_user_id' => $admin->id]);
        $policy = app(AnnouncementPolicy::class);

        foreach ([$admin] as $allowedUser) {
            $this->assertTrue($policy->viewAny($allowedUser));
            $this->assertTrue($policy->view($allowedUser, $announcement));
            $this->assertTrue($policy->create($allowedUser));
        }

        foreach ([$coach, $student] as $deniedUser) {
            $this->assertFalse($policy->viewAny($deniedUser));
            $this->assertFalse($policy->view($deniedUser, $announcement));
            $this->assertFalse($policy->create($deniedUser));
        }
    }
}
