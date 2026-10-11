<?php

namespace App\Modules\Akses\Application;

use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\Pengguna;
use Illuminate\Support\Facades\DB;

/**
 * Menu efektif untuk sidebar (MenuService, PRD D.4; kontrak docs/api/openapi.yaml → menuSaya).
 * - Sub-menu tampil bila diizinkan (AccessEvaluator lapis 1); menu tampil bila minimal satu
 *   sub-menunya tampil. Yang tidak diizinkan tidak dikirim sama sekali.
 * - Status `aktif` bila halamannya sudah dibangun dan modulnya aktif (inti.modul_aktif, PRD 3.4).
 *   Selain itu `segera` hanya bila menu.tampilkan_segera_hadir; di produksi disembunyikan (PRD 10.4).
 */
final class MenuSaya
{
    public function __construct(private readonly AccessEvaluator $evaluator) {}

    /**
     * @return array{versi: string, grup: list<array<string, mixed>>, izin: list<string>}
     */
    public function untuk(Pengguna $pengguna): array
    {
        $hak = $this->evaluator->hak($pengguna);
        $modulAktif = DB::table('inti.modul_aktif')->whereNull('unit_organisasi_id')->pluck('modul_no')->map(fn ($n) => (int) $n)->all();
        $tampilkanSegera = (bool) config('menu.tampilkan_segera_hadir');
        $status = fn (Menu $m) => $m->tersedia && in_array($m->modul_no, $modulAktif, true)
            ? 'aktif'
            : ($tampilkanSegera ? 'segera' : null);

        $anak = Menu::query()->where('aktif', true)->orderBy('urutan')->get()->groupBy(fn (Menu $m) => (string) $m->induk_id);

        $grup = [];
        foreach ($anak[''] ?? [] as $g) {
            $daftarMenu = [];
            foreach ($anak[$g->id] ?? [] as $m) {
                $subMenu = [];
                $punyaSub = false;
                foreach ($anak[$m->id] ?? [] as $s) {
                    if ($s->tingkat !== 'sub_menu') {
                        continue;
                    }
                    $punyaSub = true;
                    $statusSub = $status($s);
                    if ($statusSub !== null && $hak->bolehMenu($s->kode)) {
                        $subMenu[] = ['kode' => $s->kode, 'label' => $s->label, 'rute' => $s->rute, 'status' => $statusSub, 'fase' => $s->fase];
                    }
                }

                $statusMenu = $punyaSub
                    ? ($subMenu === [] ? null : (in_array('aktif', array_column($subMenu, 'status'), true) ? 'aktif' : 'segera'))
                    : ($hak->bolehMenu($m->kode) ? $status($m) : null);
                if ($statusMenu !== null) {
                    $daftarMenu[] = [
                        'kode' => $m->kode, 'label' => $m->label, 'ikon' => $m->ikon, 'rute' => $m->rute,
                        'status' => $statusMenu, 'fase' => $m->fase, 'jumlah_tugas' => null, 'sub_menu' => $subMenu,
                    ];
                }
            }

            if ($daftarMenu !== []) {
                $grup[] = ['kode' => $g->kode, 'label' => $g->label, 'menu' => $daftarMenu];
            }
        }

        $izin = array_keys($hak->izin);

        return [
            'versi' => substr(hash('sha256', (string) json_encode([$grup, $izin])), 0, 16),
            'grup' => $grup,
            'izin' => $izin,
        ];
    }
}
