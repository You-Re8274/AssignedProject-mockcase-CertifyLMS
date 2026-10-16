<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Certification;

use App\Enums\CertificationStatus;
use App\Models\Certification;
use App\Models\User;
use App\UseCases\Certification\IndexAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndexActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_filters_by_each_requested_status(): void
    {
        $admin = User::factory()->admin()->create();
        $certifications = [
            CertificationStatus::Draft->value => Certification::factory()->draft()->create(),
            CertificationStatus::Published->value => Certification::factory()->published()->create(),
            CertificationStatus::Archived->value => Certification::factory()->archived()->create(),
        ];
        $action = new IndexAction;

        foreach (CertificationStatus::cases() as $status) {
            $results = $action($admin, null, $status, null, null);

            $this->assertSame(
                [$certifications[$status->value]->id],
                $results->getCollection()->modelKeys(),
            );
        }
    }
}
