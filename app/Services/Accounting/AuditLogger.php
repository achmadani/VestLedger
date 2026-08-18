<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\AuditLogModel;

/**
 * Pencatat audit trail (§26).
 *
 * Mencatat siapa melakukan apa, kapan, terhadap entitas mana, dengan nilai lama
 * dan nilai baru. Dipanggil dari service — bukan dari controller — supaya
 * perubahan yang dilakukan lewat CLI maupun lewat UI sama-sama tercatat.
 */
class AuditLogger
{
    public function __construct(private AuditLogModel $logs)
    {
    }

    /**
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     */
    public function record(
        string $action,
        string $entityType,
        ?int $entityId,
        ?string $summary = null,
        ?array $old = null,
        ?array $new = null,
    ): void {
        $request = service('request');

        $this->logs->insert([
            'user_id'     => auth()->id(),
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'summary'     => $summary,
            'old_values'  => $old === null ? null : json_encode($this->scrub($old), JSON_UNESCAPED_UNICODE),
            'new_values'  => $new === null ? null : json_encode($this->scrub($new), JSON_UNESCAPED_UNICODE),
            'ip_address'  => method_exists($request, 'getIPAddress') ? $request->getIPAddress() : null,
            'user_agent'  => $this->userAgent(),
        ]);
    }

    private function userAgent(): ?string
    {
        $request = service('request');

        if (! method_exists($request, 'getUserAgent')) {
            return null;
        }

        $agent = (string) $request->getUserAgent();

        return $agent === '' ? null : substr($agent, 0, 255);
    }

    /**
     * Membuang field sensitif dari jejak audit.
     *
     * Audit trail dibaca lebih longgar daripada data aslinya, jadi nomor
     * rekening dan kredensial tidak boleh ikut tersalin ke sana (§36).
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function scrub(array $values): array
    {
        $sensitive = ['password', 'password_hash', 'secret', 'token', 'account_number'];

        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitive, true)) {
                $values[$key] = '[disamarkan]';
            }
        }

        return $values;
    }
}
