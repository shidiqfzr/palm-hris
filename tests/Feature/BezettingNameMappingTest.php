<?php

namespace Tests\Feature;

use App\Models\BezettingNameMapping;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SUT: BezettingNameMapping — one saved HR decision per normalized name and kind (DOMAIN.md §5.5 points 5–6).
 */
class BezettingNameMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalize_uppercases_and_collapses_whitespace_but_keeps_punctuation(): void
    {
        $this->assertSame('KEBUN INTI SUNGAI DEKAN (K.SAWIT)', BezettingNameMapping::normalize("  Kebun Inti  Sungai Dekan\t (K.SAWIT) "));
        $this->assertNotSame(BezettingNameMapping::normalize('Kebun Sintang (Karet)'), BezettingNameMapping::normalize('Kebun Sintang (K.Sawit)'));
    }

    public function test_a_decision_is_found_again_from_differently_written_text(): void
    {
        $user = User::factory()->create();
        BezettingNameMapping::create([
            'kind' => BezettingNameMapping::KIND_UNIT,
            'source_text' => 'KEBUN INTI GUNUNG MELIAU (K.SAWIT)',
            'decision' => BezettingNameMapping::DECISION_MAP,
            'target_value' => 'Kebun Gunung Meliau',
            'decided_by' => $user->id,
        ]);

        $found = BezettingNameMapping::decisionFor(BezettingNameMapping::KIND_UNIT, 'Kebun Inti  Gunung Meliau (K.Sawit)');

        $this->assertNotNull($found);
        $this->assertSame('Kebun Gunung Meliau', $found->target_value);
        $this->assertTrue($found->decider->is($user));
        $this->assertNull(BezettingNameMapping::decisionFor(BezettingNameMapping::KIND_JABATAN, 'Kebun Inti Gunung Meliau (K.Sawit)'));
    }

    public function test_the_same_name_and_kind_cannot_be_decided_twice(): void
    {
        $attributes = ['kind' => BezettingNameMapping::KIND_STATUS, 'source_text' => 'Penugasan sebagai', 'decision' => BezettingNameMapping::DECISION_KEEP];
        BezettingNameMapping::create($attributes);

        $this->expectException(QueryException::class);
        BezettingNameMapping::create(array_merge($attributes, ['source_text' => 'PENUGASAN  SEBAGAI']));
    }
}
