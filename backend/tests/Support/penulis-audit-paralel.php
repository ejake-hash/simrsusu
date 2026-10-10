<?php

// Dipanggil AuditRantaiTest sebagai proses terpisah: menunggu waktu mulai bersama, lalu menulis
// sejumlah baris audit lewat CatatAktivitas (koneksi simrs_app, autocommit per baris).
// Pemakaian: php penulis-audit-paralel.php <label> <jumlah> <waktu-mulai-unix>

use App\Core\Audit\CatatAktivitas;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $label, $jumlah, $mulai] = $argv;
while (microtime(true) < (float) $mulai) {
    usleep(500);
}

$audit = $app->make(CatatAktivitas::class);
for ($i = 0; $i < (int) $jumlah; $i++) {
    $audit->catat("uji.paralel.{$label}");
}
