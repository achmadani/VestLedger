<?php

declare(strict_types=1);

namespace App\Controllers\Concerns;

use App\Exceptions\BusinessRuleException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Menerjemahkan pelanggaran aturan bisnis menjadi flash message.
 *
 * Dengan ini controller tetap tipis: ia tidak perlu tahu aturan apa pun,
 * cukup meneruskan pesan yang sudah dirumuskan service layer (§29).
 */
trait HandlesBusinessRules
{
    protected function redirectWithRuleError(BusinessRuleException $e, string $to): RedirectResponse
    {
        $messages = array_merge([$e->getMessage()], $e->reasons());

        return redirect()->to($to)->withInput()->with('error', $messages);
    }

    /**
     * Flash error dari hasil validasi model.
     *
     * @param array<string, string> $errors
     */
    protected function redirectWithValidationErrors(array $errors, string $to): RedirectResponse
    {
        return redirect()->to($to)->withInput()->with('errors', array_values($errors));
    }
}
