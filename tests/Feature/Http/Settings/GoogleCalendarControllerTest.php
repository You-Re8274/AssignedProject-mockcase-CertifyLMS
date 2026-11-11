<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Settings;

use App\Models\User;
use App\Services\GoogleCalendarOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class GoogleCalendarControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_coach_can_start_google_authorization(): void
    {
        $coach = User::factory()->coach()->create();
        $oauth = Mockery::mock(GoogleCalendarOAuthService::class);
        $oauth->shouldReceive('isConfigured')->once()->andReturnTrue();
        $oauth->shouldReceive('authorizationUrl')
            ->once()
            ->with(Mockery::type('string'))
            ->andReturn('https://accounts.google.test/oauth');
        $this->app->instance(GoogleCalendarOAuthService::class, $oauth);

        $this->actingAs($coach)
            ->get(route('settings.google-calendar.redirect'))
            ->assertRedirect('https://accounts.google.test/oauth');
    }

    public function test_coach_sees_oauth_setup_error_when_google_is_not_configured(): void
    {
        $coach = User::factory()->coach()->create();
        $oauth = Mockery::mock(GoogleCalendarOAuthService::class);
        $oauth->shouldReceive('isConfigured')->once()->andReturnFalse();
        $oauth->shouldNotReceive('authorizationUrl');
        $this->app->instance(GoogleCalendarOAuthService::class, $oauth);

        $this->actingAs($coach)
            ->get(route('settings.google-calendar.redirect'))
            ->assertRedirect(route('settings.availability.index'))
            ->assertSessionHas('error');
    }

    public function test_availability_settings_page_renders_google_calendar_link_section(): void
    {
        $coach = User::factory()->coach()->create();

        $this->actingAs($coach)
            ->get(route('settings.availability.index'))
            ->assertOk()
            ->assertSee('Googleカレンダー連携')
            ->assertSee(route('settings.google-calendar.redirect'), false);
    }

    public function test_callback_saves_google_tokens_for_authenticated_coach(): void
    {
        $coach = User::factory()->coach()->create();
        $oauth = Mockery::mock(GoogleCalendarOAuthService::class);
        $oauth->shouldReceive('isConfigured')->twice()->andReturnTrue();
        $oauth->shouldReceive('authorizationUrl')
            ->once()
            ->andReturnUsing(fn (string $state) => 'https://accounts.google.test/oauth?state='.$state);
        $oauth->shouldReceive('exchangeCode')
            ->once()
            ->with('valid-auth-code')
            ->andReturn([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 3600,
            ]);
        $this->app->instance(GoogleCalendarOAuthService::class, $oauth);

        $authorizationResponse = $this->actingAs($coach)
            ->get(route('settings.google-calendar.redirect'));
        parse_str((string) parse_url($authorizationResponse->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->get(route('settings.google-calendar.callback', [
            'state' => $query['state'],
            'code' => 'valid-auth-code',
        ]))
            ->assertRedirect('/settings/availability')
            ->assertSessionHas('success');

        $coach->refresh();
        $this->assertSame('new-access-token', $coach->google_access_token);
        $this->assertSame('new-refresh-token', $coach->google_refresh_token);
        $this->assertTrue($coach->google_token_expires_at->isFuture());
    }

    public function test_callback_preserves_existing_refresh_token_when_google_does_not_return_a_new_one(): void
    {
        $coach = User::factory()->coach()->create([
            'google_refresh_token' => 'existing-refresh-token',
        ]);
        $oauth = Mockery::mock(GoogleCalendarOAuthService::class);
        $oauth->shouldReceive('isConfigured')->twice()->andReturnTrue();
        $oauth->shouldReceive('authorizationUrl')
            ->once()
            ->andReturnUsing(fn (string $state) => 'https://accounts.google.test/oauth?state='.$state);
        $oauth->shouldReceive('exchangeCode')
            ->once()
            ->andReturn([
                'access_token' => 'rotated-access-token',
                'expires_in' => 1800,
            ]);
        $this->app->instance(GoogleCalendarOAuthService::class, $oauth);

        $authorizationResponse = $this->actingAs($coach)
            ->get(route('settings.google-calendar.redirect'));
        parse_str((string) parse_url($authorizationResponse->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->get(route('settings.google-calendar.callback', [
            'state' => $query['state'],
            'code' => 'valid-auth-code',
        ]))->assertRedirect('/settings/availability');

        $coach->refresh();
        $this->assertSame('rotated-access-token', $coach->google_access_token);
        $this->assertSame('existing-refresh-token', $coach->google_refresh_token);
    }

    public function test_callback_handles_google_denial_without_overwriting_existing_tokens(): void
    {
        $coach = User::factory()->coach()->create([
            'google_access_token' => 'existing-access-token',
            'google_refresh_token' => 'existing-refresh-token',
            'google_token_expires_at' => now()->addHour(),
        ]);
        $oauth = Mockery::mock(GoogleCalendarOAuthService::class);
        $oauth->shouldReceive('isConfigured')->once()->andReturnTrue();
        $oauth->shouldReceive('authorizationUrl')
            ->once()
            ->andReturnUsing(fn (string $state) => 'https://accounts.google.test/oauth?state='.$state);
        $oauth->shouldNotReceive('exchangeCode');
        $this->app->instance(GoogleCalendarOAuthService::class, $oauth);

        $authorizationResponse = $this->actingAs($coach)
            ->get(route('settings.google-calendar.redirect'));
        parse_str((string) parse_url($authorizationResponse->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->get(route('settings.google-calendar.callback', [
            'state' => $query['state'],
            'error' => 'access_denied',
        ]))
            ->assertRedirect('/settings/availability')
            ->assertSessionHas('error');

        $this->assertSame('existing-access-token', $coach->fresh()->google_access_token);
        $this->assertSame('existing-refresh-token', $coach->fresh()->google_refresh_token);
    }

    public function test_callback_state_is_one_time_and_missing_code_is_rejected(): void
    {
        $coach = User::factory()->coach()->create();
        $oauth = Mockery::mock(GoogleCalendarOAuthService::class);
        $oauth->shouldReceive('isConfigured')->once()->andReturnTrue();
        $oauth->shouldReceive('authorizationUrl')
            ->once()
            ->andReturnUsing(fn (string $state) => 'https://accounts.google.test/oauth?state='.$state);
        $oauth->shouldNotReceive('exchangeCode');
        $this->app->instance(GoogleCalendarOAuthService::class, $oauth);

        $authorizationResponse = $this->actingAs($coach)
            ->get(route('settings.google-calendar.redirect'));
        parse_str((string) parse_url($authorizationResponse->headers->get('Location'), PHP_URL_QUERY), $query);

        $callbackUrl = route('settings.google-calendar.callback', ['state' => $query['state']]);
        $this->get($callbackUrl)
            ->assertRedirect('/settings/availability')
            ->assertSessionHas('error');

        $this->get(route('settings.google-calendar.callback', [
            'state' => $query['state'],
            'code' => 'replayed-code',
        ]))
            ->assertRedirect('/settings/availability')
            ->assertSessionHas('error');
    }

    public function test_authorization_and_callback_reject_external_redirect_paths(): void
    {
        $coach = User::factory()->coach()->create();
        $oauth = Mockery::mock(GoogleCalendarOAuthService::class);
        $oauth->shouldReceive('isConfigured')->twice()->andReturnTrue();
        $oauth->shouldReceive('authorizationUrl')
            ->once()
            ->andReturnUsing(fn (string $state) => 'https://accounts.google.test/oauth?state='.$state);
        $oauth->shouldReceive('exchangeCode')
            ->once()
            ->andReturn([
                'access_token' => 'access-token',
                'expires_in' => 3600,
            ]);
        $this->app->instance(GoogleCalendarOAuthService::class, $oauth);

        $authorizationResponse = $this->actingAs($coach)
            ->get(route('settings.google-calendar.redirect', [
                'redirect_path' => 'https://attacker.example.test',
            ]));
        parse_str((string) parse_url($authorizationResponse->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->get(route('settings.google-calendar.callback', [
            'state' => $query['state'],
            'code' => 'valid-code',
        ]))->assertRedirect('/settings/availability');
    }

    public function test_callback_rejects_invalid_state_without_saving_tokens(): void
    {
        $coach = User::factory()->coach()->create();
        $oauth = Mockery::mock(GoogleCalendarOAuthService::class);
        $oauth->shouldReceive('isConfigured')->once()->andReturnTrue();
        $oauth->shouldReceive('authorizationUrl')
            ->once()
            ->andReturnUsing(fn (string $state) => 'https://accounts.google.test/oauth?state='.$state);
        $oauth->shouldNotReceive('exchangeCode');
        $this->app->instance(GoogleCalendarOAuthService::class, $oauth);

        $authorizationResponse = $this->actingAs($coach)
            ->get(route('settings.google-calendar.redirect'));
        parse_str((string) parse_url($authorizationResponse->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->get(route('settings.google-calendar.callback', [
            'state' => 'invalid-state',
            'code' => 'valid-auth-code',
        ]))
            ->assertRedirect('/settings/availability')
            ->assertSessionHas('error');

        $this->assertNull($coach->fresh()->google_access_token);
    }

    public function test_non_coaches_cannot_use_google_calendar_routes(): void
    {
        foreach ([
            User::factory()->student()->create(),
            User::factory()->admin()->create(),
        ] as $user) {
            $this->actingAs($user)
                ->get(route('settings.google-calendar.redirect'))
                ->assertForbidden();

            $this->delete(route('settings.google-calendar.destroy'))
                ->assertForbidden();

            $this->get(route('settings.google-calendar.callback', [
                'state' => 'state',
                'code' => 'code',
            ]))->assertForbidden();
        }
    }

    public function test_coach_can_disconnect_google_calendar(): void
    {
        $coach = User::factory()->coach()->create([
            'google_access_token' => 'access-token',
            'google_refresh_token' => 'refresh-token',
            'google_token_expires_at' => now()->addHour(),
        ]);

        $this->actingAs($coach)
            ->delete(route('settings.google-calendar.destroy'))
            ->assertRedirect(route('settings.availability.index'))
            ->assertSessionHas('success');

        $coach->refresh();
        $this->assertNull($coach->google_access_token);
        $this->assertNull($coach->google_refresh_token);
        $this->assertNull($coach->google_token_expires_at);
    }
}
