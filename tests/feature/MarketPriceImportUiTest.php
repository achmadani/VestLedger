<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\MarketPrices;
use App\Models\MarketPriceModel;
use App\Models\SecuritiesAccountModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\App;
use Tests\Support\Concerns\TruncatesDomainTables;
use Tests\Support\Http\FakeFileCollection;
use Tests\Support\Http\FakeUploadedFile;
use ZipArchive;

/**
 * Halaman impor harga pasar dari XLSX IDX (§14).
 *
 * @internal
 */
final class MarketPriceImportUiTest extends CIUnitTestCase
{
    use AuthenticationTesting;
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use TruncatesDomainTables;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $accountId;
    private int $stockId;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->truncateDomainTables();
        \Config\Services::reset(true);

        service('chartOfAccounts')->ensureSystemAccounts();
        service('accountingPeriod')->generateYear((int) date('Y'));

        $ajaib           = service('securityService')->create(['code' => 'AJAIB', 'name' => 'Ajaib'], ['label' => 'RDN']);
        $this->accountId = (new SecuritiesAccountModel())->forSecurities($ajaib->id)[0]->id;
        $this->stockId   = service('stockService')->create(['ticker' => 'BBCA', 'company_name' => 'BCA'])->id;

        service('cashTransactions')->topUp([
            'transaction_date' => date('Y') . '-01-02', 'securities_account_id' => $this->accountId, 'amount' => 100_000_000,
        ]);
        service('stockTransactions')->buy([
            'transaction_date' => date('Y') . '-01-05', 'securities_account_id' => $this->accountId,
            'stock_id' => $this->stockId, 'quantity' => 10_000, 'price' => 8_000,
            'broker_fee' => 0, 'tax' => 0, 'levy' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        $this->tempFiles = [];

        parent::tearDown();
    }

    private function makeUser(string $group): User
    {
        $users = new UserModel();
        $user  = new User([
            'username' => $group . '.' . bin2hex(random_bytes(3)),
            'email'    => bin2hex(random_bytes(5)) . '@vestledger.test',
            'password' => 'kata-sandi-uji-yang-panjang',
        ]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->addGroup($group);

        return $user;
    }

    private function makeXlsx(string $ticker = 'BBCA', string $closing = '9725'): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1"><c r="B1" t="str"><v>Kode Saham</v></c>'
            . '<c r="G1" t="str"><v>Tanggal Perdagangan Terakhir</v></c>'
            . '<c r="K1" t="str"><v>Penutupan</v></c></row>'
            . '<row r="2"><c r="B2" t="str"><v>' . $ticker . '</v></c>'
            . '<c r="K2"><v>' . $closing . '</v></c></row>'
            . '</sheetData></worksheet>';

        $path = tempnam(sys_get_temp_dir(), 'idxui') . '.xlsx';

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="S" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();

        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * Menjalankan controller impor dengan berkas unggahan tiruan.
     *
     * Tidak lewat $this->post(): FeatureTestTrait membangun IncomingRequest-nya
     * sendiri di dalam call(), sehingga berkas mustahil disuntikkan dari luar.
     * Controller tetap dijalankan apa adanya, termasuk pemeriksaan ekstensi dan
     * penghapusan berkas — hanya lapisan HTTP-nya yang dilewati.
     */
    private function upload(User $user, string $path, string $name = 'Closing-20260819.xlsx', array $post = []): RedirectResponse
    {
        $this->actingAs($user);

        $file    = new FakeUploadedFile($path, $name, 'application/octet-stream', null, UPLOAD_ERR_OK);
        $request = service('incomingrequest', config(App::class), false);

        FakeFileCollection::attach($request, ['prices' => $file]);
        $request->setGlobal('post', $post + ['price_date' => date('Y-m-d')]);

        $controller = new MarketPrices();
        $controller->initController($request, service('response'), service('logger'));

        return $controller->import();
    }

    public function testImportPageRendersForOwner(): void
    {
        $result = $this->actingAs($this->makeUser('owner'))->get('market-prices/import');

        $result->assertOK();
        $result->assertSee('Impor Harga Pasar');
        $result->assertSee('Kode Saham');
        $result->assertSee('Penutupan');
    }

    /**
     * Halaman harga pasar harus menawarkan jalan menuju impor; tanpa tautan itu
     * fiturnya ada tetapi tidak pernah ditemukan.
     */
    public function testMarketPricePageLinksToImport(): void
    {
        $result = $this->actingAs($this->makeUser('owner'))->get('market-prices');

        $result->assertOK();
        $result->assertSee('market-prices/import');
    }

    /**
     * Impor mengubah harga, dan harga itu harus benar-benar menghilangkan
     * peringatan "belum memiliki harga pasar" di halaman portofolio.
     */
    public function testUploadStoresPriceAndClearsUnpricedWarning(): void
    {
        $owner = $this->makeUser('owner');

        $this->upload($owner, $this->makeXlsx('BBCA', '9000'));

        $this->assertSame(1, (new MarketPriceModel())->countAllResults());
        $this->assertSame('9000.0000', (new MarketPriceModel())->findForDate($this->stockId, date('Y-m-d'))?->closingPrice()->toDecimalString());

        // Inilah yang menghilangkan peringatan "belum memiliki harga pasar" di
        // dasbor dan halaman portofolio; tampilannya sendiri sudah diuji di
        // PortfolioUiTest, yang tidak perlu diulang di sini.
        $this->assertSame(0, service('portfolio')->snapshot()['totals']['unpriced_count']);
    }

    /**
     * Berkas unggahan tidak boleh tertinggal di server setelah dibaca.
     */
    public function testUploadedFileIsDeletedAfterImport(): void
    {
        $path = $this->makeXlsx();

        $this->upload($this->makeUser('owner'), $path);

        $this->assertFileDoesNotExist($path);
    }

    public function testNonXlsxUploadIsRejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bukan') . '.csv';
        file_put_contents($path, "BBCA,9000\n");
        $this->tempFiles[] = $path;

        $response = $this->upload($this->makeUser('owner'), $path, 'harga.csv');

        $this->assertSame(0, (new MarketPriceModel())->countAllResults());
        $this->assertStringContainsString('market-prices/import', (string) $response->getHeaderLine('Location'));
    }

    /**
     * Impor mengubah data, jadi ia harus tunduk pada izin price.manage — bukan
     * sekadar izin melihat portofolio.
     */
    public function testViewerCannotReachImport(): void
    {
        $viewer = $this->makeUser('viewer');

        $this->actingAs($viewer)->get('market-prices/import')->assertRedirect();
    }
}
