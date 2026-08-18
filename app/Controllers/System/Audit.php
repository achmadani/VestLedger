<?php

declare(strict_types=1);

namespace App\Controllers\System;

use App\Controllers\BaseController;
use App\Models\AuditLogModel;

/**
 * Audit trail (§26).
 */
class Audit extends BaseController
{
    public function index(): string
    {
        $logs    = new AuditLogModel();
        $action  = trim((string) $this->request->getGet('action'));
        $entity  = trim((string) $this->request->getGet('entity_type'));
        $builder = $logs->withUser();

        if ($action !== '') {
            $builder->where('audit_logs.action', $action);
        }

        if ($entity !== '') {
            $builder->where('audit_logs.entity_type', $entity);
        }

        $perPage = config(\Config\Pager::class)->perPage;

        return view('system/audit/index', [
            'pageTitle' => 'Audit Trail',
            'logs'      => $builder->paginate($perPage),
            'pager'     => $logs->pager,
            'filters'   => ['action' => $action, 'entity_type' => $entity],
        ]);
    }
}
