<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Enums\AccountCode;
use App\Enums\BalanceSide;
use App\Enums\JournalEntryType;
use App\Enums\SourceType;

/**
 * Jurnal yang sedang disusun, sebelum divalidasi dan disimpan (§8).
 *
 * Objek ini sengaja dibuat terpisah dari model: seluruh service transaksi
 * menyusun draft memakai kosakata akuntansi (debit akun ini, kredit akun itu),
 * lalu JournalPoster yang memvalidasi dan menyimpannya. Dengan begitu tidak ada
 * satu pun service transaksi yang menulis langsung ke tabel jurnal.
 */
final class JournalDraft
{
    /** @var list<JournalLineDraft> */
    private array $lines = [];

    public function __construct(
        public readonly string $date,
        public readonly string $description,
        public readonly SourceType $sourceType,
        public readonly ?int $sourceId = null,
        public readonly JournalEntryType $type = JournalEntryType::Normal,
        public readonly ?int $reversesEntryId = null,
    ) {
    }

    /**
     * Baris debit. Baris bernilai nol diabaikan — biaya yang tidak dikenakan
     * tidak perlu memenuhi jurnal dengan baris kosong.
     */
    public function debit(
        AccountCode|int $account,
        Money $amount,
        ?int $securitiesAccountId = null,
        ?int $stockId = null,
        ?string $memo = null,
    ): self {
        return $this->addLine(BalanceSide::Debit, $account, $amount, $securitiesAccountId, $stockId, $memo);
    }

    public function credit(
        AccountCode|int $account,
        Money $amount,
        ?int $securitiesAccountId = null,
        ?int $stockId = null,
        ?string $memo = null,
    ): self {
        return $this->addLine(BalanceSide::Credit, $account, $amount, $securitiesAccountId, $stockId, $memo);
    }

    private function addLine(
        BalanceSide $side,
        AccountCode|int $account,
        Money $amount,
        ?int $securitiesAccountId,
        ?int $stockId,
        ?string $memo,
    ): self {
        if ($amount->isZero()) {
            return $this;
        }

        // Nilai negatif tidak pernah dicatat sebagai "debit negatif"; ia dibalik
        // ke sisi lawan. Debit negatif membuat total debit dan kredit tampak
        // sama besar padahal arah pencatatannya keliru.
        if ($amount->isNegative()) {
            $side   = $side->opposite();
            $amount = $amount->abs();
        }

        $this->lines[] = new JournalLineDraft($account, $side, $amount, $securitiesAccountId, $stockId, $memo);

        return $this;
    }

    /**
     * @return list<JournalLineDraft>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function totalDebit(): Money
    {
        return array_reduce(
            $this->lines,
            static fn (Money $carry, JournalLineDraft $line): Money => $carry->add($line->debit()),
            Money::zero()
        );
    }

    public function totalCredit(): Money
    {
        return array_reduce(
            $this->lines,
            static fn (Money $carry, JournalLineDraft $line): Money => $carry->add($line->credit()),
            Money::zero()
        );
    }

    /**
     * Aturan fundamental (§8): total debit harus sama persis dengan total kredit.
     */
    public function isBalanced(): bool
    {
        return $this->totalDebit()->equals($this->totalCredit());
    }

    public function difference(): Money
    {
        return $this->totalDebit()->subtract($this->totalCredit());
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /**
     * Draft pembalik: setiap baris ditukar sisinya (§26, §40.8).
     *
     * Membalik dengan menukar sisi — bukan dengan mencatat nilai negatif —
     * menjaga agar seluruh saldo debit/kredit tetap positif dan Trial Balance
     * tetap terbaca wajar.
     */
    public function reversed(string $date, string $description, int $reversesEntryId): self
    {
        $reversal = new self(
            $date,
            $description,
            $this->sourceType,
            $this->sourceId,
            JournalEntryType::Reversal,
            $reversesEntryId,
        );

        foreach ($this->lines as $line) {
            $reversal->addLine(
                $line->side->opposite(),
                $line->account,
                $line->amount,
                $line->securitiesAccountId,
                $line->stockId,
                $line->memo,
            );
        }

        return $reversal;
    }
}
