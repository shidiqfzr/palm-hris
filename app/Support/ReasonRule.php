<?php

namespace App\Support;

use App\Services\EmployeeDeletionService;

/**
 * One rule and one wording for every mandatory reason (SECURITY.md items 6a, 6b, 1d): 10–500 characters, the limits of
 * EmployeeDeletionService. Callers name the action, e.g. "penghapusan" or "perubahan".
 */
final class ReasonRule
{
    /** @return list<string> */
    public static function rules(): array
    {
        return EmployeeDeletionService::REASON_RULES;
    }

    /** @return array<string, string> validation messages for $field */
    public static function messages(string $field, string $action): array
    {
        $min = EmployeeDeletionService::REASON_MIN_LENGTH;
        $max = EmployeeDeletionService::REASON_MAX_LENGTH;
        $subject = 'Alasan '.$action;

        return [
            "{$field}.required" => "{$subject} wajib diisi (minimal {$min} karakter).",
            "{$field}.min" => "{$subject} minimal {$min} karakter.",
            "{$field}.max" => "{$subject} maksimal {$max} karakter.",
            "{$field}.string" => "{$subject} harus berupa teks.",
        ];
    }
}
