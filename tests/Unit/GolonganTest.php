<?php

namespace Tests\Unit;

use App\Support\Golongan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SUT: App\Support\Golongan — the one normaliser of golongan to the standard [Gol]/[Berkala] format (DOMAIN.md §2.2),
 * shared by the Bezetting rebuild and the manual correction of Riwayat Karir.
 */
class GolonganTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function cases(): array
    {
        return [
            'already standard' => ['IIIA/00', 'IIIA/00'],
            'one-digit step padded' => ['IVB/3', 'IVB/03'],
            'lower case and spaces' => ['ivb / 3', 'IVB/03'],
            'class without step' => ['IVD', 'IVD'],
            'not a golongan' => ['CKP', null],
            'unknown class letter' => ['IIIE/01', null],
            'empty' => ['', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('cases')]
    public function test_golongan_is_normalised_to_the_standard_format(?string $given, ?string $expected): void
    {
        $this->assertSame($expected, Golongan::standard($given));
    }
}
