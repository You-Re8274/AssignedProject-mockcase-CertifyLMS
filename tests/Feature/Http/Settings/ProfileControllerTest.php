<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_roles_including_graduated_students_can_view_and_update_own_profile(): void
    {
        $users = [
            User::factory()->admin()->create(),
            User::factory()->coach()->create(),
            User::factory()->student()->inProgress()->create(),
            User::factory()->student()->graduated()->create(),
        ];

        foreach ($users as $user) {
            $this->actingAs($user)
                ->get(route('settings.profile.edit'))
                ->assertOk()
                ->assertViewIs('settings.profile');

            $this->patch(route('settings.profile.update'), [
                'name' => '更新後の氏名',
                'bio' => '更新後の自己紹介',
                'email' => 'attacker@example.test',
            ])->assertRedirect(route('settings.profile.edit'));

            $this->assertSame('更新後の氏名', $user->fresh()->name);
            $this->assertSame('更新後の自己紹介', $user->fresh()->bio);
            $this->assertNotSame('attacker@example.test', $user->fresh()->email);
        }
    }

    public function test_coach_can_update_meeting_url_and_other_roles_cannot_submit_it(): void
    {
        $coach = User::factory()->coach()->create();
        $this->actingAs($coach)
            ->patch(route('settings.profile.update'), [
                'name' => $coach->name,
                'meeting_url' => 'https://meet.example.test/coach',
            ])
            ->assertRedirect(route('settings.profile.edit'));
        $this->assertSame('https://meet.example.test/coach', $coach->fresh()->meeting_url);

        foreach ([
            User::factory()->admin()->create(),
            User::factory()->student()->inProgress()->create(),
            User::factory()->student()->graduated()->create(),
        ] as $user) {
            $this->actingAs($user)
                ->patchJson(route('settings.profile.update'), [
                    'name' => $user->name,
                    'meeting_url' => 'https://meet.example.test/forbidden',
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('meeting_url');

            $this->assertNull($user->fresh()->meeting_url);
        }
    }

    public function test_profile_validation_preserves_existing_data_when_invalid(): void
    {
        $user = User::factory()->student()->inProgress()->create([
            'name' => '変更前',
            'bio' => '変更前の紹介',
        ]);

        $this->actingAs($user)
            ->patchJson(route('settings.profile.update'), [
                'name' => str_repeat('a', 51),
                'bio' => str_repeat('a', 1001),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'bio']);

        $this->assertSame('変更前', $user->fresh()->name);
        $this->assertSame('変更前の紹介', $user->fresh()->bio);
    }

    public function test_password_change_requires_current_password_and_matching_new_password(): void
    {
        $user = User::factory()->student()->graduated()->create([
            'password' => 'current-password',
        ]);

        $this->actingAs($user)
            ->put(route('settings.password.update'), [
                'current_password' => 'incorrect',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertSessionHasErrorsIn('updatePassword', 'current_password');

        $this->put(route('settings.password.update'), [
            'current_password' => 'current-password',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrorsIn('updatePassword', 'password');

        $this->put(route('settings.password.update'), [
            'current_password' => 'current-password',
            'password' => 'new-password',
            'password_confirmation' => 'different-password',
        ])->assertSessionHasErrorsIn('updatePassword', 'password');

        $this->assertTrue(Hash::check('current-password', $user->fresh()->password));

        $this->put(route('settings.password.update'), [
            'current_password' => 'current-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertRedirect(route('settings.profile.edit', ['tab' => 'password']));

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_avatar_can_be_uploaded_replaced_and_deleted(): void
    {
        Storage::fake('public');
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->post(route('settings.avatar.store'), [
                'avatar' => UploadedFile::fake()->image('first.png'),
            ])
            ->assertRedirect(route('settings.profile.edit'));

        $firstUrl = $user->fresh()->avatar_url;
        $this->assertNotNull($firstUrl);
        $firstPath = $this->avatarPath($firstUrl);
        Storage::disk('public')->assertExists($firstPath);

        $this->post(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('replacement.webp'),
        ])->assertRedirect(route('settings.profile.edit'));

        $replacementUrl = $user->fresh()->avatar_url;
        $this->assertNotSame($firstUrl, $replacementUrl);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($this->avatarPath($replacementUrl));

        $this->delete(route('settings.avatar.destroy'))
            ->assertRedirect(route('settings.profile.edit'));

        $this->assertNull($user->fresh()->avatar_url);
        Storage::disk('public')->assertMissing($this->avatarPath($replacementUrl));
    }

    public function test_avatar_upload_rejects_non_image_and_oversized_files(): void
    {
        Storage::fake('public');
        $user = User::factory()->student()->graduated()->create();

        $this->actingAs($user)
            ->postJson(route('settings.avatar.store'), [
                'avatar' => UploadedFile::fake()->create('avatar.txt', 10, 'text/plain'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar');

        $this->postJson(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('large.png')->size(2049),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('avatar');

        $this->assertNull($user->fresh()->avatar_url);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_unauthenticated_user_cannot_access_settings_routes(): void
    {
        $this->get(route('settings.profile.edit'))->assertRedirect(route('login'));
        $this->patch(route('settings.profile.update'))->assertRedirect(route('login'));
        $this->post(route('settings.avatar.store'))->assertRedirect(route('login'));
        $this->delete(route('settings.avatar.destroy'))->assertRedirect(route('login'));
        $this->put(route('settings.password.update'))->assertRedirect(route('login'));
    }

    private function avatarPath(?string $url): string
    {
        $this->assertNotNull($url);

        $path = (string) parse_url($url, PHP_URL_PATH);

        return substr($path, strlen('/storage/'));
    }
}
