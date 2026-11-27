<?php

namespace Tests\Feature;

use App\Services\Bezetting\BackfillPlanner;
use App\Services\Bezetting\BezettingBackfillService;
use App\Support\BezettingReviewGuide;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * SUT: BezettingReviewGuide and x-admin.table.guide — one guide shape for every review tab of a Bezetting rebuild run
 * (DESIGN.md §8.6a): title, intro, terms with their meaning and, where HR must act, what to do.
 */
class BezettingReviewGuideTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private function guides(): array
    {
        return [
            'names' => BezettingReviewGuide::names(),
            'history' => BezettingReviewGuide::history(21),
            'follow-ups' => BezettingReviewGuide::followUps(false, 90),
            'exclusions' => BezettingReviewGuide::exclusions(),
            'upload' => BezettingReviewGuide::upload(24),
            'workflow' => BezettingReviewGuide::workflow(7, 7),
        ];
    }

    public function test_every_tab_guide_has_the_same_shape_and_plain_business_wording(): void
    {
        foreach ($this->guides() as $tab => $guide) {
            $this->assertSame(['title', 'intro', 'items', 'note'], array_keys($guide), $tab);
            $this->assertNotSame('', $guide['title'], $tab);
            $this->assertNotSame('', $guide['intro'], $tab);
            $this->assertNotSame([], $guide['items'], $tab);
            foreach ($guide['items'] as $item) {
                $this->assertNotSame('', $item['term'], $tab);
                $this->assertNotSame('', $item['arti'], $tab);
            }
            // Internal document names and acronyms never reach what an admin reads.
            $this->assertDoesNotMatchRegularExpression('/\.md\b|SSOT|AGENTS|DOMAIN|DESIGN|ENGINEERING|SECURITY/', json_encode($guide), $tab);
        }
    }

    public function test_every_follow_up_type_and_exclusion_reason_has_a_meaning_and_an_action(): void
    {
        $followUps = array_column(BezettingReviewGuide::followUps(false, 90)['items'], null, 'term');
        foreach (BezettingBackfillService::FOLLOWUP_LABELS as $type => $label) {
            $this->assertArrayHasKey($label, $followUps, "follow-up {$type}");
            $this->assertNotSame('', $followUps[$label]['tindakan']);
        }

        $exclusions = array_column(BezettingReviewGuide::exclusions()['items'], null, 'term');
        foreach (BackfillPlanner::EXCLUSION_LABELS as $reason => $label) {
            $this->assertArrayHasKey($label, $exclusions, "exclusion {$reason}");
            $this->assertNotSame('', $exclusions[$label]['arti']);
            $this->assertNotSame('', $exclusions[$label]['tindakan']);
        }
    }

    public function test_the_exclusion_guide_can_be_limited_to_the_reasons_that_occur(): void
    {
        $items = BezettingReviewGuide::exclusions([BackfillPlanner::EXCLUDED_NOT_IN_APP])['items'];

        $this->assertSame([BackfillPlanner::EXCLUSION_LABELS[BackfillPlanner::EXCLUDED_NOT_IN_APP]], array_column($items, 'term'));
    }

    public function test_a_saved_run_says_how_long_the_follow_up_list_is_kept_and_an_open_run_does_not(): void
    {
        $this->assertNull(BezettingReviewGuide::followUps(false, 90)['note']);
        $this->assertSame('Daftar ini disimpan 90 hari sesudah rekonstruksi disimpan.', BezettingReviewGuide::followUps(true, 90)['note']);
    }

    public function test_the_component_renders_every_guide_the_same_way_and_marks_the_action_only_where_there_is_one(): void
    {
        foreach ($this->guides() as $tab => $guide) {
            $html = Blade::render('<x-admin.table.guide :guide="$guide" />', ['guide' => $guide]);

            $this->assertStringContainsString('aria-label="'.$guide['title'].'"', $html, $tab);
            $this->assertStringContainsString('<dl', $html, $tab);
            $withAction = count(array_filter($guide['items'], fn (array $item) => ! empty($item['tindakan'])));
            $this->assertSame($withAction, substr_count($html, 'Yang perlu dilakukan:'), "{$tab}: the action line appears once per item that has one");
        }
    }

    public function test_every_guide_starts_closed_the_same_way_and_opens_from_its_title(): void
    {
        $guide = BezettingReviewGuide::names();

        $closed = Blade::render('<x-admin.table.guide :guide="$guide" />', ['guide' => $guide]);
        $this->assertDoesNotMatchRegularExpression('/<details[^>]*\sopen/', $closed);
        $this->assertStringContainsString('<summary', $closed);
        $this->assertStringContainsString('Tampilkan', $closed);

        $open = Blade::render('<x-admin.table.guide :guide="$guide" :open="true" />', ['guide' => $guide]);
        $this->assertMatchesRegularExpression('/<details[^>]*\sopen/', $open);
        $this->assertStringContainsString('Sembunyikan', $open);
    }

    public function test_the_component_passes_extra_content_through_its_slot_and_escapes_the_texts(): void
    {
        $guide = ['title' => 'Cara membaca', 'intro' => '<script>alert(1)</script>', 'items' => [['term' => 'A', 'arti' => 'B']], 'note' => null];

        $html = Blade::render('<x-admin.table.guide :guide="$guide"><span>contoh tambahan</span></x-admin.table.guide>', ['guide' => $guide]);

        $this->assertStringContainsString('contoh tambahan', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }
}
