<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\XlsxReader;
use App\Libraries\ZipFileReader;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;
use ZipArchive;

/**
 * Pembacaan XLSX tanpa ekstensi zip (§14).
 *
 * Hosting produksi TIDAK memiliki `ext-zip` — impor harga sempat gagal dengan
 * "Class ZipArchive not found". Karena itu jalur murni-PHP di ZipFileReader
 * bukan cadangan yang jarang terpakai, melainkan jalur yang sesungguhnya
 * dipakai di produksi, dan harus diuji sekeras jalur bawaan.
 *
 * @internal
 */
final class XlsxReaderTest extends CIUnitTestCase
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
     * @param array<string, string> $entries
     */
    private function makeZip(array $entries, int $method = ZipArchive::CM_STORE): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsxr') . '.xlsx';
        $zip  = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
            $zip->setCompressionName($name, $method);
        }

        $zip->close();
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @param list<array{0:string, 1:string}> $rows
     */
    private function makeXlsx(array $rows, int $method = ZipArchive::CM_STORE, string $sheet = 'xl/worksheets/sheet1.xml'): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1"><c r="B1" t="str"><v>Kode Saham</v></c><c r="K1" t="str"><v>Penutupan</v></c></row>';

        $number = 2;

        foreach ($rows as [$ticker, $closing]) {
            $xml .= sprintf('<row r="%d"><c r="B%d" t="str"><v>%s</v></c><c r="K%d"><v>%s</v></c></row>',
                $number, $number, $ticker, $number, $closing);
            $number++;
        }

        $xml .= '</sheetData></worksheet>';

        return $this->makeZip([
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="S" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="' . str_replace('xl/', '', $sheet) . '"/></Relationships>',
            $sheet => $xml,
        ], $method);
    }

    /**
     * @return list<array{0:XlsxReader, 1:string}>
     */
    private function readers(): array
    {
        return [
            [new XlsxReader(new ZipFileReader(true)), 'ext-zip'],
            [new XlsxReader(new ZipFileReader(false)), 'murni PHP'],
        ];
    }

    /**
     * Berkas ekspor IDX memakai metode Stored (tanpa kompresi).
     */
    public function testReadsStoredEntriesOnBothPaths(): void
    {
        $file = $this->makeXlsx([['BBCA', '9725'], ['BBRI', '4500']]);

        foreach ($this->readers() as [$reader, $label]) {
            $rows = iterator_to_array($reader->rows($file));

            $this->assertSame('Kode Saham', $rows[1]['B'], $label);
            $this->assertSame('BBCA', $rows[2]['B'], $label);
            $this->assertSame('9725', $rows[2]['K'], $label);
            $this->assertSame('4500', $rows[3]['K'], $label);
        }
    }

    /**
     * Berkas yang disimpan ulang lewat Excel memakai Deflate, yang di jalur
     * murni-PHP harus di-inflate sendiri.
     */
    public function testReadsDeflatedEntriesOnBothPaths(): void
    {
        $file = $this->makeXlsx([['BBCA', '9725']], ZipArchive::CM_DEFLATE);

        foreach ($this->readers() as [$reader, $label]) {
            $rows = iterator_to_array($reader->rows($file));

            $this->assertSame('BBCA', $rows[2]['B'], $label);
            $this->assertSame('9725', $rows[2]['K'], $label);
        }
    }

    /**
     * Nama sheet tidak selalu "sheet1.xml"; letaknya dibaca dari workbook rels.
     */
    public function testFindsSheetThroughWorkbookRelationship(): void
    {
        $file = $this->makeXlsx([['BBCA', '100']], ZipArchive::CM_STORE, 'xl/worksheets/lembar-utama.xml');

        foreach ($this->readers() as [$reader, $label]) {
            $rows = iterator_to_array($reader->rows($file));

            $this->assertSame('BBCA', $rows[2]['B'], $label);
        }
    }

    /**
     * Sel kosong tidak ditulis sama sekali oleh Excel, sehingga pembacaan harus
     * lewat huruf kolom — bukan posisi, yang akan bergeser.
     */
    public function testMissingCellsDoNotShiftColumns(): void
    {
        $xml = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1"><c r="B1" t="str"><v>Kode Saham</v></c><c r="K1" t="str"><v>Penutupan</v></c></row>'
            . '<row r="2"><c r="B2" t="str"><v>BBCA</v></c><c r="K2"><v>9725</v></c></row>'
            . '</sheetData></worksheet>';

        $file = $this->makeZip([
            'xl/workbook.xml'            => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="S" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml'   => $xml,
        ]);

        foreach ($this->readers() as [$reader, $label]) {
            $row = iterator_to_array($reader->rows($file))[2];

            $this->assertSame('9725', $row['K'], $label);
            $this->assertArrayNotHasKey('C', $row, $label);
        }
    }

    /**
     * Teks yang disimpan terpisah di sharedStrings.xml — bentuk yang dipakai
     * Excel, berbeda dari ekspor IDX yang menaruhnya di dalam sel.
     */
    public function testResolvesSharedStrings(): void
    {
        $xml = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1"><c r="B1" t="s"><v>0</v></c><c r="K1" t="s"><v>1</v></c></row>'
            . '<row r="2"><c r="B2" t="s"><v>2</v></c><c r="K2"><v>9725</v></c></row>'
            . '</sheetData></worksheet>';

        $file = $this->makeZip([
            'xl/workbook.xml'            => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="S" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/sharedStrings.xml'       => '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Kode Saham</t></si><si><t>Penutupan</t></si><si><t>BBCA</t></si></sst>',
            'xl/worksheets/sheet1.xml'   => $xml,
        ]);

        foreach ($this->readers() as [$reader, $label]) {
            $rows = iterator_to_array($reader->rows($file));

            $this->assertSame('Kode Saham', $rows[1]['B'], $label);
            $this->assertSame('BBCA', $rows[2]['B'], $label);
            $this->assertSame('9725', $rows[2]['K'], $label);
        }
    }

    public function testRejectsFileThatIsNotAZipArchive(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bukan') . '.xlsx';
        file_put_contents($path, 'ini jelas bukan berkas xlsx');
        $this->tempFiles[] = $path;

        $reader = new XlsxReader(new ZipFileReader(false));

        $this->expectException(RuntimeException::class);

        iterator_to_array($reader->rows($path));
    }
}
