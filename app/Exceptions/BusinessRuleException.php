<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Pelanggaran aturan bisnis yang layak ditampilkan kepada pengguna.
 *
 * Dibedakan dari error teknis: pesannya sudah berbahasa manusia dan aman
 * ditampilkan di UI, sehingga controller cukup menangkapnya lalu mengembalikan
 * pesan tersebut sebagai flash message.
 */
class BusinessRuleException extends RuntimeException
{
    /**
     * @param list<string> $reasons Rincian alasan, bila ada lebih dari satu.
     */
    public function __construct(string $message, private array $reasons = [])
    {
        parent::__construct($message);
    }

    /**
     * @return list<string>
     */
    public function reasons(): array
    {
        return $this->reasons;
    }
}
