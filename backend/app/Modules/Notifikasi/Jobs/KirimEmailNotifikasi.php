<?php

namespace App\Modules\Notifikasi\Jobs;

use App\Core\Queue\JenisQueue;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Notifikasi\Mail\NotifikasiMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/** Kirim satu email notifikasi (antrean `notifikasi`, sesudah commit). Alamat dibaca saat dikirim, tidak disimpan di payload. */
final class KirimEmailNotifikasi implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $penggunaId,
        public readonly string $judul,
        public readonly string $isi,
        public readonly ?string $tautan,
    ) {
        $this->onConnection(JenisQueue::Notifikasi->koneksi())->onQueue(JenisQueue::Notifikasi->value)->afterCommit();
    }

    public function handle(): void
    {
        $email = Pengguna::query()->whereKey($this->penggunaId)->value('email');
        if (is_string($email) && $email !== '') {
            Mail::to($email)->send(new NotifikasiMail($this->judul, $this->isi, $this->tautan));
        }
    }
}
