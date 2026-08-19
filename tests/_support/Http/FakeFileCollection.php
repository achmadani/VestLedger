<?php

declare(strict_types=1);

namespace Tests\Support\Http;

use CodeIgniter\HTTP\Files\FileCollection;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\IncomingRequest;
use ReflectionProperty;

/**
 * Kumpulan berkas unggahan tiruan untuk feature test.
 *
 * `IncomingRequest` membangun daftar berkas dari superglobal `$_FILES`, dan
 * tidak menyediakan cara menyuntikkannya. Dalam test, `$_FILES` tidak pernah
 * terisi karena tidak ada permintaan multipart yang sungguhan — sehingga jalur
 * unggah tidak dapat diuji tanpa tiruan ini.
 *
 * @see FakeUploadedFile untuk alasan `isValid()` juga perlu ditiru.
 */
final class FakeFileCollection extends FileCollection
{
    /**
     * Nama properti sengaja bukan $files: induknya sudah memakai nama itu untuk
     * daftar yang dibangun dari $_FILES.
     *
     * @param array<string, UploadedFile> $fakes
     */
    public function __construct(private array $fakes = [])
    {
        // FileCollection tidak punya konstruktor sendiri; tidak ada yang perlu
        // dipanggil ke atas.
    }

    /**
     * Memasang kumpulan berkas ini pada sebuah request.
     *
     * @param array<string, UploadedFile> $files
     */
    public static function attach(IncomingRequest $request, array $files): void
    {
        $property = new ReflectionProperty(IncomingRequest::class, 'files');
        $property->setAccessible(true);
        $property->setValue($request, new self($files));
    }

    public function all(): array
    {
        return $this->fakes;
    }

    public function getFile(string $name)
    {
        return $this->fakes[$name] ?? null;
    }

    public function hasFile(string $fileID): bool
    {
        return isset($this->fakes[$fileID]);
    }
}
