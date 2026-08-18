<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Audit trail (§26).
 *
 * Mencatat siapa, kapan, melakukan apa, terhadap apa, dengan nilai lama dan baru.
 * Tabel ini hanya pernah ditambah — tidak pernah diubah maupun dihapus.
 */
class CreateAuditLogsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'      => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'action'  => [
                'type' => 'VARCHAR', 'constraint' => 50,
                'comment' => 'created, updated, reversed, closed, reopened, rebuilt, ...',
            ],
            'entity_type' => ['type' => 'VARCHAR', 'constraint' => 60],
            'entity_id'   => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'summary'     => [
                'type' => 'VARCHAR', 'constraint' => 255, 'null' => true,
                'comment' => 'Ringkasan berbahasa manusia, agar audit trail terbaca tanpa membedah JSON',
            ],
            'old_values' => ['type' => 'JSON', 'null' => true],
            'new_values' => ['type' => 'JSON', 'null' => true],
            'ip_address' => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'user_agent' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['entity_type', 'entity_id']);
        $this->forge->addKey(['created_at', 'id']);
        $this->forge->addKey('user_id');
        $this->forge->addKey('action');

        // SET NULL: menghapus user tidak boleh menghapus jejak auditnya.
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'SET NULL');

        $this->forge->createTable('audit_logs', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('audit_logs', true);
    }
}
