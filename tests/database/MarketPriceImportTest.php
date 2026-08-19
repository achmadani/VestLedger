<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Exceptions\BusinessRuleException;
use App\Models\MarketPriceModel;
use Tests\Support\Engine\EngineTestCase;
use ZipArchive;

/**
 * Impor harga penutupan dari XLSX ringkasan perdagangan IDX (§14).
 *
 * Berkas uji dibuat di sini, bukan diambil dari docs/: berkas IDX sungguhan
 * berukuran ratusan kilobyte dan sengaja tidak di-commit (lihat .gitignore),
 * sehingga test yang bergantung padanya akan gagal di mesin lain.
 *
 * @internal
 */
final class MarketPriceImportTest extends EngineTestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

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

    /**
     * Membuat XLSX bergaya IDX: baris 1 header, kolom B kode, K penutupan.
     *
     * @param list<array{0:string, 1:string}> $rows      [ticker, penutupan]
     * @param array<string, string>           $overrides header pengganti per kolom
     */
    private function makeXlsx(array $rows, string $tradeDate = '19 Agt 2026', array $overrides = []): string
    {
        $header = [
            'A' => 'No', 'B' => 'Kode Saham', 'C' => 'Nama Perusahaan', 'E' => 'Sebelumnya',
            'G' => 'Tanggal Perdagangan Terakhir', 'K' => 'Penutupan', 'L' => 'Selisih',
        ] + $overrides;

        foreach ($overrides as $column => $value) {
            $header[$column] = $value;
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData><row r="1">';

        foreach ($header as $column => $label) {
            $xml .= sprintf('<c r="%s1" t="str"><v>%s</v></c>', $column, htmlspecialchars($label, ENT_XML1));
        }

        $xml .= '</row>';

        $number = 2;

        foreach ($rows as [$ticker, $closing]) {
            $xml .= sprintf('<row r="%d">', $number)
                . sprintf('<c r="A%d"><v>%d</v></c>', $number, $number - 1)
                . sprintf('<c r="B%d" t="str"><v>%s</v></c>', $number, htmlspecialchars($ticker, ENT_XML1))
                . sprintf('<c r="C%d" t="str"><v>Emiten %s</v></c>', $number, htmlspecialchars($ticker, ENT_XML1))
                . sprintf('<c r="G%d" t="str"><v>%s</v></c>', $number, htmlspecialchars($tradeDate, ENT_XML1));

            // Sel kosong memang tidak ditulis sama sekali oleh Excel.
            if ($closing !== '') {
                $xml .= sprintf('<c r="K%d"><v>%s</v></c>', $number, $closing);
            }

            $xml .= '</row>';
            $number++;
        }

        $xml .= '</sheetData></worksheet>';

        $path = tempnam(sys_get_temp_dir(), 'idx') . '.xlsx';

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();

        $this->tempFiles[] = $path;

        return $path;
    }

    private function price(int $stockId, string $date = '2026-08-19'): ?string
    {
        return (new MarketPriceModel())->findForDate($stockId, $date)?->closingPrice()->toDecimalString();
    }

    private function buyBbca(): void
    {
        service('cashTransactions')->topUp([
            'transaction_date' => '2026-01-02', 'securities_account_id' => $this->ajaib, 'amount' => 500_000_000,
        ]);
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 1_000, 'price' => 8_000,
            'broker_fee' => 0, 'tax' => 0, 'levy' => 0,
        ]);
    }

    public function testImportsClosingPriceForHeldStock(): void
    {
        $this->buyBbca();

        $file   = $this->makeXlsx([['BBCA', '9725'], ['AADI', '1234']]);
        $result = service('marketPriceImport')->importFile($file, '2026-08-19');

        $this->assertSame(1, $result['saved']);
        $this->assertSame('9725.0000', $this->price($this->bbca));

        // AADI tidak dimiliki dan tidak ada di master data test — tidak ikut tersimpan.
        $this->assertSame(1, $result['unknown']);
        $this->assertSame(1, $this->db->table('market_prices')->countAllResults());
    }

    /**
     * Harga nol adalah saham disuspensi, BUKAN harga. Menyimpannya membuat nilai
     * pasar anjlok ke nol, dan CHECK constraint tabel pun menolaknya.
     */
    public function testZeroAndEmptyPricesAreSkippedNotStored(): void
    {
        $this->buyBbca();

        $file   = $this->makeXlsx([['BBCA', '0'], ['BBRI', '']]);
        $result = service('marketPriceImport')->importFile($file, '2026-08-19', false);

        $this->assertSame(0, $result['saved']);
        $this->assertSame(2, $result['skipped']);
        $this->assertNull($this->price($this->bbca));
        $this->assertSame(0, $this->db->table('market_prices')->countAllResults());
    }

    public function testReimportOverwritesInsteadOfDuplicating(): void
    {
        $this->buyBbca();

        service('marketPriceImport')->importFile($this->makeXlsx([['BBCA', '9000']]), '2026-08-19');
        $result = service('marketPriceImport')->importFile($this->makeXlsx([['BBCA', '9500']]), '2026-08-19');

        $this->assertSame(0, $result['saved']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame('9500.0000', $this->price($this->bbca));
        $this->assertSame(1, $this->db->table('market_prices')->countAllResults());
    }

    /**
     * Mengunggah berkas yang sama dua kali tidak boleh tampak seperti perubahan.
     */
    public function testUnchangedPricesAreNotCountedAsUpdates(): void
    {
        $this->buyBbca();

        service('marketPriceImport')->importFile($this->makeXlsx([['BBCA', '9000']]), '2026-08-19');
        $result = service('marketPriceImport')->importFile($this->makeXlsx([['BBCA', '9000']]), '2026-08-19');

        $this->assertSame(0, $result['saved']);
        $this->assertSame(0, $result['updated']);
    }

    /**
     * Berkas IDX yang keliru (mis. daftar emiten) punya susunan kolom lain, dan
     * kolom K-nya berisi hal yang sama sekali bukan harga.
     */
    public function testWrongFileLayoutIsRejectedBeforeAnythingIsStored(): void
    {
        $this->buyBbca();

        $file = $this->makeXlsx([['BBCA', '9725']], '19 Agt 2026', ['K' => 'Listed Shares']);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/Susunan kolom tidak dikenali/');

        try {
            service('marketPriceImport')->importFile($file, '2026-08-19');
        } finally {
            $this->assertSame(0, $this->db->table('market_prices')->countAllResults());
        }
    }

    public function testFutureDateIsRejected(): void
    {
        $this->buyBbca();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/masa depan/');

        service('marketPriceImport')->importFile(
            $this->makeXlsx([['BBCA', '9725']]),
            date('Y-m-d', strtotime('+1 day')),
        );
    }

    /**
     * Tanggal di dalam berkas dilaporkan apa adanya supaya pengguna tahu bila ia
     * mengunggah berkas kemarin; harga tetap disimpan pada tanggal pilihannya.
     */
    public function testTradingDateInsideFileIsReported(): void
    {
        $this->buyBbca();

        $result = service('marketPriceImport')->importFile(
            $this->makeXlsx([['BBCA', '9725']], '18 Agt 2026'),
            '2026-08-19',
        );

        $this->assertSame('2026-08-18', $result['fileDate']);
        $this->assertSame('2026-08-19', $result['date']);
        $this->assertSame('9725.0000', $this->price($this->bbca));
    }

    public function testHeldOnlyScopeIgnoresStocksWithoutPosition(): void
    {
        $this->buyBbca(); // hanya BBCA yang dimiliki

        $file   = $this->makeXlsx([['BBCA', '9000'], ['BBRI', '4500']]);
        $result = service('marketPriceImport')->importFile($file, '2026-08-19', true);

        $this->assertSame(1, $result['saved']);
        $this->assertSame('9000.0000', $this->price($this->bbca));
        $this->assertNull($this->price($this->bbri));
    }

    public function testAllScopeStoresEveryKnownStock(): void
    {
        $this->buyBbca();

        $file   = $this->makeXlsx([['BBCA', '9000'], ['BBRI', '4500']]);
        $result = service('marketPriceImport')->importFile($file, '2026-08-19', false);

        $this->assertSame(2, $result['saved']);
        $this->assertSame('4500.0000', $this->price($this->bbri));
    }

    /**
     * Harga hasil impor harus benar-benar dipakai laporan, bukan sekadar
     * tersimpan — inilah yang menghilangkan peringatan di dasbor.
     */
    public function testImportedPriceFeedsPortfolioValuation(): void
    {
        $this->buyBbca();

        service('marketPriceImport')->importFile($this->makeXlsx([['BBCA', '9000']]), '2026-08-19');

        $snapshot = service('portfolio')->snapshot('2026-08-19');
        $position = $snapshot['positions'][0];

        $this->assertSame('9000.0000', $position['market_price']->toDecimalString());
        $this->assertMoneyEquals('9000000.00', $position['market_value']);
        $this->assertMoneyEquals('1000000.00', $position['unrealized']);
    }
}
