<script setup lang="ts">
import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import Dialog from '@/design-system/components/Dialog.vue'
import KeadaanKosong from '@/design-system/components/KeadaanKosong.vue'
import Lencana from '@/design-system/components/Lencana.vue'
import Pesan from '@/design-system/components/Pesan.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import HalamanModul from '@/layouts/HalamanModul.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import { jamWib } from '@/modules/antrean/antrean'
import { semuaMatriks } from '@/modules/matriks-perpindahan/api'
import { catatDisposisi, daftarDisposisi, daftarKunjunganIgd } from '@/modules/pendaftaran/apiKunjungan'
import { KUNCI_KUNJUNGAN, lamaDiIgd } from '@/modules/pendaftaran/kunjungan'
import { formatNoRm, formatTanggal } from '@/modules/pendaftaran/pasien'
import type { Disposisi, Kunjungan, TujuanDisposisi } from '@/modules/pendaftaran/tipeKunjungan'
import { ApiError } from '@/shared/api/klien'
import { boleh } from '@/shared/composables/usePermission'
import LencanaTriase from '../components/LencanaTriase.vue'
import {
  AKIBAT_TUJUAN,
  butuhSerahTerima,
  KUNCI_DISPOSISI,
  LABEL_STATUS_DISPOSISI,
  LABEL_TUJUAN,
  labelTujuan,
  menutupKunjungan,
  NADA_STATUS_DISPOSISI,
  pesanGalatDisposisi,
  tujuanDariMatriks,
} from '../disposisi'
import { kategoriBerlaku, waktuTriase } from '../triase'

/**
 * IGD → Disposisi (tiket 2.26, kontrak v0.33.0; FR-03-09, FR-00-02, FR-00-03). Dokter memilih pasien IGD aktif, tujuan
 * dari matriks perpindahan aktif asal IGD, alasan wajib, dan serah terima (wajib untuk rawat inap dan kamar operasi).
 * Perintah hanya ditambah dan dikonfirmasi lebih dulu karena pulang, APS, rujuk keluar, meninggal, dan DOA menutup
 * kunjungan IGD. Jam dan pemberi perintah dari server.
 */
const qc = useQueryClient()
const route = useRoute()

// ── Daftar pasien IGD aktif (izin igd.pendaftaran.lihat, sama dengan layar Triase) ──
const bolehDaftarIgd = computed(() => boleh('igd.pendaftaran.lihat'))
const kueriIgd = useInfiniteQuery({
  queryKey: KUNCI_KUNJUNGAN.igd,
  queryFn: ({ pageParam }) => daftarKunjunganIgd(null, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
  refetchInterval: 15_000,
  enabled: bolehDaftarIgd,
})
const pasienIgd = computed<Kunjungan[]>(() => kueriIgd.data.value?.pages.flatMap((h) => h.data) ?? [])
const kolom = computed<KolomTabel[]>(() => [
  { kunci: 'pasien', label: 'Pasien' },
  { kunci: 'tiba', label: 'Jam tiba', lebar: '130px', prioritas: 2 },
  ...(boleh('igd.triase.lihat') ? [{ kunci: 'triase', label: 'Triase', lebar: '150px' }] : []),
])

/** Potret kunjungan saat dipilih: panel tetap menyebut pasiennya sesudah kunjungan ditutup dan keluar dari daftar. */
const terpilih = ref<Kunjungan | null>(null)
const masihAktif = computed(() => terpilih.value !== null && pasienIgd.value.some((k) => k.id === terpilih.value!.id))
function pilih(k: Kunjungan) {
  terpilih.value = k
}
// Tautan dari Pendaftaran IGD (?kunjungan=<id>): pilih baris itu sesudah daftar termuat.
watch(pasienIgd, (d) => {
  const id = route.query.kunjungan
  if (!terpilih.value && typeof id === 'string') {
    const k = d.find((x) => x.id === id)
    if (k) terpilih.value = k
  }
}, { immediate: true })

const sekarang = ref(new Date())
let detak: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  detak = setInterval(() => (sekarang.value = new Date()), 30_000)
})
onBeforeUnmount(() => clearInterval(detak))

// ── Tujuan dari matriks perpindahan (sel aktif asal IGD) ──
const kueriMatriks = useQuery({
  queryKey: KUNCI_DISPOSISI.matriksIgd,
  queryFn: () => semuaMatriks({ pos_asal: 'igd', status: 'aktif' }),
  staleTime: 60_000,
})
const pilihanTujuan = computed(() => tujuanDariMatriks(kueriMatriks.data.value ?? []))

// ── Formulir ──
const tujuan = ref<TujuanDisposisi | ''>('')
const alasan = ref('')
const serahTerima = ref('')
const wajibSerah = computed(() => butuhSerahTerima(tujuan.value))
function kosongkan() {
  tujuan.value = ''
  alasan.value = ''
  serahTerima.value = ''
}
watch(() => terpilih.value?.id, kosongkan)
// Tujuan yang hilang dari matriks (sel dinonaktifkan) tidak boleh tetap terpilih.
watch(pilihanTujuan, (p) => {
  if (tujuan.value && !p.includes(tujuan.value)) tujuan.value = ''
})
const kurang = computed(() => {
  if (!tujuan.value) return 'Pilih tujuan disposisi.'
  if (alasan.value.trim().length < 5) return 'Isi alasan, minimal 5 karakter.'
  if (wajibSerah.value && serahTerima.value.trim().length < 5) return 'Isi catatan serah terima, minimal 5 karakter.'
  return null
})

const konfirmasi = ref(false)
const simpan = useMutation({
  mutationFn: () =>
    catatDisposisi(terpilih.value!.id, {
      tujuan: tujuan.value as TujuanDisposisi,
      alasan: alasan.value.trim(),
      serah_terima: serahTerima.value.trim() || null,
    }),
  onSuccess: (d) => {
    konfirmasi.value = false
    const nama = terpilih.value?.pasien.nama ?? 'pasien'
    tampilkanToast(`Disposisi ${labelTujuan(d.tujuan)} tersimpan untuk ${nama}, pukul ${jamWib(d.diputuskan_pada)}. Status: ${LABEL_STATUS_DISPOSISI[d.status]}.`, 'ok')
    kosongkan()
    void qc.invalidateQueries({ queryKey: KUNCI_DISPOSISI.riwayat(d.kunjungan_id) })
    void qc.invalidateQueries({ queryKey: KUNCI_KUNJUNGAN.igd })
  },
  onError: (e) => {
    konfirmasi.value = false
    if (!(e instanceof ApiError)) return
    if (e.kode === 'status_tidak_sesuai') void qc.invalidateQueries({ queryKey: KUNCI_KUNJUNGAN.igd })
    if (e.kode === 'perpindahan_di_luar_matriks' || e.kode === 'tujuan_bukan_disposisi') void qc.invalidateQueries({ queryKey: KUNCI_DISPOSISI.matriksIgd })
    if (e.kode === 'perintah_sudah_menunggu' && terpilih.value) void qc.invalidateQueries({ queryKey: KUNCI_DISPOSISI.riwayat(terpilih.value.id) })
  },
})
watch([() => terpilih.value?.id, tujuan], () => simpan.reset())
const galat = computed(() => simpan.error.value)
const errorIsian = computed(() => errorKolom(galat.value, ['tujuan', 'alasan', 'serah_terima']))
const pesanGalat = computed(() => (galat.value ? (pesanGalatDisposisi(galat.value) ?? (Object.keys(errorIsian.value).length ? null : pesanError(galat.value))) : null))

function ajukan() {
  if (kurang.value === null) konfirmasi.value = true
}
const judulKonfirmasi = computed(() => (tujuan.value && terpilih.value ? `Simpan disposisi ${LABEL_TUJUAN[tujuan.value]} untuk ${terpilih.value.pasien.nama}?` : 'Simpan disposisi?'))
const uraianKonfirmasi = computed(() => {
  const k = terpilih.value
  if (!k || !tujuan.value) return ''
  const identitas = `${k.pasien.nama}, No. RM ${formatNoRm(k.pasien.no_rm)}, lahir ${formatTanggal(k.pasien.tanggal_lahir)}.`
  const akhir = menutupKunjungan(tujuan.value) ? ' Perintah tidak bisa dibatalkan dari layar ini.' : ''
  return `${identitas} ${AKIBAT_TUJUAN[tujuan.value]}${akhir}`
})

// ── Riwayat perintah kunjungan terpilih ──
const kueriRiwayat = useInfiniteQuery({
  queryKey: computed(() => KUNCI_DISPOSISI.riwayat(terpilih.value?.id ?? '')),
  queryFn: ({ pageParam }) => daftarDisposisi(terpilih.value!.id, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
  enabled: computed(() => terpilih.value !== null),
})
const riwayat = computed<Disposisi[]>(() => kueriRiwayat.data.value?.pages.flatMap((h) => h.data) ?? [])
</script>

<template>
  <HalamanModul
    izin="igd.disposisi.lihat"
    judul="Disposisi"
    uraian="Catat keputusan akhir pasien IGD sebagai perintah menurut matriks perpindahan. Perintah hanya ditambah; jam dan pemberi perintah dicatat server."
  >
    <div class="tata">
      <section class="kartu" aria-labelledby="judul-pasien-igd">
        <div class="kartu-kepala">
          <div>
            <h2 id="judul-pasien-igd">Pasien di IGD</h2>
            <p>Pilih pasien untuk disposisi. Urut jam tiba terlama; diperbarui otomatis tiap 15 detik.</p>
          </div>
        </div>
        <div v-if="!bolehDaftarIgd" class="kartu-isi">
          <Pesan jenis="peringatan">Butuh izin melihat Pendaftaran IGD; minta lewat Pengaturan peran.</Pesan>
        </div>
        <DataTable
          v-else
          :kolom="kolom"
          :baris="pasienIgd"
          :kunci-baris="(k: Kunjungan) => k.id"
          label="Tabel pasien di IGD untuk disposisi"
          dapat-dipilih
          :terpilih="terpilih?.id ?? null"
          :memuat="kueriIgd.isPending.value"
          :memuat-lagi="kueriIgd.isFetchingNextPage.value"
          :error="kueriIgd.isError.value ? pesanError(kueriIgd.error.value) : null"
          :ada-lagi="kueriIgd.hasNextPage.value"
          teks-kosong="Belum ada pasien di IGD."
          @pilih="pilih"
          @muat-lagi="kueriIgd.fetchNextPage()"
          @coba-lagi="kueriIgd.refetch()"
        >
          <template #sel-pasien="{ baris: k }">
            <b>{{ k.pasien.nama }}</b>
            <span class="ket">No. RM {{ formatNoRm(k.pasien.no_rm) }} · {{ formatTanggal(k.pasien.tanggal_lahir) }}</span>
          </template>
          <template #sel-tiba="{ baris: k }">
            {{ k.tiba_pada ? jamWib(k.tiba_pada) : '-' }}
            <span class="ket angka">{{ lamaDiIgd(k.tiba_pada, sekarang) }}</span>
          </template>
          <template #sel-triase="{ baris: k }"><LencanaTriase :kategori="kategoriBerlaku(k)" /></template>
        </DataTable>
      </section>

      <section class="kartu" aria-labelledby="judul-disposisi-pasien">
        <div class="kartu-kepala">
          <div>
            <h2 id="judul-disposisi-pasien">{{ terpilih ? `Disposisi ${terpilih.pasien.nama}` : 'Disposisi pasien' }}</h2>
            <p v-if="terpilih">
              No. RM {{ formatNoRm(terpilih.pasien.no_rm) }} · lahir {{ formatTanggal(terpilih.pasien.tanggal_lahir) }} · {{ terpilih.nomor }} · tiba {{ terpilih.tiba_pada ? jamWib(terpilih.tiba_pada) : '-' }}
            </p>
          </div>
        </div>

        <KeadaanKosong v-if="!terpilih" judul="Belum ada pasien dipilih" uraian="Pilih pasien dari daftar Pasien di IGD." />
        <template v-else>
          <div v-if="!masihAktif && !kueriIgd.isPending.value" class="kartu-isi">
            <Pesan jenis="info">Kunjungan IGD pasien ini sudah ditutup atau dibatalkan; pasien tidak lagi di daftar IGD aktif. Riwayat perintah tetap tampil di bawah.</Pesan>
          </div>
          <form v-else-if="boleh('igd.disposisi.input')" class="formulir" novalidate @submit.prevent="ajukan">
            <fieldset class="tujuan" :aria-describedby="errorIsian.tujuan ? 'galat-tujuan' : 'ket-tujuan'">
              <legend class="kolom-label">Tujuan <span class="kolom-wajib" aria-hidden="true">*</span></legend>
              <p id="ket-tujuan" class="tafsir">Hanya tujuan yang aktif di matriks perpindahan untuk IGD.</p>
              <p v-if="kueriMatriks.isPending.value" class="tafsir">Memuat tujuan…</p>
              <Pesan v-else-if="kueriMatriks.isError.value" jenis="error">Matriks perpindahan tidak dapat dimuat: {{ pesanError(kueriMatriks.error.value) }}</Pesan>
              <Pesan v-else-if="!pilihanTujuan.length" jenis="peringatan">Matriks perpindahan belum mengaktifkan disposisi apa pun untuk IGD. Hubungi pengelola data induk.</Pesan>
              <div v-else class="pilihan-tujuan">
                <label v-for="t in pilihanTujuan" :key="t" class="pilihan" :class="{ dipilih: tujuan === t }">
                  <input v-model="tujuan" type="radio" name="tujuan-disposisi" :value="t" />
                  <span>{{ LABEL_TUJUAN[t] }}</span>
                </label>
              </div>
              <p v-if="errorIsian.tujuan" id="galat-tujuan" class="galat">{{ errorIsian.tujuan }}</p>
              <p v-if="tujuan" class="tafsir akibat">{{ AKIBAT_TUJUAN[tujuan] }}</p>
            </fieldset>

            <div class="baris-isian">
              <KolomIsian v-slot="a" label="Alasan" wajib petunjuk="Dasar keputusan klinis, 5 sampai 1.000 karakter." :error="errorIsian.alasan">
                <textarea v-bind="a" v-model="alasan" class="isian" rows="3" maxlength="1000"></textarea>
              </KolomIsian>
              <KolomIsian
                v-slot="a"
                :label="wajibSerah ? 'Serah terima' : 'Serah terima (opsional)'"
                :wajib="wajibSerah"
                :petunjuk="wajibSerah ? 'Wajib untuk rawat inap dan kamar operasi: kondisi, terapi berjalan, dan hal yang perlu diawasi.' : 'Maksimal 4.000 karakter.'"
                :error="errorIsian.serah_terima"
              >
                <textarea v-bind="a" v-model="serahTerima" class="isian" rows="3" maxlength="4000"></textarea>
              </KolomIsian>
            </div>

            <div v-if="pesanGalat" class="blok">
              <Pesan jenis="error">{{ pesanGalat }}</Pesan>
            </div>
            <div class="aksi-kirim">
              <p v-if="kurang" class="tafsir">{{ kurang }}</p>
              <Tombol type="submit" varian="utama" :disabled="kurang !== null || undefined" :sibuk="simpan.isPending.value">Simpan disposisi</Tombol>
            </div>
          </form>

          <div class="riwayat">
            <h3 class="blok-judul">Riwayat perintah</h3>
            <p v-if="kueriRiwayat.isPending.value" class="tafsir">Memuat riwayat…</p>
            <Pesan v-else-if="kueriRiwayat.isError.value" jenis="error">{{ pesanError(kueriRiwayat.error.value) }}</Pesan>
            <p v-else-if="!riwayat.length" class="tafsir">Belum ada disposisi untuk kunjungan ini.</p>
            <ol v-else class="daftar-riwayat" aria-label="Riwayat disposisi, terbaru di atas">
              <li v-for="d in riwayat" :key="d.id">
                <div class="riwayat-kepala">
                  <b>{{ labelTujuan(d.tujuan) }}</b>
                  <Lencana :jenis="NADA_STATUS_DISPOSISI[d.status]">{{ LABEL_STATUS_DISPOSISI[d.status] }}</Lencana>
                  <span class="ket-baris angka">{{ waktuTriase(d.diputuskan_pada, sekarang) }}</span>
                </div>
                <p class="ket-baris">Alasan: {{ d.alasan }}</p>
                <p v-if="d.serah_terima" class="ket-baris">Serah terima: {{ d.serah_terima }}</p>
                <p class="ket-baris">Oleh {{ d.diputuskan_oleh?.nama ?? 'sistem' }}</p>
              </li>
            </ol>
            <Tombol v-if="kueriRiwayat.hasNextPage.value" :sibuk="kueriRiwayat.isFetchingNextPage.value" @click="kueriRiwayat.fetchNextPage()">Muat lagi</Tombol>
          </div>
        </template>
      </section>
    </div>

    <Dialog v-model:open="konfirmasi" :judul="judulKonfirmasi" :uraian="uraianKonfirmasi" :sibuk="simpan.isPending.value" :peringatan="tujuan !== '' && menutupKunjungan(tujuan)">
      <p class="ringkas-konfirmasi">Alasan: {{ alasan.trim() }}</p>
      <template #aksi>
        <button type="button" class="tombol" :disabled="simpan.isPending.value" @click="konfirmasi = false">Batal</button>
        <Tombol varian="utama" :sibuk="simpan.isPending.value" @click="simpan.mutate()">Ya, simpan disposisi</Tombol>
      </template>
    </Dialog>
  </HalamanModul>
</template>

<style scoped>
.tata {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: var(--space-4);
}
@media (min-width: 1024px) {
  .tata {
    grid-template-columns: minmax(0, 5fr) minmax(0, 6fr);
    align-items: start;
  }
}
.ket {
  display: block;
  font-size: 12px;
  color: var(--muted);
}
.angka {
  font-variant-numeric: tabular-nums;
}
.formulir {
  border-bottom: 1px solid var(--border);
}
.tujuan {
  display: grid;
  gap: var(--space-2);
  margin: 0;
  padding: var(--space-3) var(--space-4) 0;
  border: 0;
}
.pilihan-tujuan {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
  gap: var(--space-2);
}
.pilihan {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  min-height: 44px;
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--border-input);
  border-radius: var(--radius-input);
  background: var(--surface);
  font-weight: var(--font-weight-medium);
  cursor: pointer;
}
.pilihan:has(input:focus-visible) {
  outline: 2px solid var(--brand-600);
  outline-offset: 2px;
}
.pilihan.dipilih {
  border-width: 2px;
  border-color: var(--text);
  font-weight: var(--font-weight-bold);
}
.galat {
  margin: 0;
  font-size: 13px;
  color: var(--danger);
}
.baris-isian {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
}
.blok {
  padding: 0 var(--space-4) var(--space-3);
}
.blok-judul {
  margin: 0 0 var(--space-2);
  font-size: 14px;
  color: var(--muted);
}
.tafsir {
  margin: 0;
  font-size: 13px;
  color: var(--muted);
}
.akibat {
  color: var(--text);
}
.aksi-kirim {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
}
.riwayat {
  display: grid;
  gap: var(--space-2);
  padding: var(--space-3) var(--space-4) var(--space-4);
}
.daftar-riwayat {
  display: grid;
  gap: var(--space-2);
  margin: 0;
  padding: 0;
  list-style: none;
}
.daftar-riwayat li {
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--border);
  border-radius: var(--radius-input);
}
.riwayat-kepala {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
}
.ket-baris {
  margin: var(--space-1) 0 0;
  font-size: 13px;
  color: var(--muted);
  overflow-wrap: anywhere;
}
.riwayat-kepala .ket-baris {
  margin: 0;
}
.ringkas-konfirmasi {
  margin: 0;
  overflow-wrap: anywhere;
}
</style>
