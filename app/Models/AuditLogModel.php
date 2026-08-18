<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Audit trail (§26). Tabel ini hanya pernah ditambah.
 */
class AuditLogModel extends Model
{
    protected $table          = 'audit_logs';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useTimestamps  = true;
    protected $updatedField   = '';
    protected $allowedFields  = [
        'user_id', 'action', 'entity_type', 'entity_id', 'summary',
        'old_values', 'new_values', 'ip_address', 'user_agent',
    ];

    public function withUser(): self
    {
        return $this->select('audit_logs.*, users.username')
            ->join('users', 'users.id = audit_logs.user_id', 'left')
            ->orderBy('audit_logs.id', 'desc');
    }
}
