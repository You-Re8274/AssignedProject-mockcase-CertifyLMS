<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ContentStatus;
use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\SectionProgress;
use App\Models\User;
use App\Services\Learning\LearningProgressService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LearningProgressServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_summarize_preserves_section_chapter_and_part_counts_and_ratios(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();
        $part = Part::factory()->for($certification)->create(['status' => ContentStatus::Published->value]);
        $completedChapter = Chapter::factory()->for($part)->create(['status' => ContentStatus::Published->value]);
        $partialChapter = Chapter::factory()->for($part)->create(['status' => ContentStatus::Published->value]);
        $completedSections = [
            Section::factory()->for($completedChapter)->create(['status' => ContentStatus::Published->value]),
            Section::factory()->for($partialChapter)->create(['status' => ContentStatus::Published->value]),
        ];
        Section::factory()->for($partialChapter)->create(['status' => ContentStatus::Published->value]);

        foreach ($completedSections as $section) {
            SectionProgress::factory()->forEnrollment($enrollment)->forSection($section)->create();
        }

        $summary = app(LearningProgressService::class)->summarize($enrollment);

        $this->assertSame(3, $summary->sectionsTotal);
        $this->assertSame(2, $summary->sectionsCompleted);
        $this->assertSame(0.6667, $summary->sectionCompletionRatio);
        $this->assertSame(2, $summary->chaptersTotal);
        $this->assertSame(1, $summary->chaptersCompleted);
        $this->assertSame(0.5, $summary->chapterCompletionRatio);
        $this->assertSame(1, $summary->partsTotal);
        $this->assertSame(0, $summary->partsCompleted);
        $this->assertSame(0.0, $summary->partCompletionRatio);
        $this->assertSame(0.6667, $summary->overallCompletionRatio);
    }

    public function test_batch_section_ratios_and_hierarchical_helpers_preserve_existing_values(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();
        $otherEnrollment = Enrollment::factory()->for($otherStudent)->for($certification)->learning()->create();
        $part = Part::factory()->for($certification)->create(['status' => ContentStatus::Published->value]);
        $chapter = Chapter::factory()->for($part)->create(['status' => ContentStatus::Published->value]);
        $first = Section::factory()->for($chapter)->create(['status' => ContentStatus::Published->value]);
        $second = Section::factory()->for($chapter)->create(['status' => ContentStatus::Published->value]);

        SectionProgress::factory()->forEnrollment($enrollment)->forSection($first)->create();

        $service = app(LearningProgressService::class);
        $ratios = $service->batchSectionRatios(new Collection([
            $enrollment,
            $otherEnrollment,
        ]));

        $this->assertSame(0.5, $ratios[$enrollment->id]);
        $this->assertSame(0.0, $ratios[$otherEnrollment->id]);
        $this->assertSame([$first->id], $service->completedSectionIds(
            $enrollment,
            collect([$first->id, $second->id]),
        ));
        $this->assertSame([$chapter->id => 1], $service->completedCountsByChapter(
            $enrollment,
            collect([$chapter]),
        ));
    }

    public function test_summarize_returns_zeroes_when_certification_has_no_published_content(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();

        $summary = app(LearningProgressService::class)->summarize($enrollment);

        $this->assertSame(0, $summary->sectionsTotal);
        $this->assertSame(0, $summary->sectionsCompleted);
        $this->assertSame(0.0, $summary->overallCompletionRatio);
        $this->assertSame(0, $summary->chaptersCompleted);
        $this->assertSame(0, $summary->partsCompleted);
    }
}
