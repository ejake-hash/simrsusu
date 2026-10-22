import type { PermintaanTiruan, ResponsTiruan } from '../../akses/tiruan/serverTiruan'
import type { IsianPasien, JenisCariPasien, JenisKelaminPasien, KodeLabelReferensi, KontakDaruratPasien, Pasien, UbahPasien } from '../tipe'

/**
 * Server tiruan kontrak v0.19.0 (tiket 2.5): GET /pasien (cari satu kolom lewat header X-Cari), POST /pasien
 * (No. RM dibuat server, tanpa celah), PATCH /pasien/{id} (jejak versi, melengkapi Mr. X). Menyimpan keadaan di
 * memori dan meniru aturan kontrak termasuk 409/412/422/428. Semua data sintetis; bukan sumber aturan.
 */
const error = (status: number, kode: string, pesan: string, rincian: Record<string, unknown>[] = []): ResponsTiruan => ({ status, isi: { error: { kode, pesan, rincian } } })
const uid = (n: number) => `00000000-0000-7000-a000-${n.toString(16).padStart(12, '0')}`
const NO_STORE = { 'Cache-Control': 'no-store' }

/** Referensi contoh (kode → label) agar `agama`/`pendidikan`/dll. valid. */
const REF: Record<string, Record<string, string>> = {
  agama: { islam: 'Islam', kristen: 'Kristen', katolik: 'Katolik', hindu: 'Hindu', buddha: 'Buddha', konghucu: 'Konghucu' },
  pendidikan: { sd: 'SD', smp: 'SMP', sma: 'SMA/SMK', d3: 'Diploma III', s1: 'Sarjana (S1)' },
  pekerjaan: { pns: 'PNS', swasta: 'Karyawan swasta', wiraswasta: 'Wiraswasta', pelajar: 'Pelajar/Mahasiswa', irt: 'Ibu rumah tangga' },
  status_kawin: { belum_kawin: 'Belum kawin', kawin: 'Kawin', cerai_hidup: 'Cerai hidup', cerai_mati: 'Cerai mati' },
}
/** Kelurahan contoh valid (kode Kemendagri → nama bertingkat). */
const WILAYAH: Record<string, { kelurahan: string; kecamatan: string; kabupaten_kota: string; provinsi: string }> = {
  '12.71.01.1001': { kelurahan: 'Contoh Kelurahan A', kecamatan: 'Contoh Kecamatan', kabupaten_kota: 'Kota Contoh', provinsi: 'Sumatera Utara' },
  '12.71.01.1002': { kelurahan: 'Contoh Kelurahan B', kecamatan: 'Contoh Kecamatan', kabupaten_kota: 'Kota Contoh', provinsi: 'Sumatera Utara' },
}

interface VersiP {
  isi: IsianPasien
  alasan: string | null
  oleh: string
  pada: string
}
interface PasienT {
  id: string
  no_rm: string
  nik_sidik: string | null
  no_kartu_sidik: string | null
  versi: VersiP[]
  meninggal_pada: string | null
  dibuat_pada: string
}

export interface OpsiServerPasien {
  boleh: (kode: string) => boolean
  sekarang?: () => Date
}

export function buatServerPasien(opsi: OpsiServerPasien) {
  const sekarang = opsi.sekarang ?? (() => new Date())
  const iso = () => sekarang().toISOString()
  const bolehIdentitas = () => opsi.boleh('pendaftaran.pasien.identitas.lihat')
  let urutRm = 126
  let idN = 2000
  const daftar: PasienT[] = []

  const ref = (kelompok: string, kode: string | null | undefined): KodeLabelReferensi | null =>
    kode ? { kode, label: REF[kelompok]?.[kode] ?? kode } : null

  /** NIK termasking "1271 •••• •••• 0042" (4 depan + 4 belakang terlihat). */
  const samaran = (nilai: string | null, pisah: boolean): string | null => {
    if (!nilai) return null
    if (nilai.length <= 4) return `${nilai[0] ?? ''}${'•'.repeat(Math.max(0, nilai.length - 1))}`
    const depan = nilai.slice(0, 4)
    const belakang = nilai.slice(-4)
    const tengah = nilai.length - 8
    if (pisah) return `${depan} ${'•'.repeat(4)} ${'•'.repeat(4)} ${belakang}`
    return `${depan}${'•'.repeat(Math.max(0, tengah))}${belakang}`
  }

  const namaSementara = (jk: JenisKelaminPasien) => (jk === 'laki_laki' ? 'Mr. X' : jk === 'perempuan' ? 'Ny. X' : 'X')

  function bentuk(t: PasienT): Pasien {
    const v = t.versi[t.versi.length - 1]!.isi
    const w = v.alamat ? WILAYAH[v.alamat.wilayah_kode] : undefined
    const dasar: Pasien = {
      id: t.id,
      no_rm: t.no_rm,
      nama: v.nama ?? namaSementara(v.jenis_kelamin),
      nik_samaran: samaran(v.nik ?? null, true),
      no_paspor_samaran: samaran(v.no_paspor ?? null, false),
      no_kartu_bpjs: v.no_kartu_bpjs ?? null,
      tanggal_lahir: v.tanggal_lahir ?? null,
      tempat_lahir: v.tempat_lahir ?? null,
      jenis_kelamin: v.jenis_kelamin,
      agama: ref('agama', v.agama),
      pendidikan: ref('pendidikan', v.pendidikan),
      pekerjaan: ref('pekerjaan', v.pekerjaan),
      status_kawin: ref('status_kawin', v.status_kawin),
      kewarganegaraan: v.kewarganegaraan ?? 'wni',
      bahasa: v.bahasa ?? null,
      kebutuhan_khusus: v.kebutuhan_khusus ?? null,
      alamat: v.alamat && w ? { jalan: v.alamat.jalan, rt: v.alamat.rt ?? null, rw: v.alamat.rw ?? null, kode_pos: v.alamat.kode_pos ?? null, wilayah: { kode: v.alamat.wilayah_kode, ...w } } : null,
      telepon: v.telepon ?? null,
      kontak_darurat: v.kontak_darurat ?? [],
      tanpa_identitas: v.tanpa_identitas ?? false,
      ciri_pengenal: v.ciri_pengenal ?? null,
      meninggal_pada: t.meninggal_pada,
      jumlah_kunjungan: 0,
      sejak_tahun: Number(t.dibuat_pada.slice(0, 4)),
      versi: String(t.versi.length),
      dibuat_pada: t.dibuat_pada,
      diubah_pada: t.versi[t.versi.length - 1]!.pada,
    }
    // NIK/paspor utuh hanya bagi akun berizin bagian identitas (PRD 9.2.2); selain itu field tidak ada.
    if (bolehIdentitas()) {
      dasar.nik = v.nik ?? null
      dasar.no_paspor = v.no_paspor ?? null
    }
    return dasar
  }

  function tambah(isi: IsianPasien, oleh: string): PasienT {
    const t: PasienT = {
      id: uid(++idN),
      no_rm: String(++urutRm).padStart(8, '0'),
      nik_sidik: isi.nik ?? null,
      no_kartu_sidik: isi.no_kartu_bpjs ?? null,
      versi: [{ isi, alasan: null, oleh, pada: iso() }],
      meninggal_pada: null,
      dibuat_pada: iso(),
    }
    daftar.push(t)
    return t
  }

  // ── Data awal sintetis (RS Contoh Pendidikan) ──
  const KELUARGA: KontakDaruratPasien[] = [{ nama: 'Contoh Wali', hubungan: 'anak', telepon: '081200000001' }]
  const alamatContoh = { jalan: 'Jl. Contoh No. 1', rt: '001', rw: '002', kode_pos: '20155', wilayah_kode: '12.71.01.1001' }
  tambah({ nama: 'Contoh Pasien Satu', nik: '1271010101800042', no_kartu_bpjs: '0001234567890', tanggal_lahir: '1980-03-12', tempat_lahir: 'Kota Contoh', jenis_kelamin: 'laki_laki', agama: 'islam', pekerjaan: 'swasta', status_kawin: 'kawin', kewarganegaraan: 'wni', alamat: alamatContoh, telepon: '081200000010', kontak_darurat: KELUARGA }, 'Contoh Superadmin')
  tambah({ nama: 'Contoh Pasien Dua', nik: '1271020202750013', tanggal_lahir: '1975-02-02', tempat_lahir: 'Kota Contoh', jenis_kelamin: 'perempuan', agama: 'kristen', pekerjaan: 'irt', status_kawin: 'kawin', kewarganegaraan: 'wni', alamat: { ...alamatContoh, wilayah_kode: '12.71.01.1002' } }, 'Contoh Superadmin')
  tambah({ nama: 'Contoh Pasien Tiga', nik: '1271030303900099', tanggal_lahir: '1990-03-03', jenis_kelamin: 'perempuan', kewarganegaraan: 'wni' }, 'Contoh Superadmin')

  // ── Klasifikasi kata cari (kontrak v0.19.0) ──
  const TGL = /(\d{2})[-/](\d{2})[-/](\d{4})|(\d{4})-(\d{2})-(\d{2})/
  function klasifikasi(kataMentah: string): { jenis: JenisCariPasien; nilai: string; tgl?: string; nama?: string } | ResponsTiruan {
    const kata = kataMentah.trim()
    if (!kata) return error(422, 'kata_cari_wajib', 'Isi kata cari.', [])
    const angka = kata.replace(/[\s.-]/g, '')
    if (/^\d+$/.test(angka)) {
      if (angka.length === 16) return { jenis: 'nik', nilai: angka }
      if (angka.length === 13) return { jenis: 'no_kartu', nilai: angka }
      if (angka.length >= 1 && angka.length <= 12) return { jenis: 'no_rm', nilai: angka }
      return error(422, 'kata_cari_tidak_dikenali', 'Kata cari tidak dikenali. Masukkan NIK 16 digit, No. RM, No. kartu BPJS 13 digit, atau nama disertai tanggal lahir (mis. Budi 12-03-1980).', [])
    }
    const cocok = kata.match(TGL)
    if (cocok) {
      const tgl = cocok[4] ? `${cocok[4]}-${cocok[5]}-${cocok[6]}` : `${cocok[3]}-${cocok[2]}-${cocok[1]}`
      const nama = kata.replace(TGL, '').trim()
      if (nama.replace(/[^a-zA-Z]/g, '').length >= 3) return { jenis: 'nama_tanggal_lahir', nilai: kata, tgl, nama }
    }
    return error(422, 'kata_cari_tidak_dikenali', 'Kata cari tidak dikenali. Nama harus disertai tanggal lahir (mis. Budi 12-03-1980).', [])
  }

  /** Kemiripan nama sederhana (pengganti indeks trigram): cocok bila salah satu memuat yang lain. */
  const miripNama = (a: string, b: string) => {
    const x = a.toLowerCase().trim()
    const y = b.toLowerCase().trim()
    return x.includes(y) || y.includes(x)
  }

  function cari(kataMentah: string): ResponsTiruan {
    const k = klasifikasi(kataMentah)
    if ('status' in k) return k
    let hasil: PasienT[]
    if (k.jenis === 'nik') hasil = daftar.filter((t) => t.nik_sidik === k.nilai)
    else if (k.jenis === 'no_kartu') hasil = daftar.filter((t) => t.no_kartu_sidik === k.nilai)
    else if (k.jenis === 'no_rm') hasil = daftar.filter((t) => String(Number(t.no_rm)) === String(Number(k.nilai)))
    else hasil = daftar.filter((t) => t.versi[t.versi.length - 1]!.isi.tanggal_lahir === k.tgl && miripNama(t.versi[t.versi.length - 1]!.isi.nama ?? '', k.nama ?? ''))
    // Terbaru didaftarkan dulu (keyset dibuat_pada, id).
    const baris = [...hasil].sort((a, b) => b.dibuat_pada.localeCompare(a.dibuat_pada) || b.id.localeCompare(a.id)).map(bentuk)
    return { status: 200, header: NO_STORE, isi: { data: baris, meta: { kursor_berikutnya: null, jenis_cari: k.jenis } } }
  }

  // ── Validasi POST/PATCH ──
  const salahIsi = (rincian: Record<string, unknown>[]) => error(422, 'validasi_gagal', 'Isian belum benar.', rincian)
  function validasiReferensi(isi: Partial<IsianPasien>): ResponsTiruan | null {
    for (const k of ['agama', 'pendidikan', 'pekerjaan', 'status_kawin'] as const) {
      const v = isi[k]
      if (v != null && v !== '' && !REF[k]?.[v]) return error(422, 'referensi_tidak_valid', 'Kode referensi tidak ditemukan.', [{ kolom: k }])
    }
    if (isi.alamat && !WILAYAH[isi.alamat.wilayah_kode]) return error(422, 'wilayah_tidak_valid', 'Kode wilayah tidak ditemukan.', [{ kolom: 'wilayah_kode' }])
    return null
  }
  const nikDipakai = (nik: string, kecuali?: string) => daftar.filter((t) => t.id !== kecuali && t.nik_sidik === nik).map((t) => ({ pasien_id: t.id, no_rm: t.no_rm }))
  const kartuDipakai = (no: string, kecuali?: string) => daftar.filter((t) => t.id !== kecuali && t.no_kartu_sidik === no).map((t) => ({ pasien_id: t.id, no_rm: t.no_rm }))

  function buat(isi: IsianPasien): ResponsTiruan {
    if (typeof isi.jenis_kelamin !== 'string') return salahIsi([{ kolom: 'jenis_kelamin', pesan: 'Pilih jenis kelamin.' }])
    const tanpa = isi.tanpa_identitas === true
    if (!tanpa) {
      if (!isi.nama || !String(isi.nama).trim()) return salahIsi([{ kolom: 'nama', pesan: 'Isi nama pasien.' }])
      if (!isi.tanggal_lahir) return salahIsi([{ kolom: 'tanggal_lahir', pesan: 'Isi tanggal lahir.' }])
      if (isi.kewarganegaraan === 'wna' && !isi.nik && !isi.no_paspor) return salahIsi([{ kolom: 'no_paspor', pesan: 'WNA tanpa NIK wajib mengisi nomor paspor.' }])
    }
    const ref = validasiReferensi(isi)
    if (ref) return ref
    if (isi.nik) {
      const ada = nikDipakai(isi.nik)
      if (ada.length) return error(409, 'nik_sudah_terdaftar', 'NIK sudah terdaftar untuk pasien lain.', ada)
    }
    if (isi.no_kartu_bpjs) {
      const ada = kartuDipakai(isi.no_kartu_bpjs)
      if (ada.length) return error(409, 'no_kartu_sudah_terdaftar', 'No. kartu BPJS sudah terdaftar untuk pasien lain.', ada)
    }
    const t = tambah({ ...isi, kewarganegaraan: isi.kewarganegaraan ?? 'wni' }, 'Contoh Superadmin')
    return { status: 201, header: { ...NO_STORE, ETag: `"${t.versi.length}"` }, isi: { data: bentuk(t) } }
  }

  function ubah(t: PasienT, isi: UbahPasien, ifMatch: string | undefined): ResponsTiruan {
    if (!ifMatch) return error(428, 'if_match_wajib', 'Sertakan versi (If-Match).', [])
    if (ifMatch.replace(/"/g, '') !== String(t.versi.length)) return error(412, 'versi_berubah', 'Data sudah diubah orang lain. Muat ulang.', [])
    if (!isi.alasan_perubahan || isi.alasan_perubahan.trim().length < 5) return salahIsi([{ kolom: 'alasan_perubahan', pesan: 'Isi alasan perubahan, minimal 5 karakter.' }])
    const lama = t.versi[t.versi.length - 1]!.isi
    const { alasan_perubahan, ...patch } = isi
    if (!Object.keys(patch).length) return salahIsi([{ kolom: 'nama', pesan: 'Kirim minimal satu isian untuk diubah.' }])
    // Mengubah/mengosongkan NIK/paspor yang sudah terisi butuh izin bagian identitas.
    for (const k of ['nik', 'no_paspor'] as const) {
      if (k in patch && (lama[k] ?? null) !== null && patch[k] !== lama[k] && !bolehIdentitas()) return error(403, 'akses_ditolak', 'Anda tidak berhak mengubah identitas (NIK/paspor) pasien.', [])
    }
    const baru: IsianPasien = { ...lama, ...patch }
    if (patch.tanpa_identitas === true && lama.tanpa_identitas !== true) return salahIsi([{ kolom: 'tanpa_identitas', pesan: 'Pasien yang sudah beridentitas tidak bisa dikembalikan menjadi tanpa identitas.' }])
    if (baru.tanpa_identitas === false) {
      if (!baru.nama || !String(baru.nama).trim()) return salahIsi([{ kolom: 'nama', pesan: 'Isi nama pasien.' }])
      if (!baru.tanggal_lahir) return salahIsi([{ kolom: 'tanggal_lahir', pesan: 'Isi tanggal lahir.' }])
    }
    const ref = validasiReferensi(patch)
    if (ref) return ref
    if (patch.nik && patch.nik !== lama.nik) {
      const ada = nikDipakai(patch.nik, t.id)
      if (ada.length) return error(409, 'nik_sudah_terdaftar', 'NIK sudah terdaftar untuk pasien lain.', ada)
    }
    if (patch.no_kartu_bpjs && patch.no_kartu_bpjs !== lama.no_kartu_bpjs) {
      const ada = kartuDipakai(patch.no_kartu_bpjs, t.id)
      if (ada.length) return error(409, 'no_kartu_sudah_terdaftar', 'No. kartu BPJS sudah terdaftar untuk pasien lain.', ada)
    }
    if ('nik' in patch) t.nik_sidik = patch.nik ?? null
    if ('no_kartu_bpjs' in patch) t.no_kartu_sidik = patch.no_kartu_bpjs ?? null
    t.versi.push({ isi: baru, alasan: alasan_perubahan.trim(), oleh: 'Contoh Superadmin', pada: iso() })
    return { status: 200, header: { ...NO_STORE, ETag: `"${t.versi.length}"` }, isi: { data: bentuk(t) } }
  }

  function tangani(r: PermintaanTiruan): ResponsTiruan | null {
    const [a0, a1] = r.jalur.replace(/^\/+|\/+$/g, '').split('/')
    if (a0 !== 'pasien') return null
    const metode = r.metode.toUpperCase()
    if (!a1) {
      if (metode === 'GET') {
        if (!opsi.boleh('pendaftaran.pasien.lihat')) return error(403, 'akses_ditolak', 'Anda tidak berhak mencari pasien.')
        const kata = r.header['x-cari']
        if (kata === undefined || kata === '') return error(422, 'kata_cari_wajib', 'Isi kata cari.')
        return cari(decodeURIComponent(kata))
      }
      if (metode === 'POST') {
        if (!opsi.boleh('pendaftaran.pasien.input')) return error(403, 'akses_ditolak', 'Anda tidak berhak mendaftarkan pasien.')
        return buat((r.isi ?? {}) as IsianPasien)
      }
      return null
    }
    const t = daftar.find((x) => x.id === a1)
    if (metode === 'PATCH') {
      if (!opsi.boleh('pendaftaran.pasien.perbaiki')) return error(403, 'akses_ditolak', 'Anda tidak berhak memperbaiki data pasien.')
      if (!t) return error(404, 'tidak_ditemukan', 'Pasien tidak ditemukan.')
      return ubah(t, (r.isi ?? {}) as UbahPasien, r.header['if-match'])
    }
    return null
  }

  return { tangani, contoh: { satu: daftar[0]!, dua: daftar[1]!, tiga: daftar[2]! } }
}

export type ServerPasien = ReturnType<typeof buatServerPasien>
