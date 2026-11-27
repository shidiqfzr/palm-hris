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

    public function test_a_bracketed_merangkap_leaves_no_dangling_bracket_on_the_position(): void
    {
        // Real shapes from the monthly files: the bracket opens before "merangkap" and closes after the duty.
        foreach ([
            'Asisten Personalia (merangkap Plt. Kepala Sub Bagian Personalia & Manajemen Kinerja SDM tmt 1 April 2025)' => 'Asisten Personalia',
            'Manajer (merangkap Plt. Manajer PKS Pamukan tmt 12 Maret 2025)' => 'Manajer',
            'Asisten Personalia & Teknik Kebun (merangkap Asisten Tata Usaha & Keuangan tmt 1 Februari 2024)' => 'Asisten Personalia & Teknik Kebun',
            'Staf Sub Bagian Pengembangan dan Pelatihan (merangkap Staf Sub Bagian Transformasi Budaya)' => 'Staf Sub Bagian Pengembangan dan Pelatihan',
        ] as $text => $expectedMain) {
            $result = $this->interpreter()->interpret($text, 'Regional Office');

            $this->assertSame($expectedMain, $result['main']['jabatan'], $text);
            $this->assertCount(1, $result['concurrent'], $text);
            $this->assertStringNotContainsString(')', $result['concurrent'][0]['jabatan'], 'no stray closing bracket on the duty either');
        }
    }

    public function test_a_duty_in_brackets_without_merangkap_is_read_as_the_same_concurrent_duty(): void
    {
        $result = $this->interpreter()->interpret('Asisten Tata Usaha & Keuangan (Plt. Kepala Sub Bagian HPS tmt 1 September 2023)', 'Regional Office');

        $this->assertSame('Asisten Tata Usaha & Keuangan', $result['main']['jabatan']);
        $this->assertSame(Employee::STATUS_DEFINITIF, $result['main']['status']);
        $this->assertCount(1, $result['concurrent']);
        $this->assertSame(Employee::STATUS_PLT, $result['concurrent'][0]['status']);
        $this->assertSame('2023-09-01', $result['concurrent'][0]['tmt']);
    }

    public function test_merangkap_inside_a_longer_bracketed_note_is_not_split_into_half_open_brackets(): void
    {
        $text = 'Asisten Tata Usaha & Keuangan (Pengumandahan Asisten Tata Usaha & Keuangan Kebun Tabara merangkap Asisten Tata Usaha & Keuangan PKS Samuntai)';

        $result = $this->interpreter()->interpret($text, 'Regional Office');

        $this->assertSame($text, $result['main']['jabatan'], 'left whole for an HR decision');
        $this->assertSame([], $result['concurrent']);
    }

    public function test_brackets_that_belong_to_the_post_itself_are_kept(): void
    {
        foreach (['Asisten Kepala Tanaman (Rayon Utara)', 'Kepala Bidang Operasional (UGKST)', 'Plt. Staf Kantor Penghubung Jakarta (Dedicated LO)', 'Asisten Inti VI (Penugasan Asisten Afdeling I Kebun Melati, KSO Regional II)'] as $text) {
            $result = $this->interpreter()->interpret($text, 'Regional Office');

            $this->assertStringContainsString('(', $result['main']['jabatan'], $text);
            $this->assertSame([], $result['concurrent'], $text);
        }
    }

    public function test_an_explicit_tmt_on_a_concurrent_duty_is_kept(): void
    {
        $result = $this->interpreter()->interpret('Asisten Tanaman merangkap Plt. Asisten Teknik TMT 15 Juli 2026', 'Kebun Parindu');

        $this->assertSame('2026-07-15', $result['concurrent'][0]['tmt']);
    }

    public function test_concurrent_pj_duty_preserves_pj_status(): void
    {
        $result = $this->interpreter()->interpret('Asisten Tanaman merangkap Pj. Asisten Kepala', 'Kebun Parindu');

        $this->assertCount(1, $result['concurrent']);
        $this->assertSame(Employee::STATUS_PJ, $result['concurrent'][0]['status']);
    }
}
