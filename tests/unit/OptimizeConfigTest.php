<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Config\BaseConfig;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Investment;
use Config\Optimize;
use Throwable;

/**
 * Penjaga setelan cache CI4 (§11).
 *
 * Test ini ada karena kesalahannya pernah benar-benar terjadi: menambahkan satu
 * properti ke kelas Config membuat seluruh aplikasi produksi mati dengan
 * "Cannot assign null to property ... of type string".
 *
 * @internal
 */
final class OptimizeConfigTest extends CIUnitTestCase
{
    /**
     * Config caching HARUS mati.
     *
     * Cache config dimuat di dalam Boot, sebelum event pre_system — sehingga
     * App\Libraries\DeploymentRefresh, yang membersihkan cache basi setiap kali
     * VERSION berubah, tidak pernah sempat berjalan bila cache itu sendiri yang
     * merusak boot. Di server tanpa shell, satu-satunya pemulihan adalah
     * menghapus berkas cache lewat File Manager.
     */
    public function testConfigCachingStaysDisabled(): void
    {
        $this->assertFalse(
            (new Optimize())->configCacheEnabled,
            'Config caching mematikan aplikasi begitu ada properti Config baru; lihat komentar di app/Config/Optimize.php.',
        );
    }

    /**
     * Cache locator boleh menyala: ia menyembunyikan berkas baru, tetapi tidak
     * mematikan boot, sehingga DeploymentRefresh masih sempat membersihkannya.
     */
    public function testLocatorCachingMayStayEnabled(): void
    {
        $this->assertIsBool((new Optimize())->locatorCacheEnabled);
    }

    /**
     * Membuktikan sebab kegagalannya, bukan sekadar mencatatnya: inilah yang
     * dilakukan cache config saat memuat objek yang ditulis versi sebelumnya.
     */
    public function testSetStateFailsWhenCachedDataPredatesANewProperty(): void
    {
        $config = new Investment();
        $cached = get_object_vars($config);

        // Meniru cache yang ditulis sebelum properti ini ada.
        unset($cached['idxDailySummaryUrl']);

        $this->assertInstanceOf(BaseConfig::class, $config);

        // Jenis kesalahannya berbeda menurut lingkungan: di test, warning
        // "Undefined array key" sudah diubah menjadi ErrorException lebih dulu;
        // di produksi ia lolos dan penugasan null-lah yang ditolak sebagai
        // TypeError. Cacatnya satu dan sama — cache lama kehilangan properti.
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/idxDailySummaryUrl|Cannot assign null/');

        Investment::__set_state($cached);
    }
}
