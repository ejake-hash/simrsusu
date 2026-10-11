<?php

namespace App\Modules\Akses\Application;

use RuntimeException;

/** Keadaan berubah sejak pengajuan sehingga persetujuan tidak bisa diterapkan (mis. peran nonaktif). */
final class TidakBisaDiterapkan extends RuntimeException {}
