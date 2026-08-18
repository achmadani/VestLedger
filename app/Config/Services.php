<?php

namespace Config;

use App\Models\AccountingPeriodModel;
use App\Models\AccountModel;
use App\Models\SecuritiesAccountModel;
use App\Models\SecurityModel;
use App\Models\StockModel;
use App\Services\Accounting\AccountingPeriodService;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\MasterData\SecurityService;
use App\Services\MasterData\StockService;
use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Service layer VestLedger didaftarkan di sini agar controller tidak perlu
 * merakit dependency-nya sendiri (§29). Semua factory memakai $getShared
 * sehingga satu request hanya membuat satu instance.
 */
class Services extends BaseService
{
    public static function securityService(bool $getShared = true): SecurityService
    {
        if ($getShared) {
            return static::getSharedInstance('securityService');
        }

        return new SecurityService(new SecurityModel(), new SecuritiesAccountModel());
    }

    public static function stockService(bool $getShared = true): StockService
    {
        if ($getShared) {
            return static::getSharedInstance('stockService');
        }

        return new StockService(new StockModel());
    }

    public static function chartOfAccounts(bool $getShared = true): ChartOfAccountsService
    {
        if ($getShared) {
            return static::getSharedInstance('chartOfAccounts');
        }

        return new ChartOfAccountsService(new AccountModel());
    }

    public static function accountingPeriod(bool $getShared = true): AccountingPeriodService
    {
        if ($getShared) {
            return static::getSharedInstance('accountingPeriod');
        }

        return new AccountingPeriodService(new AccountingPeriodModel());
    }
}
