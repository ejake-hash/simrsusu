<script setup lang="ts">
import { useInfiniteQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import KeadaanKosong from '@/design-system/components/KeadaanKosong.vue'
import Lencana from '@/design-system/components/Lencana.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import Ikon from '@/design-system/ikon/Ikon.vue'
import HalamanModul from '@/layouts/HalamanModul.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import type { Berversi } from '@/modules/organisasi/api'
import { ApiError } from '@/shared/api/klien'
import { refDebounced } from '@/shared/composables/refDebounced'
import { boleh } from '@/shared/composables/usePermission'
import { cariPasien } from '../api'
import DialogPasien from '../components/DialogPasien.vue'
import { formatNoRm, formatTanggal, KUNCI_PASIEN, LABEL_JENIS_CARI, tampilanNik } from '../pasien'
import type { Pasien } from '../tipe'

/**
 * Pendaftaran → Pendaftaran pasien (tiket 2.5, kontrak v0.19.0). Satu kolom cari: NIK (16 digit), No. RM, No. kartu
 * BPJS (13 digit), atau nama disertai tanggal lahir. Kata cari dikirim lewat header X-Cari (bukan URL). Dari sini
 * petugas membuka pasien lama untuk diperbaiki atau mendaftarkan pasien baru (termasuk Mr. X tanpa identitas).
 */
const qc = useQueryClient()
const cari = ref('')
const cariTunda = refDebounced(cari, 350)
const kataAktif = computed(() => cariTunda.value.trim())

const kueri = useInfiniteQuery({
  queryKey: computed(() => KUNCI_PASIEN.cari(kataAktif.value)),
  queryFn: ({ pageParam }) => cariPasien(kataAktif.value, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
  enabled: computed(() => kataAktif.value.length > 0),
})
const baris = computed(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])
const jenisCari = computed(() => kueri.data.value?.pages[0]?.meta.jenis_cari ?? null)
/** 422 kata_cari_* dari server: tampilkan sebagai petunjuk, bukan "gagal dimuat". */
const pesanBentuk = computed(() => {
  const e = kueri.error.value
  return e instanceof ApiError && (e.kode === 'kata_cari_tidak_dikenali' || e.kode === 'kata_cari_wajib') ? e.message : null
})
const errorTabel = computed(() => (kueri.isError.value && !pesanBentuk.value ? pesanError(kueri.error.value) : null))

const kolom = computed<KolomTabel[]>(() => [
  { kunci: 'no_rm', label: 'No. RM', lebar: '140px' },
  { kunci: 'nama', label: 'Nama pasien' },
  { kunci: 'nik', label: 'NIK', prioritas: 2, lebar: '190px' },
  { kunci: 'tanggal_lahir', label: 'Tanggal lahir', prioritas: 3, lebar: '150px' },
  { kunci: 'jenis_kelamin', label: 'L/P', prioritas: 3, lebar: '80px' },
  { kunci: 'aksi', label: 'Aksi', rata: 'kanan', lebar: '110px' },
])

const dialog = ref(false)
const target = ref<Berversi<Pasien> | null>(null)
function baru() {
  target.value = null
  dialog.value = true
}
function buka(p: Pasien) {
  // Rincian dari pencarian sudah lengkap; etag dari versi (GET /pasien tidak memberi ETag per baris).
  target.value = { data: p, etag: `"${p.versi}"` }
  dialog.value = true
}
function selesai(hasil: Berversi<Pasien>) {
  tampilkanToast(target.value ? 'Perubahan tersimpan.' : `Pasien terdaftar dengan No. RM ${hasil.data.no_rm}.`, 'ok')
  if (!target.value) cari.value = hasil.data.no_rm
  void qc.invalidateQueries({ queryKey: KUNCI_PASIEN.semua })
}
</script>

<template>
  <HalamanModul
    izin="pendaftaran.pasien.lihat"
    judul="Pendaftaran pasien"
    uraian="Temukan pasien lama dengan satu kolom pencarian, atau daftarkan pasien baru. No. RM berlaku seumur hidup dan tidak pernah dipakai ulang."
  >
    <section class="kartu" aria-labelledby="judul-cari">
      <div class="kartu-kepala">
        <div>
          <h2 id="judul-cari">Cari atau daftarkan pasien</h2>
          <p>Ketik NIK, No. RM, No. kartu BPJS, atau nama disertai tanggal lahir (mis. Budi 12-03-1980).</p>
        </div>
        <div class="aksi-kepala">
          <RouterLink class="tombol" to="/pendaftaran/kunjungan"><Ikon nama="pendaftaran" :ukuran="16" />Pendaftaran kunjungan poli</RouterLink>
          <button v-can="'pendaftaran.pasien.input'" type="button" class="tombol tombol-utama" @click="baru"><Ikon nama="tambah" :ukuran="16" />Pasien baru</button>
        </div>
      </div>

      <div class="cari-baris">
        <Ikon nama="cari" :ukuran="18" />
        <label class="sr-only" for="cari-pasien">Cari pasien</label>
        <input id="cari-pasien" v-model="cari" type="search" class="isian isian-cari" autocomplete="off" placeholder="Cari pasien: NIK, No. RM, No. kartu BPJS, atau nama + tanggal lahir" />
      </div>

      <p v-if="kataAktif && jenisCari" class="tafsir" role="status">
        Dicari sebagai <b>{{ LABEL_JENIS_CARI[jenisCari] }}</b>.
      </p>

      <KeadaanKosong v-if="!kataAktif" judul="Mulai dengan mencari pasien" uraian="Hasil muncul saat Anda mengetik. Pasien yang sudah meninggal tetap ditemukan." />
      <KeadaanKosong v-else-if="pesanBentuk" jenis="error" judul="Kata cari belum dikenali" :uraian="pesanBentuk" />
      <DataTable
        v-else
        :kolom="kolom"
        :baris="baris"
        :kunci-baris="(p: Pasien) => p.id"
        label="Hasil pencarian pasien"
        :memuat="kueri.isPending.value || kueri.isFetching.value && baris.length === 0"
        :memuat-lagi="kueri.isFetchingNextPage.value"
        :error="errorTabel"
        :ada-lagi="kueri.hasNextPage.value"
        teks-kosong="Tidak ada pasien yang cocok. Periksa kembali kata cari, atau daftarkan sebagai pasien baru."
        @muat-lagi="kueri.fetchNextPage()"
        @coba-lagi="kueri.refetch()"
      >
        <template #sel-no_rm="{ baris: p }"><code>{{ formatNoRm(p.no_rm) }}</code></template>
        <template #sel-nama="{ baris: p }">
          <b>{{ p.nama }}</b>
          <Lencana v-if="p.tanpa_identitas" jenis="peringatan" class="tanda">Tanpa identitas</Lencana>
          <Lencana v-if="p.meninggal_pada" jenis="netral" class="tanda">Meninggal</Lencana>
          <Lencana v-if="p.digabung_ke" jenis="info" class="tanda">Digabung ke {{ formatNoRm(p.digabung_ke.no_rm) }}</Lencana>
          <Lencana v-if="p.digabung_dari.length" jenis="netral" class="tanda">Gabungan dari {{ p.digabung_dari.map((d) => formatNoRm(d.no_rm)).join(', ') }}</Lencana>
        </template>
        <template #sel-nik="{ baris: p }">
          <span class="nik">{{ tampilanNik(p) ?? '—' }}</span>
          <Ikon v-if="p.nik === undefined && p.nik_samaran" nama="gembok" :ukuran="12" class="kunci" aria-label="NIK termasking" />
        </template>
        <template #sel-tanggal_lahir="{ baris: p }">{{ formatTanggal(p.tanggal_lahir) }}</template>
        <template #sel-jenis_kelamin="{ baris: p }">{{ p.jenis_kelamin === 'laki_laki' ? 'L' : p.jenis_kelamin === 'perempuan' ? 'P' : '—' }}</template>
        <template #sel-aksi="{ baris: p }">
          <Tombol :aria-label="`Buka ${p.nama}`" @click="buka(p)">{{ (boleh('pendaftaran.pasien.perbaiki')) ? 'Buka' : 'Lihat' }}</Tombol>
        </template>
      </DataTable>
    </section>

    <DialogPasien v-model:open="dialog" :pasien="target" @selesai="selesai" @muat-ulang="kueri.refetch()" />
  </HalamanModul>
</template>

<style scoped>
.cari-baris {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-3) var(--space-4);
  color: var(--muted);
}
.isian-cari {
  flex: 1;
  min-width: 0;
}
.tafsir {
  margin: 0;
  padding: 0 var(--space-4) var(--space-2);
  font-size: 13px;
  color: var(--muted);
}
.aksi-kepala {
  display: inline-flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}
.tanda {
  margin-left: var(--space-2);
}
.nik {
  font-family: var(--font-mono);
}
.kunci {
  margin-left: var(--space-1);
  color: var(--muted);
  vertical-align: middle;
}
</style>
