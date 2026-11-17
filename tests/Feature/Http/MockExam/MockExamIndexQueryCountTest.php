<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MockExam;

use App\Models\Certification;
use App\Models\MockExam;
use App\Models\MockExamQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 模試マスタ一覧 (`admin.mock-exams.index`) の N+1 非回帰を検証する Feature テスト。
 * 所属資格 / 作成者 / 更新者 / 問題数を一括取得することで、模試の件数を増やしても
 * 発行クエリ数がほぼ増えない (各行の関連参照で遅延ロードが発火しない) ことを担保する。
 */
class MockExamIndexQueryCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_preserves_row_details_and_order_while_loading_relations(): void
    {
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create([
            'name' => '表示確認用資格',
        ]);
        $creator = User::factory()->admin()->create(['name' => '模試作成者']);
        $updater = User::factory()->admin()->create(['name' => '模試更新者']);

        $first = MockExam::factory()->forCertification($certification)->create([
            'title' => '先に表示される模試',
            'order' => 1,
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $updater->id,
        ]);
        MockExamQuestion::factory()->count(2)->forMockExam($first)->create();

        $second = MockExam::factory()->forCertification($certification)->create([
            'title' => '後に表示される模試',
            'order' => 2,
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $updater->id,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.mock-exams.index'));

        $response->assertOk()
            ->assertSeeInOrder(['先に表示される模試', '後に表示される模試'])
            ->assertSee('表示確認用資格')
            ->assertSee('模試更新者');

        $this->assertMatchesRegularExpression(
            '/<td[^>]*text-right tabular-nums[^>]*>\s*2\s*<\/td>/s',
            $response->getContent(),
        );

        $response->assertViewHas('mockExams', fn ($mockExams): bool => $mockExams->every(
            fn (MockExam $mockExam, int $index): bool => $mockExam->relationLoaded('certification')
                && $mockExam->relationLoaded('createdBy')
                && $mockExam->relationLoaded('updatedBy')
                && $mockExam->mock_exam_questions_count === ($index === 0 ? 2 : 0),
        ));
    }

    public function test_index_query_count_does_not_grow_with_mock_exam_count(): void
    {
        // Arrange: 管理者 + 共通資格に紐づく模試 2 件 (基準)
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();
        $this->createMockExams($certification, 2);

        // Act: 基準のクエリ数を計測 → 模試を 10 件追加して再計測 (1 ページに収まる件数)
        $baseline = $this->countQueriesFor(
            fn () => $this->actingAs($admin)->get(route('admin.mock-exams.index'))
        );
        $this->createMockExams($certification, 10);
        $scaled = $this->countQueriesFor(
            fn () => $this->actingAs($admin)->get(route('admin.mock-exams.index'))
        );

        // Assert: 模試が増えても発行クエリ数はほぼ一定 (N+1 なら件数分増える)
        $this->assertLessThanOrEqual(
            $baseline + 3,
            $scaled,
            "模試マスタ一覧で N+1 が再発している (基準 {$baseline} → 増加後 {$scaled})。所属資格 / 作成者 / 更新者 / 問題数を Eager Loading + withCount で一括取得しているか確認",
        );
    }

    private function createMockExams(Certification $certification, int $count): void
    {
        MockExam::factory()->count($count)->forCertification($certification)->create();
    }

    private function countQueriesFor(\Closure $closure): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $closure();

        return $count;
    }
}
