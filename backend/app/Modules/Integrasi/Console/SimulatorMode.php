<?php

namespace App\Modules\Integrasi\Console;

use App\Modules\Integrasi\Domain\SistemIntegrasi;
use App\Modules\Integrasi\Infrastructure\SimulatorIntegrasi;
use Illuminate\Console\Command;

/** `php artisan integrasi:simulator <sistem> <mode>`: atur perilaku simulator (lokal/CI/e2e; tidak berlaku di produksi). */
final class SimulatorMode extends Command
{
    protected $signature = 'integrasi:simulator {sistem : bpjs_vclaim|aplicares|satusehat} {mode : normal|mati|gangguan|tolak}';

    protected $description = 'Atur mode simulator integrasi untuk menguji circuit breaker dan penolakan';

    public function handle(): int
    {
        $sistem = SistemIntegrasi::tryFrom((string) $this->argument('sistem'));
        $mode = (string) $this->argument('mode');
        if ($sistem === null || ! in_array($mode, SimulatorIntegrasi::MODE, true) || app()->isProduction()) {
            $this->error('Sistem atau mode tidak dikenal, atau berjalan di produksi.');

            return self::INVALID;
        }
        SimulatorIntegrasi::atur($sistem, $mode);
        $this->info("Simulator {$sistem->value}: $mode");

        return self::SUCCESS;
    }
}
