<?php

namespace Tests\Unit;

use App\Services\Bezetting\CareerHistoryDiffer;
use PHPUnit\Framework\TestCase;

/**
 * SUT: CareerHistoryDiffer — monthly snapshots to history segments (DOMAIN.md §5.5 rules 1 and 4). Pure, synthetic data.
 */
class CareerHistoryDifferTest extends TestCase
{
    private const NIK = '99000001';

    private function obs(string $jabatan, string $unit = 'Kebun Parindu', array $extra = []): array
    {
        return array_merge([
            'main' => ['jabatan' => $jabatan, 'unit' => $unit, 'status' => 'Definitif'],
            'concurrent' => [],
            'golongan' => 'IIIC/02',
            'tanggal_dalam_jabatan' => '2023-04-01',
            'tmt_unit_kerja' => '2023-04-01',
            'teks_sumber' => "{$unit} | {$jabatan}",
        ], $extra);
    }

    /** @param array<string, ?array> $byPeriod */
    private function differ(array $byPeriod): array
    {
        $snapshots = [];
        foreach ($byPeriod as $period => $obs) {
            $snapshots[$period] = $obs === null ? [] : [self::NIK => $obs];
        }

        return (new CareerHistoryDiffer)->diff(array_keys($byPeriod), $snapshots)[self::NIK];
    }

    public function test_an_unchanged_position_is_one_open_segment_starting_on_the_date_in_the_file(): void
    {
        $result = $this->differ(['2025-01' => $this->obs('Asisten Afdeling I'), '2025-02' => $this->obs('Asisten Afdeling I'), '2025-03' => $this->obs('Asisten Afdeling I')]);

        $this->assertCount(1, $result['segments']);
        $segment = $result['segments'][0];
        $this->assertSame('PENEMPATAN_AWAL', $segment['perubahan']);
        $this->assertSame('2023-04-01', $segment['tmt_awal'], 'the first period has no lower bound: the true start may predate the window');
        $this->assertFalse($segment['diperkirakan']);
        $this->assertNull($segment['tmt_akhir']);
        $this->assertTrue($result['present_at_end']);
    }

    public function test_a_periodic_step_change_is_not_a_career_event_but_a_class_change_is(): void
    {
        $result = $this->differ([
            '2025-06' => $this->obs('Asisten Afdeling I'),
            '2025-07' => $this->obs('Asisten Afdeling I', extra: ['golongan' => 'IIIC/03']),
            '2025-08' => $this->obs('Asisten Afdeling I', extra: ['golongan' => 'IIID/00']),
        ]);

        $this->assertCount(2, $result['segments']);
        $this->assertSame('PERUBAHAN_GOLONGAN', $result['segments'][1]['perubahan']);
        $this->assertSame('2025-08-01', $result['segments'][1]['tmt_awal']);
        $this->assertTrue($result['segments'][1]['diperkirakan'], 'the file holds no golongan date');
        $this->assertSame('2025-07-31', $result['segments'][0]['tmt_akhir']);
    }

    public function test_a_position_change_uses_the_date_in_the_file_when_it_falls_inside_the_window(): void
    {
        $result = $this->differ([
            '2025-01' => $this->obs('Asisten Afdeling I'),
            '2025-02' => $this->obs('Asisten Kepala', extra: ['tanggal_dalam_jabatan' => '2025-01-15']),
        ]);

        [$old, $new] = $result['segments'];
        $this->assertSame('PERUBAHAN_JABATAN', $new['perubahan']);
        $this->assertSame('2025-01-15', $new['tmt_awal']);
        $this->assertFalse($new['diperkirakan']);
        $this->assertSame('2025-01-14', $old['tmt_akhir']);
    }

    public function test_a_stale_date_in_the_file_gives_an_estimated_start_on_the_month_the_change_is_seen(): void
    {
        $result = $this->differ([
            '2025-01' => $this->obs('Asisten Afdeling I'),
            '2025-02' => $this->obs('Asisten Kepala', extra: ['tanggal_dalam_jabatan' => '2023-04-01']),
        ]);

        $this->assertSame('2025-02-01', $result['segments'][1]['tmt_awal']);
        $this->assertTrue($result['segments'][1]['diperkirakan']);
        $this->assertSame('2025-01-31', $result['segments'][0]['tmt_akhir']);
    }

    public function test_a_unit_change_reads_the_unit_date(): void
    {
        $result = $this->differ([
            '2025-01' => $this->obs('Asisten Afdeling I'),
            '2025-02' => $this->obs('Asisten Afdeling I', 'Kebun Gunung Meliau', ['tmt_unit_kerja' => '2025-02-01', 'tanggal_dalam_jabatan' => '2023-04-01']),
        ]);

        $this->assertSame('PERUBAHAN_UNIT', $result['segments'][1]['perubahan']);
        $this->assertSame('2025-02-01', $result['segments'][1]['tmt_awal']);
        $this->assertFalse($result['segments'][1]['diperkirakan']);
    }

    public function test_leaving_the_list_closes_the_position_at_the_end_of_the_last_month_seen(): void
    {
        $result = $this->differ(['2025-01' => $this->obs('Manajer Kebun'), '2025-02' => $this->obs('Manajer Kebun'), '2025-03' => null]);

        $this->assertSame('2025-02-28', $result['segments'][0]['tmt_akhir']);
        $this->assertFalse($result['present_at_end']);
        $this->assertSame('2025-02', $result['last_seen']);
        $this->assertSame([], $result['gaps']);
    }

    public function test_leaving_and_returning_records_a_gap_and_does_not_reuse_a_date_from_before_the_gap(): void
    {
        $result = $this->differ(['2025-01' => $this->obs('Manajer Kebun'), '2025-02' => null, '2025-03' => $this->obs('Manajer Kebun')]);

        $this->assertSame([['dari' => '2025-02', 'sampai' => '2025-02']], $result['gaps']);
        $this->assertCount(2, $result['segments']);
        $this->assertSame('2025-01-31', $result['segments'][0]['tmt_akhir']);
        $this->assertSame('2025-03-01', $result['segments'][1]['tmt_awal']);
        $this->assertTrue($result['segments'][1]['diperkirakan']);
    }

    public function test_first_appearance_later_in_the_window_with_an_old_date_is_estimated(): void
    {
        $result = $this->differ(['2025-01' => null, '2025-02' => $this->obs('Asisten Afdeling II')]);

        $this->assertSame('2025-02-01', $result['segments'][0]['tmt_awal']);
        $this->assertTrue($result['segments'][0]['diperkirakan']);
        $this->assertSame('2025-02', $result['first_seen']);
    }

    public function test_a_concurrent_duty_is_its_own_lane_opened_and_closed_with_the_months_it_is_seen(): void
    {
        $duty = ['jabatan' => 'Asisten Penggajian', 'unit' => 'Kebun Parindu', 'status' => 'Plt', 'tmt' => null];
        $result = $this->differ([
            '2025-01' => $this->obs('Asisten Manajemen Risiko'),
            '2025-02' => $this->obs('Asisten Manajemen Risiko', extra: ['concurrent' => [$duty]]),
            '2025-03' => $this->obs('Asisten Manajemen Risiko'),
        ]);

        $this->assertCount(2, $result['segments']);
        $concurrent = $result['segments'][1];
        $this->assertSame(CareerHistoryDiffer::LANE_CONCURRENT, $concurrent['lane']);
        $this->assertSame('TUGAS_RANGKAP', $concurrent['perubahan']);
        $this->assertSame('Plt', $concurrent['status']);
        $this->assertSame('2025-02-01', $concurrent['tmt_awal']);
        $this->assertSame('2025-02-28', $concurrent['tmt_akhir']);
        $this->assertNull($result['segments'][0]['tmt_akhir'], 'the main position is unaffected');
    }

    public function test_golongan_class_ignores_the_periodic_step(): void
    {
        $this->assertSame('IIIC', CareerHistoryDiffer::golonganClass('IIIC/02'));
        $this->assertSame('IVA', CareerHistoryDiffer::golonganClass('iva/00'));
        $this->assertSame('', CareerHistoryDiffer::golonganClass(''));
    }
}
