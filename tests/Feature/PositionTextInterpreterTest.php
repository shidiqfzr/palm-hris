<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Position;
use App\Models\Unit;
use App\Services\Bezetting\PositionTextInterpreter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SUT: PositionTextInterpreter — main position, status prefix and concurrent duties from a Bezetting position cell
 * (DOMAIN.md §5.5 rule 6). Concurrent duties reuse the Employee Import parser and its master lookups.
 */
class PositionTextInterpreterTest extends TestCase
{
    use RefreshDatabase;

    private function interpreter(): PositionTextInterpreter
    {
        return app(PositionTextInterpreter::class);
    }

    public function test_a_plain_position_is_definitive_with_no_concurrent_duty(): void
    {
        $result = $this->interpreter()->interpret('Asisten Afdeling V', 'Kebun Parindu');

        $this->assertSame(['jabatan' => 'Asisten Afdeling V', 'status' => Employee::STATUS_DEFINITIF, 'status_phrase' => null], $result['main']);
        $this->assertSame([], $result['concurrent']);
    }

    public function test_a_leading_status_prefix_sets_the_main_status(): void
    {
        $this->assertSame(Employee::STATUS_PLT, $this->interpreter()->interpret('Plt. Manajer Kebun', 'Kebun Parindu')['main']['status']);
        $this->assertSame(Employee::STATUS_PJS, $this->interpreter()->interpret('Pjs Manajer Kebun', 'Kebun Parindu')['main']['status']);
        $this->assertSame('Manajer Kebun', $this->interpreter()->interpret('Pj. Manajer Kebun', 'Kebun Parindu')['main']['jabatan']);
    }

    public function test_a_status_phrase_that_is_a_business_fact_is_returned_for_an_hr_decision_not_guessed(): void
    {
        $main = $this->interpreter()->interpret('Penugasan  sebagai Kepala Bagian Tanaman', 'BAGIAN TANAMAN')['main'];

        $this->assertSame('Kepala Bagian Tanaman', $main['jabatan']);
        $this->assertNull($main['status']);
        $this->assertSame('PENUGASAN SEBAGAI', $main['status_phrase']);
    }

    public function test_a_concurrent_duty_becomes_a_separate_assignment_with_the_parser_rules(): void
    {
        Unit::factory()->create(['nama' => 'Regional Office']);
        Position::factory()->create(['nama' => 'Asisten Penggajian']);

        $result = $this->interpreter()->interpret('Asisten Manajemen Risiko merangkap Plt. Asisten Penggajian', 'Regional Office');

        $this->assertSame('Asisten Manajemen Risiko', $result['main']['jabatan']);
        $this->assertSame(Employee::STATUS_DEFINITIF, $result['main']['status']);
        $this->assertCount(1, $result['concurrent']);
        $this->assertSame('Asisten Penggajian', $result['concurrent'][0]['jabatan']);
        $this->assertSame('Regional Office', $result['concurrent'][0]['unit']);
        $this->assertSame(Employee::STATUS_PLT, $result['concurrent'][0]['status']);
        $this->assertNull($result['concurrent'][0]['tmt']);
    }

    public function test_an_explicit_tmt_on_a_concurrent_duty_is_kept(): void
    {
        $result = $this->interpreter()->interpret('Asisten Tanaman merangkap Plt. Asisten Teknik TMT 15 Juli 2026', 'Kebun Parindu');

        $this->assertSame('2026-07-15', $result['concurrent'][0]['tmt']);
    }
}
