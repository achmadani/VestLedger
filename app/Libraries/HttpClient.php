<?php

declare(strict_types=1);

namespace App\Libraries;

use RuntimeException;

/**
 * Klien HTTPS kecil dengan beberapa jalur keluar.
 *
 * CodeIgniter memakai ekstensi cURL untuk seluruh permintaan keluar, dan hosting
 * produksi aplikasi ini **memblokir `curl_exec`** — akibatnya login Google
 * berhenti pada "Tidak dapat menghubungi server Google" tanpa petunjuk apa pun.
 * Hosting yang sama juga tidak memiliki ext-zip dan tidak menyediakan shell,
 * sehingga bertumpu pada satu jalur saja terbukti tidak bijak.
 *
 * Tiga transport dicoba berurutan, dari yang paling lazim ke yang paling tidak
 * mungkin diblokir:
 *
 *   1. cURL          — dipakai bila ekstensinya ada DAN curl_exec tidak dimatikan
 *   2. stream wrapper — file_get_contents(), butuh allow_url_fopen
 *   3. socket TLS     — stream_socket_client(), tidak butuh keduanya
 *
 * Yang dibutuhkan hanya permintaan kecil ke titik akhir OAuth: GET dan POST
 * form-encoded, balasan JSON. Ini bukan klien HTTP serba bisa, dan tidak perlu.
 */
class HttpClient
{
    public const TRANSPORT_CURL   = 'curl';
    public const TRANSPORT_STREAM = 'stream';
    public const TRANSPORT_SOCKET = 'socket';

    /**
     * @param list<string>|null $only batasi transport; null berarti coba semuanya
     */
    public function __construct(
        private int $timeout = 15,
        private ?array $only = null,
    ) {
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, string> $headers
     *
     * @return array{status:int, body:string}
     */
    public function postForm(string $url, array $fields, array $headers = []): array
    {
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';

        return $this->send('POST', $url, http_build_query($fields), $headers);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status:int, body:string}
     */
    public function get(string $url, array $headers = []): array
    {
        return $this->send('GET', $url, null, $headers);
    }

    /**
     * Transport yang benar-benar dapat dipakai di server ini.
     *
     * Dipakai untuk menyusun pesan kesalahan yang menyebut sebabnya, bukan
     * sekadar "gagal menghubungi" — di server tanpa shell, pesan itulah
     * satu-satunya alat diagnosis yang dimiliki pengguna.
     *
     * @return array<string, bool>
     */
    public function availability(): array
    {
        return [
            self::TRANSPORT_CURL   => $this->curlUsable(),
            self::TRANSPORT_STREAM => $this->streamUsable(),
            self::TRANSPORT_SOCKET => $this->socketUsable(),
        ];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status:int, body:string}
     */
    private function send(string $method, string $url, ?string $body, array $headers): array
    {
        $attempts = [];

        foreach ($this->transports() as $transport) {
            try {
                return match ($transport) {
                    self::TRANSPORT_CURL   => $this->sendWithCurl($method, $url, $body, $headers),
                    self::TRANSPORT_STREAM => $this->sendWithStream($method, $url, $body, $headers),
                    self::TRANSPORT_SOCKET => $this->sendWithSocket($method, $url, $body, $headers),
                };
            } catch (RuntimeException $e) {
                // Transport berikutnya dicoba: kegagalan satu jalur belum tentu
                // berarti jaringannya yang bermasalah.
                $attempts[] = $transport . ': ' . $e->getMessage();
            }
        }

        throw new RuntimeException(
            $attempts === []
                ? 'Tidak ada transport HTTP yang tersedia di server ini.'
                : implode('; ', $attempts)
        );
    }

    /**
     * @return list<string>
     */
    private function transports(): array
    {
        $available = array_keys(array_filter($this->availability()));

        if ($this->only === null) {
            return $available;
        }

        return array_values(array_intersect($this->only, $available));
    }

    private function curlUsable(): bool
    {
        return function_exists('curl_init') && function_exists('curl_exec');
    }

    private function streamUsable(): bool
    {
        return filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)
            && in_array('https', stream_get_wrappers(), true)
            && function_exists('file_get_contents');
    }

    private function socketUsable(): bool
    {
        return function_exists('stream_socket_client')
            && in_array('ssl', stream_get_transports(), true);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status:int, body:string}
     */
    private function sendWithCurl(string $method, string $url, ?string $body, array $headers): array
    {
        $handle = curl_init($url);

        if ($handle === false) {
            throw new RuntimeException('curl_init gagal');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $this->headerLines($headers),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($handle);
        $status   = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error    = curl_error($handle);
        curl_close($handle);

        if ($response === false) {
            throw new RuntimeException($error !== '' ? $error : 'permintaan gagal');
        }

        return ['status' => $status, 'body' => (string) $response];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status:int, body:string}
     */
    private function sendWithStream(string $method, string $url, ?string $body, array $headers): array
    {
        $context = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $this->headerLines($headers)),
                'content'       => $body ?? '',
                'timeout'       => $this->timeout,
                // Balasan 4xx/5xx tetap dibaca isinya, bukan dijadikan warning
                // tanpa badan pesan — isi JSON-nya justru yang menjelaskan sebab.
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new RuntimeException('file_get_contents gagal');
        }

        // $http_response_header diisi oleh PHP di scope pemanggil.
        $status = 0;

        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => $response];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status:int, body:string}
     */
    private function sendWithSocket(string $method, string $url, ?string $body, array $headers): array
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            throw new RuntimeException('URL tidak sah');
        }

        $secure = ($parts['scheme'] ?? 'https') === 'https';
        $port   = $parts['port'] ?? ($secure ? 443 : 80);
        $path   = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        $socket = @stream_socket_client(
            ($secure ? 'ssl://' : 'tcp://') . $parts['host'] . ':' . $port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]),
        );

        if ($socket === false) {
            throw new RuntimeException($errstr !== '' ? $errstr : 'koneksi gagal');
        }

        try {
            stream_set_timeout($socket, $this->timeout);

            $headers['Host']       = $parts['host'];
            $headers['Connection'] = 'close';

            if ($body !== null) {
                $headers['Content-Length'] = (string) strlen($body);
            }

            $request = $method . ' ' . $path . " HTTP/1.1\r\n"
                . implode("\r\n", $this->headerLines($headers)) . "\r\n\r\n"
                . ($body ?? '');

            if (@fwrite($socket, $request) === false) {
                throw new RuntimeException('gagal mengirim permintaan');
            }

            $raw = '';

            while (! feof($socket)) {
                $chunk = fread($socket, 8192);

                if ($chunk === false) {
                    break;
                }

                $raw .= $chunk;
            }
        } finally {
            fclose($socket);
        }

        return $this->parseRawResponse($raw);
    }

    /**
     * @return array{status:int, body:string}
     */
    private function parseRawResponse(string $raw): array
    {
        $split = explode("\r\n\r\n", $raw, 2);

        if (count($split) < 2) {
            throw new RuntimeException('balasan tidak lengkap');
        }

        [$head, $body] = $split;
        $status        = 0;

        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $head, $m) === 1) {
            $status = (int) $m[1];
        }

        if (stripos($head, "\r\nTransfer-Encoding: chunked") !== false) {
            $body = $this->decodeChunked($body);
        }

        return ['status' => $status, 'body' => $body];
    }

    /**
     * Menggabungkan kembali badan pesan ber-Transfer-Encoding: chunked.
     */
    private function decodeChunked(string $body): string
    {
        $decoded = '';

        while ($body !== '') {
            $lineEnd = strpos($body, "\r\n");

            if ($lineEnd === false) {
                break;
            }

            $size = (int) hexdec(trim(substr($body, 0, $lineEnd)));

            if ($size === 0) {
                break;
            }

            $decoded .= substr($body, $lineEnd + 2, $size);
            $body = substr($body, $lineEnd + 2 + $size + 2);
        }

        return $decoded;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return list<string>
     */
    private function headerLines(array $headers): array
    {
        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return $lines;
    }
}
