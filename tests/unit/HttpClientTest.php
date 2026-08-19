<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\HttpClient;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

/**
 * Klien HTTPS dengan beberapa jalur keluar (§31).
 *
 * Test ini ada karena hosting produksi **memblokir `curl_exec`**, dan seluruh
 * permintaan keluar CodeIgniter bertumpu pada ekstensi itu — login Google
 * berhenti dengan pesan yang tidak menjelaskan apa pun.
 *
 * Test yang benar-benar menghubungi Google ditandai @group network dan
 * dilewati bila jaringan tidak tersedia, supaya suite tetap dapat dijalankan
 * tanpa koneksi.
 *
 * @internal
 */
final class HttpClientTest extends CIUnitTestCase
{
    private function skipWithoutNetwork(): void
    {
        $probe = @stream_socket_client('ssl://oauth2.googleapis.com:443', $errno, $errstr, 5);

        if ($probe === false) {
            $this->markTestSkipped('Tidak ada koneksi keluar: ' . $errstr);
        }

        fclose($probe);
    }

    public function testReportsWhichTransportsAreUsable(): void
    {
        $available = (new HttpClient())->availability();

        $this->assertArrayHasKey(HttpClient::TRANSPORT_CURL, $available);
        $this->assertArrayHasKey(HttpClient::TRANSPORT_STREAM, $available);
        $this->assertArrayHasKey(HttpClient::TRANSPORT_SOCKET, $available);

        foreach ($available as $transport => $usable) {
            $this->assertIsBool($usable, $transport);
        }
    }

    /**
     * Transport socket tidak membutuhkan cURL maupun allow_url_fopen, sehingga
     * ia yang tersisa ketika hosting menutup keduanya.
     *
     * @group network
     */
    public function testSocketTransportWorksWithoutCurl(): void
    {
        $this->skipWithoutNetwork();

        $client = new HttpClient(15, [HttpClient::TRANSPORT_SOCKET]);

        // Endpoint publik tanpa kredensial; Google membalas 400 berisi JSON.
        $response = $client->get('https://oauth2.googleapis.com/tokeninfo?access_token=tidak-sah');

        $this->assertSame(400, $response['status']);
        $this->assertJson($response['body']);
        $this->assertArrayHasKey('error', (array) json_decode($response['body'], true));
    }

    /**
     * Ketiga transport harus menghasilkan hal yang sama; kalau tidak, cadangan
     * justru menjadi sumber perbedaan perilaku yang sulit dilacak.
     *
     * @group network
     */
    public function testAllTransportsReturnTheSameResult(): void
    {
        $this->skipWithoutNetwork();

        $results = [];

        foreach ((new HttpClient())->availability() as $transport => $usable) {
            if (! $usable) {
                continue;
            }

            $response = (new HttpClient(15, [$transport]))
                ->postForm('https://oauth2.googleapis.com/token', ['grant_type' => 'authorization_code', 'code' => 'x']);

            $results[$transport] = [
                $response['status'],
                (array) json_decode($response['body'], true)['error'] ?? null,
            ];
        }

        $this->assertNotEmpty($results, 'Tidak ada transport yang dapat dipakai.');
        $this->assertCount(1, array_unique(array_map('serialize', $results)), 'Transport memberi hasil berbeda.');
    }

    /**
     * Bila seluruh jalur ditutup, kesalahannya harus mengatakan demikian —
     * bukan diam atau melempar sesuatu yang tidak dapat ditafsirkan.
     */
    public function testFailsClearlyWhenNoTransportIsAllowed(): void
    {
        $client = new HttpClient(5, ['transport-yang-tidak-ada']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tidak ada transport HTTP yang tersedia');

        $client->get('https://oauth2.googleapis.com/tokeninfo');
    }

    /**
     * @group network
     */
    public function testPostFormSendsUrlEncodedBody(): void
    {
        $this->skipWithoutNetwork();

        $response = (new HttpClient(15, [HttpClient::TRANSPORT_SOCKET]))
            ->postForm('https://oauth2.googleapis.com/token', [
                'grant_type' => 'authorization_code',
                'code'       => 'kode-tidak-sah',
            ]);

        $body = (array) json_decode($response['body'], true);

        // Google memahami form-nya dan mengeluh soal isinya, bukan soal
        // bentuknya — bukti badan permintaan terkirim sebagai form-encoded.
        $this->assertSame(400, $response['status']);
        $this->assertArrayHasKey('error', $body);
    }
}
