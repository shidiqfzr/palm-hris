<?php

namespace App\Services\Bezetting;

use App\Models\Employee;
use App\Services\Import\ConcurrentJobTextParser;

/**
 * Splits a Bezetting position cell into the main position and its concurrent duties (DOMAIN.md §5.5 rule 6).
 *
 * "Asisten Manajemen Risiko merangkap Plt. Asisten Penggajian" → main "Asisten Manajemen Risiko" (Definitif) plus one
 * concurrent duty parsed by ConcurrentJobTextParser, the same rules Employee Import uses. A leading Plt/Pj/Pjs sets the
 * main status. Other leading phrases whose status is a business fact ("Penugasan sebagai …") are returned as a phrase
 * for an HR decision instead of being guessed.
 */
class PositionTextInterpreter
{
    /** Leading phrases whose status HR decides once (BezettingNameMapping::KIND_STATUS). */
    public const DECISION_PHRASES = ['PENUGASAN SEBAGAI'];

    private const STATUS_PREFIXES = [
        'PLT' => Employee::STATUS_PLT,
        'PJS' => Employee::STATUS_PJS,
        'PJ' => Employee::STATUS_PJ,
    ];

    public function __construct(private ConcurrentJobTextParser $concurrent) {}

    /**
     * @return array{main: array{jabatan: string, status: ?string, status_phrase: ?string},
     *     concurrent: list<array{jabatan: string, unit: string, status: string, tmt: ?string}>}
     */
    public function interpret(string $text, string $unit): array
    {
        $parts = preg_split('/\s*\bmerangkap\b\s*/iu', trim($text)) ?: [''];
        $main = $this->main(array_shift($parts));

        $concurrent = [];
        foreach ($parts as $part) {
            if (trim($part) === '') {
                continue;
            }
            $parsed = $this->concurrent->parse('merangkap '.$part, $unit);
            if ($parsed === null) {
                continue;
            }
            $concurrent[] = [
                'jabatan' => (string) $parsed['jabatan'],
                'unit' => (string) $parsed['unit_kerja'],
                'status' => (string) $parsed['status_penugasan'],
                'tmt' => $parsed['tmt_eksplisit'] ? $parsed['tmt_awal'] : null,
            ];
        }

        return ['main' => $main, 'concurrent' => $concurrent];
    }

    /** @return array{jabatan: string, status: ?string, status_phrase: ?string} */
    private function main(string $text): array
    {
        $text = trim($text);

        foreach (self::DECISION_PHRASES as $phrase) {
            $pattern = '/^'.str_replace(' ', '\s+', preg_quote($phrase, '/')).'\s+/iu';
            if (preg_match($pattern, $text) === 1) {
                return ['jabatan' => trim((string) preg_replace($pattern, '', $text)), 'status' => null, 'status_phrase' => $phrase];
            }
        }

        if (preg_match('/^(plt|pjs|pj)\.?\s+(.+)$/iu', $text, $m) === 1) {
            return ['jabatan' => trim($m[2]), 'status' => self::STATUS_PREFIXES[mb_strtoupper($m[1])], 'status_phrase' => null];
        }

        return ['jabatan' => $text, 'status' => Employee::STATUS_DEFINITIF, 'status_phrase' => null];
    }
}
