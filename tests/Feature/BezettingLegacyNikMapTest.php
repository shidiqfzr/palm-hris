<?php

namespace Tests\Feature;

use App\Models\BezettingLegacyNikMap;
use App\Models\Employee;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SUT: BezettingLegacyNikMap — canonicalization, keyed HMAC-SHA256 hashing, uniqueness, and cascade delete on employee
 * removal (DOMAIN.md §5.5, SECURITY.md §4.4).
 */
class BezettingLegacyNikMapTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonicalize_accepts_14_and_15_digit_legacy_ptpn13_numbers_and_rejects_others(): void
    {
        $this->assertSame('000071612345678', BezettingLegacyNikMap::canonicalize('000.0716.1234.5678'));
        $this->assertSame('000071612345678', BezettingLegacyNikMap::canonicalize(' 000 0716 1234 5678 '));
        $this->assertSame('00071612345678', BezettingLegacyNikMap::canonicalize('00.0716.1234.5678'));
        $this->assertSame('00071612345678', BezettingLegacyNikMap::canonicalize('00071612345678'));

        $this->assertNull(BezettingLegacyNikMap::canonicalize('12345678'));
        $this->assertNull(BezettingLegacyNikMap::canonicalize('1234567'));
        $this->assertNull(BezettingLegacyNikMap::canonicalize(''));
        $this->assertNull(BezettingLegacyNikMap::canonicalize('ABC.0716.1234.5678'));
    }

    public function test_hash_is_deterministic_64_char_hex_and_never_contains_raw_digits(): void
    {
        $hashA = BezettingLegacyNikMap::hashFromRaw('000.0716.1234.5678');
        $hashB = BezettingLegacyNikMap::hashFromRaw('000071612345678');

        $this->assertNotNull($hashA);
        $this->assertSame(64, strlen($hashA));
        $this->assertSame($hashA, $hashB);
        $this->assertStringNotContainsString('07161234', $hashA);
        $this->assertNull(BezettingLegacyNikMap::hashFromRaw('12345678'));
    }

    public function test_enforces_unique_hash_and_cascades_when_employee_is_deleted(): void
    {
        $employee = Employee::factory()->create(['nik' => '12345678']);
        $hash = BezettingLegacyNikMap::hashFromRaw('000.0716.1234.5678');

        BezettingLegacyNikMap::create([
            'legacy_nik_hash' => $hash,
            'employee_nik' => $employee->nik,
        ]);

        try {
            BezettingLegacyNikMap::create([
                'legacy_nik_hash' => $hash,
                'employee_nik' => $employee->nik,
            ]);
            $this->fail('Expected duplicate legacy_nik_hash to fail.');
        } catch (QueryException) {
            $this->assertSame(1, BezettingLegacyNikMap::count());
        }

        $employee->delete();
        $this->assertSame(0, BezettingLegacyNikMap::count());
    }
}
