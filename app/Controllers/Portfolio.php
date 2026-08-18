<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Concerns\FiltersRequestInput;

/**
 * Tampilan portofolio: global, per sekuritas, dan per ticker (§5, §20, §22).
 */
class Portfolio extends BaseController
{
    use FiltersRequestInput;

    public function index(): string
    {
        return view('portfolio/index', [
            'pageTitle' => 'Portofolio Global',
        ] + $this->snapshot());
    }

    public function securities(): string
    {
        return view('portfolio/securities', [
            'pageTitle' => 'Portofolio per Sekuritas',
        ] + $this->snapshot());
    }

    public function tickers(): string
    {
        return view('portfolio/tickers', [
            'pageTitle' => 'Portofolio per Saham',
        ] + $this->snapshot());
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $asOf = (string) $this->dateInput('as_of', date('Y-m-d'));

        return ['snapshot' => service('portfolio')->snapshot($asOf), 'asOf' => $asOf];
    }
}
