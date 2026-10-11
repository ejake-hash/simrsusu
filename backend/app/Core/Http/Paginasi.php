<?php

namespace App\Core\Http;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Satu-satunya cara membuat endpoint daftar (PRD 10.3.1, 14.1.1 butir 1): paginasi keyset
 * pada (waktu, id), `?kursor=&batas=`, respons `{data: [...], meta: {kursor_berikutnya}}`.
 *
 * Bukan cursorPaginate() bawaan: Laravel membentuk kursor dari atribut ber-cast, sehingga
 * mikrodetik timestamptz hilang dan baris bisa terlewat atau berulang di batas halaman.
 * Filter cakupan (unit, tanggal, status) tetap tanggung jawab pemanggil, dipasang ke $query.
 */
final class Paginasi
{
    public const BATAS_BAWAAN = 50;

    public const BATAS_MAKSIMUM = 200;

    /**
     * @template TModel of Model
     *
     * @param  EloquentBuilder<TModel>|Builder  $query
     * @param  string  $kolomWaktu  kolom urutan utama, sebaiknya berindeks komposit bersama $kolomId
     * @param  'asc'|'desc'  $arah
     * @param  (callable(Collection<int, mixed>): mixed)|null  $bentuk  mis. fn ($baris) => XResource::collection($baris)->resolve()
     */
    public static function respons(
        EloquentBuilder|Builder $query,
        Request $request,
        string $kolomWaktu,
        string $kolomId = 'id',
        string $arah = 'desc',
        ?callable $bentuk = null,
    ): JsonResponse {
        $batas = self::batas($request);
        $kursor = self::bacaKursor($request);

        if ($kursor !== null) {
            $grammar = $query instanceof EloquentBuilder ? $query->getQuery()->getGrammar() : $query->getGrammar();
            // Perbandingan baris (waktu, id) memakai indeks komposit; nilai tetap lewat binding.
            $query->whereRaw(
                sprintf('(%s, %s) %s (?, ?)', $grammar->wrap($kolomWaktu), $grammar->wrap($kolomId), $arah === 'desc' ? '<' : '>'),
                $kursor,
            );
        }

        $baris = $query->reorder()->orderBy($kolomWaktu, $arah)->orderBy($kolomId, $arah)->limit($batas + 1)->get();

        $kursorBerikutnya = null;
        if ($baris->count() > $batas) {
            $baris = $baris->take($batas);
            $terakhir = $baris->last();
            $kursorBerikutnya = self::tulisKursor([self::nilaiMentah($terakhir, $kolomWaktu), self::nilaiMentah($terakhir, $kolomId)]);
        }

        return new JsonResponse([
            'data' => $bentuk ? $bentuk($baris->values()) : $baris->values(),
            'meta' => ['kursor_berikutnya' => $kursorBerikutnya],
        ]);
    }

    private static function batas(Request $request): int
    {
        $nilai = $request->query('batas');
        if ($nilai === null || $nilai === '') {
            return self::BATAS_BAWAAN;
        }

        if (! is_string($nilai) || ! ctype_digit($nilai) || (int) $nilai < 1) {
            throw new GalatApi(422, 'validasi_gagal', 'Isian belum benar.', [
                ['kolom' => 'batas', 'pesan' => 'Batas harus bilangan bulat positif.'],
            ]);
        }

        if ((int) $nilai > self::BATAS_MAKSIMUM) {
            throw new GalatApi(422, 'ukuran_halaman_melebihi_batas', 'Jumlah baris per halaman terlalu besar.', [
                ['kolom' => 'batas', 'pesan' => 'Maksimum '.self::BATAS_MAKSIMUM.' baris per halaman.'],
            ]);
        }

        return (int) $nilai;
    }

    /** @return array{0: string, 1: string}|null */
    private static function bacaKursor(Request $request): ?array
    {
        $nilai = $request->query('kursor');
        if ($nilai === null || $nilai === '') {
            return null;
        }

        $isi = is_string($nilai) ? json_decode((string) base64_decode(strtr($nilai, '-_', '+/'), true), true) : null;
        if (! is_array($isi) || ! array_is_list($isi) || count($isi) !== 2 || ! is_string($isi[0]) || ! is_string($isi[1])) {
            throw new GalatApi(422, 'kursor_tidak_valid', 'Kursor halaman tidak valid. Muat ulang daftar dari awal.');
        }

        return $isi;
    }

    /** @param  array{0: string, 1: string}  $nilai */
    private static function tulisKursor(array $nilai): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($nilai)), '+/', '-_'), '=');
    }

    /** Nilai kolom apa adanya dari basis data (bukan hasil cast), agar presisi waktu tidak hilang. */
    private static function nilaiMentah(mixed $baris, string $kolom): string
    {
        $nama = Str::afterLast($kolom, '.');

        return (string) ($baris instanceof Model ? $baris->getRawOriginal($nama) : data_get($baris, $nama));
    }
}
