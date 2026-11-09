<script setup lang="ts">
import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import KeadaanKosong from '@/design-system/components/KeadaanKosong.vue'
import Lencana from '@/design-system/components/Lencana.vue'
import Pesan from '@/design-system/components/Pesan.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import HalamanModul from '@/layouts/HalamanModul.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import { jamWib } from '@/modules/antrean/antrean'
import { catatTriase, daftarInstrumenTriase, daftarKunjunganIgd, daftarTriase } from '@/modules/pendaftaran/apiKunjungan'
import { KUNCI_KUNJUNGAN, lamaDiIgd } from '@/modules/pendaftaran/kunjungan'
import { formatNoRm, formatTanggal } from '@/modules/pendaftaran/pasien'
import type { KategoriTriase, Kunjungan, Triase } from '@/modules/pendaftaran/tipeKunjungan'
import { ApiError } from '@/shared/api/klien'
import { boleh } from '@/shared/composables/usePermission'
import LencanaTriase from '../components/LencanaTriase.vue'
import { IKON_KATEGORI, KATEGORI_TRIASE, kategoriBerlaku, KUNCI_TRIASE, LABEL_KATEGORI, waktuTriase } from '../triase'

/**
 * IGD → Triase (tiket 2.24, kontrak v0.32.0; FR-03-02). Perawat memilih pasien dari daftar IGD aktif, memilih kategori
 * (teks + ikon, bukan hanya warna), instrumen dan skala dari data induk RS, dan keluhan opsional. Ulang triase menambah
 * baris baru; riwayat terbaru di atas dan baris terbaru berlabel "Berlaku". Jam triase dari server.
 */
const qc = useQueryClient()

// ── Daftar pasien IGD aktif ──
// Daftar IGD butuh igd.pendaftaran.lihat (keputusan pemilik 9 Nov 2026: layar Triase tidak melonggarkan izin itu).
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
const kolom: KolomTabel[] = [
  { kunci: 'pasien', label: 'Pasien' },
  { kunci: 'tiba', label: 'Jam tiba', lebar: '130px', prioritas: 2 },
  { kunci: 'triase', label: 'Triase berlaku', lebar: '160px' },
]
const kunjunganId = ref<string | null>(null)
const terpilih = computed(() => pasienIgd.value.find((k) => k.id === kunjunganId.value) ?? null)

const sekarang = ref(new Date())
let detak: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  detak = setInterval(() => (sekarang.value = new Date()), 30_000)
})
onBeforeUnmount(() => clearInterval(detak))

// ── Instrumen data induk ──
const kueriInstrumen = useQuery({ queryKey: KUNCI_TRIASE.instrumen, queryFn: daftarInstrumenTriase, staleTime: 60_000 })
const daftarInstrumen = computed(() => kueriInstrumen.data.value ?? [])

// ── Formulir triase ──
const kategori = ref<KategoriTriase | null>(null)
const instrumenId = ref('')
const skala = ref('')
const keluhan = ref('')
const instrumen = computed(() => daftarInstrumen.value.find((i) => i.id === instrumenId.value) ?? null)
// Satu instrumen aktif: pilih otomatis supaya perawat tidak menebak.
watch(daftarInstrumen, (d) => {
  if (!instrumenId.value && d.length === 1) instrumenId.value = d[0]!.id
}, { immediate: true })
watch(instrumenId, () => (skala.value = ''))
function kosongkan() {
  kategori.value = null
  skala.value = ''
  keluhan.value = ''
}
watch(kunjunganId, kosongkan)

const simpan = useMutation({
  mutationFn: () =>
    catatTriase(kunjunganId.value!, {
      kategori: kategori.value!,
      instrumen_triase_id: instrumenId.value || null,
      skala: skala.value || null,
      keluhan: keluhan.value.trim() || null,
    }),
  onSuccess: (t) => {
    const nama = terpilih.value?.pasien.nama ?? 'pasien'
    tampilkanToast(`Triase ${LABEL_KATEGORI[t.kategori]} tersimpan untuk ${nama}, pukul ${jamWib(t.ditriase_pada)}.`, 'ok')
    kosongkan()
    void qc.invalidateQueries({ queryKey: KUNCI_TRIASE.riwayat(t.kunjungan_id) })
    void qc.invalidateQueries({ queryKey: KUNCI_KUNJUNGAN.igd })
  },
  // Kunjungan yang sudah selesai/batal tidak lagi di daftar IGD aktif.
  onError: (e) => {
    if (e instanceof ApiError && e.kode === 'status_tidak_sesuai') void qc.invalidateQueries({ queryKey: KUNCI_KUNJUNGAN.igd })
  },
})
watch([kunjunganId, kategori, instrumenId, skala], () => simpan.reset())
const galat = computed(() => simpan.error.value)
const errorIsian = computed(() => errorKolom(galat.value, ['kategori', 'instrumen_triase_id', 'skala', 'keluhan']))
const adaErrorKolom = computed(() => Object.keys(errorIsian.value).length > 0)
const butuhInstrumen = computed(() => daftarInstrumen.value.length > 0)
const butuhSkala = computed(() => (instrumen.value?.skala.length ?? 0) > 0)
const kurang = computed(() => {
  if (!kategori.value) return 'Pilih kategori triase.'
  if (butuhInstrumen.value && !instrumenId.value) return 'Pilih instrumen triase.'
  if (butuhSkala.value && !skala.value) return 'Pilih skala instrumen.'
  return null
})

// ── Riwayat triase pasien terpilih ──
const kueriRiwayat = useInfiniteQuery({
  queryKey: computed(() => KUNCI_TRIASE.riwayat(kunjunganId.value ?? '')),
  queryFn: ({ pageParam }) => daftarTriase(kunjunganId.value!, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
  enabled: computed(() => Boolean(kunjunganId.value) && boleh('igd.triase.lihat')),
})
const riwayat = computed<Triase[]>(() => kueriRiwayat.data.value?.pages.flatMap((h) => h.data) ?? [])
const sudahDitriase = computed(() => riwayat.value.length > 0 || Boolean(terpilih.value?.triase))
</script>

<template>
  <HalamanModul
    izin="igd.triase.lihat"
    judul="Triase"
    uraian="Catat kategori triase pasien IGD. Ulang triase menambah catatan baru; catatan lama tetap tersimpan dan yang terbaru berlaku."
  >
    <div class="tata">
      <section class="kartu" aria-labelledby="judul-pasien-igd">
        <div class="kartu-kepala">
          <div>
            <h2 id="judul-pasien-igd">Pasien di IGD</h2>
            <p>Pilih pasien untuk triase. Urut jam tiba terlama; diperbarui otomatis tiap 15 detik.</p>
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
          label="Tabel pasien di IGD untuk triase"
          dapat-dipilih
          :terpilih="kunjunganId"
          :memuat="kueriIgd.isPending.value"
          :memuat-lagi="kueriIgd.isFetchingNextPage.value"
          :error="kueriIgd.isError.value ? pesanError(kueriIgd.error.value) : null"
          :ada-lagi="kueriIgd.hasNextPage.value"
          teks-kosong="Belum ada pasien di IGD."
          @pilih="(k: Kunjungan) => (kunjunganId = k.id)"
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

      <section class="kartu" aria-labelledby="judul-triase-pasien">
        <div class="kartu-kepala">
          <div>
            <h2 id="judul-triase-pasien">{{ terpilih ? `Triase ${terpilih.pasien.nama}` : 'Triase pasien' }}</h2>
            <p v-if="terpilih">
              No. RM {{ formatNoRm(terpilih.pasien.no_rm) }} · {{ terpilih.nomor }} · tiba {{ terpilih.tiba_pada ? jamWib(terpilih.tiba_pada) : '-' }}
            </p>
          </div>
        </div>

        <KeadaanKosong v-if="!kunjunganId" judul="Belum ada pasien dipilih" uraian="Pilih pasien dari daftar Pasien di IGD." />
        <div v-else-if="!terpilih" class="kartu-isi">
          <Pesan jenis="info">Pasien ini tidak lagi tercatat di IGD (selesai atau dibatalkan). Pilih pasien lain.</Pesan>
        </div>
        <template v-else>
          <form v-if="boleh('igd.triase.input')" class="formulir" @submit.prevent="kurang === null && simpan.mutate()">
            <fieldset class="kategori" :aria-describedby="errorIsian.kategori ? 'galat-kategori' : undefined">
              <legend class="kolom-label">Kategori <span class="kolom-wajib" aria-hidden="true">*</span></legend>
              <div class="pilihan-kategori">
                <label v-for="k in KATEGORI_TRIASE" :key="k" class="pilihan" :class="[`pilihan-${k}`, { dipilih: kategori === k }]">
                  <input v-model="kategori" type="radio" name="kategori-triase" :value="k" />
                  <span class="tanda" aria-hidden="true"><Ikon :nama="IKON_KATEGORI[k]" :ukuran="16" /></span>
                  <span>{{ LABEL_KATEGORI[k] }}</span>
                </label>
              </div>
              <p v-if="errorIsian.kategori" id="galat-kategori" class="galat">{{ errorIsian.kategori }}</p>
            </fieldset>

            <div class="baris-isian">
              <KolomIsian
                v-if="butuhInstrumen || kueriInstrumen.isError.value"
                v-slot="a"
                label="Instrumen triase"
                wajib
                :error="errorIsian.instrumen_triase_id ?? (kueriInstrumen.isError.value ? `Instrumen tidak dapat dimuat: ${pesanError(kueriInstrumen.error.value)}` : undefined)"
              >
                <select v-bind="a" v-model="instrumenId" class="isian">
                  <option value="" disabled>Pilih instrumen…</option>
                  <option v-for="i in daftarInstrumen" :key="i.id" :value="i.id">{{ i.nama }}</option>
                </select>
              </KolomIsian>
              <KolomIsian v-if="butuhSkala" v-slot="a" label="Skala" wajib :error="errorIsian.skala">
                <select v-bind="a" v-model="skala" class="isian">
                  <option value="" disabled>Pilih skala…</option>
                  <option v-for="s in instrumen?.skala ?? []" :key="s.kode" :value="s.kode">{{ s.label }}</option>
                </select>
              </KolomIsian>
              <KolomIsian v-slot="a" label="Keluhan saat triase (opsional)" petunjuk="Maksimal 1.000 karakter." :error="errorIsian.keluhan">
                <textarea v-bind="a" v-model="keluhan" class="isian" rows="2" maxlength="1000"></textarea>
              </KolomIsian>
            </div>
            <p v-if="!butuhInstrumen && kueriInstrumen.isSuccess.value" class="tafsir">RS belum menetapkan instrumen triase; triase dicatat dengan kategori saja.</p>

            <div v-if="galat && !adaErrorKolom" class="blok">
              <Pesan jenis="error">{{ pesanError(galat) }}</Pesan>
            </div>
            <div class="aksi-kirim">
              <p v-if="kurang" class="tafsir">{{ kurang }}</p>
              <Tombol type="submit" varian="utama" :disabled="kurang !== null || undefined" :sibuk="simpan.isPending.value">
                {{ sudahDitriase ? 'Simpan ulang triase' : 'Simpan triase' }}
              </Tombol>
            </div>
          </form>

          <div class="riwayat">
            <h3 class="blok-judul">Riwayat triase</h3>
            <p v-if="kueriRiwayat.isPending.value" class="tafsir">Memuat riwayat…</p>
            <Pesan v-else-if="kueriRiwayat.isError.value" jenis="error">{{ pesanError(kueriRiwayat.error.value) }}</Pesan>
            <p v-else-if="!riwayat.length" class="tafsir">Pasien ini belum ditriase.</p>
            <ol v-else class="daftar-riwayat" aria-label="Riwayat triase, terbaru di atas">
              <li v-for="t in riwayat" :key="t.id" :class="{ berlaku: t.berlaku }">
                <div class="riwayat-kepala">
                  <LencanaTriase :kategori="t.kategori" />
                  <Lencana v-if="t.berlaku" jenis="info">Berlaku</Lencana>
                  <span class="ket-baris angka">{{ waktuTriase(t.ditriase_pada, sekarang) }}</span>
                </div>
                <p v-if="t.instrumen" class="ket-baris">{{ t.instrumen.nama }}<template v-if="t.skala">: {{ t.skala.label }}</template></p>
                <p v-if="t.keluhan" class="ket-baris">Keluhan: {{ t.keluhan }}</p>
                <p class="ket-baris">Oleh {{ t.petugas?.nama ?? 'sistem' }}</p>
              </li>
            </ol>
            <Tombol v-if="kueriRiwayat.hasNextPage.value" :sibuk="kueriRiwayat.isFetchingNextPage.value" @click="kueriRiwayat.fetchNextPage()">Muat lagi</Tombol>
          </div>
        </template>
      </section>
    </div>
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
.kategori {
  margin: 0;
  padding: var(--space-3) var(--space-4) 0;
  border: 0;
}
.pilihan-kategori {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
  gap: var(--space-2);
  margin-top: var(--space-2);
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
.tanda {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 26px;
  height: 26px;
  border-radius: 999px;
  color: var(--surface);
}
.pilihan-merah .tanda {
  background: var(--triase-merah);
}
.pilihan-kuning .tanda {
  background: var(--triase-kuning);
  color: var(--text);
}
.pilihan-hijau .tanda {
  background: var(--triase-hijau);
}
.pilihan-hitam .tanda {
  background: var(--triase-hitam);
}
.galat {
  margin: var(--space-1) 0 0;
  font-size: 13px;
  color: var(--danger);
}
.baris-isian {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
  padding: 0 var(--space-4);
  font-size: 13px;
  color: var(--muted);
}
.aksi-kirim .tafsir,
.riwayat .tafsir {
  padding: 0;
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
.daftar-riwayat li.berlaku {
  border-color: var(--border-input);
  background: var(--bg);
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
}
.riwayat-kepala .ket-baris {
  margin: 0;
}
</style>
