<?php

namespace Config;

use App\Models\AccountingPeriodModel;
use App\Models\AccountModel;
use App\Models\AuditLogModel;
use App\Models\CashTransactionModel;
use App\Models\DividendTransactionModel;
use App\Models\JournalEntryModel;
use App\Models\JournalLineModel;
use App\Models\OpeningBalanceModel;
use App\Models\MarketPriceModel;
use App\Models\SecuritiesAccountModel;
use App\Models\SecurityModel;
use App\Models\StockModel;
use App\Models\StockPositionModel;
use App\Models\StockTransactionModel;
use App\Repositories\PositionSnapshotRepository;
use App\Services\Accounting\AccountingPeriodService;
use App\Services\Accounting\AuditLogger;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Accounting\DocumentNumberService;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\OpeningBalanceService;
use App\Services\MasterData\SecurityService;
use App\Services\MasterData\StockImportService;
use App\Services\MasterData\StockService;
use App\Libraries\XlsxReader;
use App\Services\Portfolio\MarketPriceImportService;
use App\Services\Portfolio\MarketPriceService;
use App\Services\Portfolio\PortfolioService;
use App\Services\Portfolio\PositionService;
use App\Services\Reporting\FinancialStatementService;
use App\Services\Reporting\InvestmentReportService;
use App\Services\Reporting\PeriodicReportService;
use App\Services\Transaction\CashTransactionService;
use App\Services\Transaction\DividendTransactionService;
use App\Services\Transaction\ReversalService;
use App\Services\Transaction\StampDutyService;
use App\Services\Transaction\StockTransactionService;
use App\Services\Transaction\TradingFeeCalculator;
use App\Services\GoogleAuthService;
use App\Services\UserAccountService;
use CodeIgniter\Shield\Models\UserModel;
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

    public static function stockImport(bool $getShared = true): StockImportService
    {
        if ($getShared) {
            return static::getSharedInstance('stockImport');
        }

        return new StockImportService(new StockModel(), static::auditLogger());
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

    public static function googleAuth(bool $getShared = true): GoogleAuthService
    {
        if ($getShared) {
            return static::getSharedInstance('googleAuth');
        }

        return new GoogleAuthService(
            config(\Config\GoogleAuth::class),
            new UserModel(),
            static::auditLogger(),
        );
    }

    public static function userAccounts(bool $getShared = true): UserAccountService
    {
        if ($getShared) {
            return static::getSharedInstance('userAccounts');
        }

        return new UserAccountService(new UserModel(), static::auditLogger());
    }

    public static function documentNumber(bool $getShared = true): DocumentNumberService
    {
        if ($getShared) {
            return static::getSharedInstance('documentNumber');
        }

        return new DocumentNumberService();
    }

    public static function auditLogger(bool $getShared = true): AuditLogger
    {
        if ($getShared) {
            return static::getSharedInstance('auditLogger');
        }

        return new AuditLogger(new AuditLogModel());
    }

    /**
     * Satu-satunya pintu masuk ke buku besar (§8).
     */
    public static function journalPoster(bool $getShared = true): JournalPoster
    {
        if ($getShared) {
            return static::getSharedInstance('journalPoster');
        }

        return new JournalPoster(
            new JournalEntryModel(),
            new JournalLineModel(),
            new AccountModel(),
            new AccountingPeriodModel(),
            static::accountingPeriod(),
            static::documentNumber(),
        );
    }

    public static function openingBalance(bool $getShared = true): OpeningBalanceService
    {
        if ($getShared) {
            return static::getSharedInstance('openingBalance');
        }

        return new OpeningBalanceService(
            new OpeningBalanceModel(),
            new StockPositionModel(),
            new SecuritiesAccountModel(),
            new StockModel(),
            static::journalPoster(),
            static::auditLogger(),
        );
    }

    public static function positions(bool $getShared = true): PositionService
    {
        if ($getShared) {
            return static::getSharedInstance('positions');
        }

        return new PositionService(new StockPositionModel(), new StockTransactionModel());
    }

    public static function marketPrices(bool $getShared = true): MarketPriceService
    {
        if ($getShared) {
            return static::getSharedInstance('marketPrices');
        }

        return new MarketPriceService(new MarketPriceModel(), new StockModel(), static::auditLogger());
    }

    public static function marketPriceImport(bool $getShared = true): MarketPriceImportService
    {
        if ($getShared) {
            return static::getSharedInstance('marketPriceImport');
        }

        return new MarketPriceImportService(
            new MarketPriceModel(),
            new StockModel(),
            new StockPositionModel(),
            static::auditLogger(),
            new XlsxReader(),
        );
    }

    public static function portfolio(bool $getShared = true): PortfolioService
    {
        if ($getShared) {
            return static::getSharedInstance('portfolio');
        }

        return new PortfolioService(
            new StockPositionModel(),
            new MarketPriceModel(),
            new JournalLineModel(),
            new AccountModel(),
            new SecuritiesAccountModel(),
            new PositionSnapshotRepository(new AccountModel()),
        );
    }

    public static function financialStatements(bool $getShared = true): FinancialStatementService
    {
        if ($getShared) {
            return static::getSharedInstance('financialStatements');
        }

        return new FinancialStatementService(new JournalLineModel(), new AccountModel());
    }

    public static function periodicReports(bool $getShared = true): PeriodicReportService
    {
        if ($getShared) {
            return static::getSharedInstance('periodicReports');
        }

        return new PeriodicReportService(static::portfolio(), new AccountModel());
    }

    public static function investmentReports(bool $getShared = true): InvestmentReportService
    {
        if ($getShared) {
            return static::getSharedInstance('investmentReports');
        }

        return new InvestmentReportService();
    }

    public static function cashTransactions(bool $getShared = true): CashTransactionService
    {
        if ($getShared) {
            return static::getSharedInstance('cashTransactions');
        }

        return new CashTransactionService(
            new CashTransactionModel(),
            new SecuritiesAccountModel(),
            static::journalPoster(),
            static::documentNumber(),
            static::auditLogger(),
        );
    }

    public static function tradingFees(bool $getShared = true): TradingFeeCalculator
    {
        if ($getShared) {
            return static::getSharedInstance('tradingFees');
        }

        return new TradingFeeCalculator(config(\Config\Investment::class));
    }

    public static function stampDuty(bool $getShared = true): StampDutyService
    {
        if ($getShared) {
            return static::getSharedInstance('stampDuty');
        }

        return new StampDutyService(
            new CashTransactionModel(),
            static::journalPoster(),
            static::documentNumber(),
            static::auditLogger(),
            config(\Config\Investment::class),
        );
    }

    public static function stockTransactions(bool $getShared = true): StockTransactionService
    {
        if ($getShared) {
            return static::getSharedInstance('stockTransactions');
        }

        return new StockTransactionService(
            new StockTransactionModel(),
            new SecuritiesAccountModel(),
            new SecurityModel(),
            new StockModel(),
            static::tradingFees(),
            static::positions(),
            static::journalPoster(),
            static::documentNumber(),
            static::auditLogger(),
            static::stampDuty(),
        );
    }

    public static function dividendTransactions(bool $getShared = true): DividendTransactionService
    {
        if ($getShared) {
            return static::getSharedInstance('dividendTransactions');
        }

        return new DividendTransactionService(
            new DividendTransactionModel(),
            new SecuritiesAccountModel(),
            new StockModel(),
            static::positions(),
            static::journalPoster(),
            static::documentNumber(),
            static::auditLogger(),
        );
    }

    public static function reversals(bool $getShared = true): ReversalService
    {
        if ($getShared) {
            return static::getSharedInstance('reversals');
        }

        return new ReversalService(
            new CashTransactionModel(),
            new StockTransactionModel(),
            new DividendTransactionModel(),
            static::positions(),
            static::journalPoster(),
            static::auditLogger(),
            static::stampDuty(),
        );
    }
}
