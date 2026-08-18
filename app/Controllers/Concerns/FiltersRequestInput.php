<?php

declare(strict_types=1);

namespace App\Controllers\Concerns;

/**
 * Pembersih parameter filter dari query string.
 *
 * Nilai filter selalu terikat sebagai parameter query, sehingga tidak ada
 * risiko SQL injection. Namun tanggal yang tidak berbentuk tanggal tetap
 * diteruskan ke database dan ditolak di sana sebagai galat — pengguna melihat
 * halaman error 500 hanya karena salah ketik di URL.
 *
 * Trait ini menyaringnya lebih dulu: yang tidak berbentuk tanggal diperlakukan
 * seolah tidak diisi.
 */
trait FiltersRequestInput
{
    protected function dateInput(string $key, ?string $default = null): ?string
    {
        $value = trim((string) $this->request->getGet($key));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : $default;
    }

    /**
     * Id numerik dari query string; 0 berarti "tidak difilter".
     */
    protected function idInput(string $key): int
    {
        $value = $this->request->getGet($key);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : 0;
    }

    /**
     * Nilai dari daftar yang diizinkan; di luar itu dianggap tidak diisi.
     *
     * @param list<string> $allowed
     */
    protected function enumInput(string $key, array $allowed): string
    {
        $value = trim((string) $this->request->getGet($key));

        return in_array($value, $allowed, true) ? $value : '';
    }
}
