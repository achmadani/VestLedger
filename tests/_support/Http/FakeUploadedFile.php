<?php

declare(strict_types=1);

namespace Tests\Support\Http;

use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Berkas unggahan tiruan untuk feature test.
 *
 * `UploadedFile::isValid()` memanggil `is_uploaded_file()`, yang hanya bernilai
 * benar untuk berkas yang benar-benar tiba lewat permintaan HTTP multipart.
 * Dalam test hal itu mustahil dipenuhi, sehingga tanpa tiruan ini seluruh
 * pengujian jalur unggah akan berhenti di pemeriksaan "berkas gagal diunggah"
 * dan tidak pernah menyentuh kode yang ingin diuji.
 *
 * Yang diubah hanya pemeriksaan asal-usul berkas; sisanya perilaku asli.
 */
final class FakeUploadedFile extends UploadedFile
{
    public function isValid(): bool
    {
        return $this->getError() === UPLOAD_ERR_OK && is_file($this->getTempName());
    }
}
