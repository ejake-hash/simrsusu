<script setup lang="ts">
import { useQueries, useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, nextTick, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PohonData from '@/design-system/components/PohonData.vue'
import type { SimpulPohon } from '@/design-system/components/pohon'
import { tampilkanToast } from '@/design-system/components/toast'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { refDebounced } from '@/shared/composables/refDebounced'
import type { Berversi } from '../../api'
import { daftarLokasi, semuaAnakLokasi } from '../../apiLokasi'
import { KUNCI_LOKASI, LABEL_JENIS_LOKASI, LABEL_STATUS_LOKASI, urutAlami } from '../../lokasi'
import type { HasilImporLokasi, Lokasi } from '../../tipeLokasi'
import DialogImpor from './DialogImpor.vue'
import DialogLokasi from './DialogLokasi.vue'
import DialogStatusLokasi from './DialogStatusLokasi.vue'
import RincianLokasi from './RincianLokasi.vue'

/**
 * Pohon lokasi fisik (situs → gedung → lantai → ruang → TT; muat-lambat per induk) dan panel rinci lokasi terpilih
 * (?lokasi= di URL). Cari kode lengkap, nama, atau kode TT menampilkan daftar datar dengan jalur; memilih hasil
 * membuka cabangnya. Saudara diurutkan alami (lantai 2 sebelum 10). Impor CSV dari tombol di kepala pohon.
 */
const route = useRoute()
const router = useRouter()
const qc = useQueryClient()

const terpilih = computed(() => (typeof route.query.lokasi === 'string' ? route.query.lokasi : null))
const terbuka = ref<string[]>([])
function pilih(id: string | null) {
  if (id === terpilih.value) return
  void router.replace({ query: { ...route.query, lokasi: id ?? undefined } })
}

const kueriAnak = useQueries({
  queries: computed(() =>
    [null, ...terbuka.value].map((induk) => ({
      queryKey: KUNCI_LOKASI.anak(induk),
      queryFn: async () => (await semuaAnakLokasi(induk)).sort(urutAlami),
      staleTime: 60_000,
    })),
  ),
})
const anakPer = computed(() => {
  const peta = new Map<string, { data?: Lokasi[]; memuat: boolean; gagal: boolean }>()
  ;[null, ...terbuka.value].forEach((induk, i) => {
    const q = kueriAnak.value[i]
    peta.set(induk ?? '', { data: q?.data, memuat: !!q?.isPending, gagal: !!q?.isError })
  })
  return peta
})
const akar = computed(() => anakPer.value.get(''))

function keSimpul(l: Lokasi): SimpulPohon {
  const anak = anakPer.value.get(l.id)
  const status = l.status === 'aktif' ? '' : ` · ${LABEL_STATUS_LOKASI[l.status].toLowerCase()}`
  return {
    id: l.id,
    label: l.nama,
    keterangan: `${l.kode_lengkap}${status}`,
    adaAnak: l.jumlah_anak > 0,
    memuat: anak?.memuat,
    anak: anak?.data?.map(keSimpul),
  }
}
const simpul = computed(() => akar.value?.data?.map(keSimpul) ?? [])
const indeks = computed(() => {
  const peta = new Map<string, Lokasi>()
  for (const v of anakPer.value.values()) v.data?.forEach((l) => peta.set(l.id, l))
  return peta
})
const gagalAnak = computed(() => [...anakPer.value.entries()].some(([k, v]) => k !== '' && v.gagal))

// ── Cari ──
const cari = ref('')
const cariTunda = refDebounced(cari, 300)
const hasilCari = useQuery({
  queryKey: computed(() => KUNCI_LOKASI.cari(`pohon:${cariTunda.value.trim()}`)),
  queryFn: async () => (await daftarLokasi({ cari: cariTunda.value.trim() }, null, 50)).data.sort(urutAlami),
  enabled: computed(() => cariTunda.value.trim().length > 0),
})
const modeCari = computed(() => cari.value.trim().length > 0)
function bukaJalur(l: Pick<Lokasi, 'jalur'>) {
  terbuka.value = [...new Set([...terbuka.value, ...l.jalur.map((j) => j.id)])]
}
function pilihHasil(l: Lokasi) {
  bukaJalur(l)
  cari.value = ''
  pilih(l.id)
}

// ── Dialog ──
const dialogLokasi = ref(false)
const modeDialog = ref<'buat' | 'ubah'>('buat')
const indukBaru = ref<Lokasi | null>(null)
const lokasiDiubah = ref<Berversi<Lokasi> | null>(null)
const dialogStatus = ref(false)
const dialogImpor = ref(false)
function tambah(induk: Lokasi | null) {
  modeDialog.value = 'buat'
  indukBaru.value = induk
  lokasiDiubah.value = null
  dialogLokasi.value = true
}
function ubah(l: Berversi<Lokasi>) {
  modeDialog.value = 'ubah'
  lokasiDiubah.value = l
  dialogLokasi.value = true
}
function ubahStatus(l: Berversi<Lokasi>) {
  lokasiDiubah.value = l
  dialogStatus.value = true
}

const rincian = ref<InstanceType<typeof RincianLokasi> | null>(null)
let jalurDibuka: string | null = null
watch(
  () => (terpilih.value ? indeks.value.get(terpilih.value) : undefined),
  (l) => {
    if (!l || jalurDibuka === l.id) return
    jalurDibuka = l.id
    bukaJalur(l)
  },
)
// Pilihan dari URL sebelum pohon termuat: ambil jalurnya lewat rincian.
watch(
  () => (terpilih.value ? qc.getQueryData<Berversi<Lokasi>>(KUNCI_LOKASI.rinci(terpilih.value))?.data : undefined),
  (l) => {
    if (l && jalurDibuka !== l.id) {
      jalurDibuka = l.id
      bukaJalur(l)
    }
  },
)
// Layar sempit: rincian di bawah pohon, jadi digulir ke tampilan saat dipilih.
watch(terpilih, async (id) => {
  if (!id || !window.matchMedia?.('(max-width: 1023.98px)').matches) return
  await nextTick()
  document.querySelector('section[aria-labelledby="judul-rincian-lokasi"]')?.scrollIntoView?.({ block: 'start', behavior: 'smooth' })
})

async function selesai(hasil: Berversi<Lokasi>) {
  const pindah = hasil.data.id !== terpilih.value
  qc.setQueryData(KUNCI_LOKASI.rinci(hasil.data.id), hasil)
  bukaJalur(hasil.data)
  pilih(hasil.data.id)
  if (pindah) {
    await nextTick()
    requestAnimationFrame(() => document.getElementById('judul-rincian-lokasi')?.focus())
  }
  await qc.invalidateQueries({ queryKey: KUNCI_LOKASI.semua })
}
function imporSelesai(h: HasilImporLokasi) {
  const p = h.per_jenis
  tampilkanToast(`Impor diterapkan: ${h.jumlah_baris} baris (${p.tempat_tidur} TT, ${p.kamar} kamar).`)
  void qc.invalidateQueries({ queryKey: KUNCI_LOKASI.semua })
  void qc.invalidateQueries({ queryKey: KUNCI_LOKASI.bangsal })
}
function muatUlang() {
  void qc.invalidateQueries({ queryKey: KUNCI_LOKASI.semua })
}
</script>

<template>
  <div class="tata">
    <section class="kartu pohon-kartu" aria-labelledby="judul-pohon-lokasi">
      <div class="kartu-kepala">
        <h2 id="judul-pohon-lokasi">Lokasi fisik</h2>
        <span class="aksi">
          <button v-can="'master_data.organisasi.input'" type="button" class="tombol" @click="dialogImpor = true">Impor CSV</button>
          <button v-can="'master_data.organisasi.input'" type="button" class="tombol" @click="tambah(null)"><Ikon nama="tambah" :ukuran="16" />Gedung/situs</button>
        </span>
      </div>
      <div class="filter">
        <label class="sr-only" for="cari-lokasi">Cari lokasi</label>
        <input id="cari-lokasi" v-model="cari" type="search" class="isian" placeholder="Cari kode lengkap, nama, atau kode TT" />
      </div>
      <div class="isi-pohon">
        <template v-if="modeCari">
          <p v-if="hasilCari.isFetching.value && !hasilCari.data.value" class="status" role="status">Mencari…</p>
          <p v-else-if="hasilCari.isError.value" class="catatan catatan-bahaya" role="alert">{{ pesanError(hasilCari.error.value) }}</p>
          <p v-else-if="hasilCari.data.value && !hasilCari.data.value.length" class="status" role="status">Tidak ada lokasi yang cocok.</p>
          <ul v-else class="hasil-cari" aria-label="Hasil pencarian lokasi">
            <li v-for="l in hasilCari.data.value ?? []" :key="l.id">
              <button type="button" class="hasil" :aria-current="l.id === terpilih ? 'true' : undefined" @click="pilihHasil(l)">
                <span class="nama">{{ l.nama }}<span v-if="l.status !== 'aktif'" class="pil pil-netral">{{ LABEL_STATUS_LOKASI[l.status] }}</span></span>
                <span class="ket"><code>{{ l.kode_lengkap }}</code> · {{ LABEL_JENIS_LOKASI[l.jenis] }}<template v-if="l.jalur.length"> · {{ l.jalur.map((j) => j.nama).join(' › ') }}</template></span>
              </button>
            </li>
          </ul>
        </template>
        <template v-else>
          <p v-if="akar?.memuat" class="status" role="status">Memuat lokasi…</p>
          <div v-else-if="akar?.gagal" role="alert">
            <p class="catatan catatan-bahaya">{{ pesanError(kueriAnak[0]?.error) }}</p>
            <button type="button" class="tombol" @click="kueriAnak[0]?.refetch()">Coba lagi</button>
          </div>
          <p v-else-if="!simpul.length" class="status">Belum ada lokasi. Mulai dengan gedung atau situs, atau impor dari CSV.</p>
          <PohonData v-else v-model:terbuka="terbuka" :terpilih="terpilih" :simpul="simpul" label="Pohon lokasi fisik" @update:terpilih="pilih" />
          <p v-if="gagalAnak" class="catatan catatan-bahaya" role="alert">Sebagian cabang gagal dimuat. Lipat lalu buka lagi untuk mencoba.</p>
        </template>
      </div>
    </section>

    <RincianLokasi v-if="terpilih" :key="terpilih" ref="rincian" :lokasi-id="terpilih" :ringkas="indeks.get(terpilih) ?? null" @tambah="tambah" @ubah="ubah" @status="ubahStatus" @tutup="pilih(null)" @berubah="muatUlang" />
    <section v-else class="kartu kosong">
      <h2>Pilih lokasi</h2>
      <p>Pilih gedung, lantai, ruang, atau tempat tidur di pohon untuk melihat rinciannya, kamar rawat, dan tempat tidurnya.</p>
    </section>

    <DialogLokasi v-model:open="dialogLokasi" :mode="modeDialog" :lokasi="lokasiDiubah" :induk="indukBaru" @selesai="selesai" @muat-ulang="muatUlang" />
    <DialogStatusLokasi v-model:open="dialogStatus" :lokasi="lokasiDiubah" @selesai="selesai" @muat-ulang="muatUlang" />
    <DialogImpor v-model:open="dialogImpor" @selesai="imporSelesai" />
  </div>
</template>

<style scoped>
.tata {
  display: grid;
  grid-template-columns: minmax(300px, 400px) minmax(0, 1fr);
  align-items: start;
  gap: var(--space-4);
}
.aksi {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}
.filter {
  padding: var(--space-3) var(--space-4);
}
.filter .isian {
  width: 100%;
}
.isi-pohon {
  max-height: 640px;
  padding: 0 var(--space-2) var(--space-3);
  overflow-y: auto;
}
.status {
  margin: 0;
  padding: var(--space-3) var(--space-2);
  color: var(--muted);
}
.hasil-cari {
  margin: 0;
  padding: 0;
  list-style: none;
}
.hasil {
  display: grid;
  gap: 2px;
  width: 100%;
  min-height: 44px;
  padding: 6px var(--space-2);
  border: 0;
  border-radius: var(--radius-input);
  background: none;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}
.hasil:hover,
.hasil[aria-current='true'] {
  background: var(--brand-50);
}
.hasil:focus-visible {
  outline: none;
  box-shadow: var(--ring);
}
.nama {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}
.ket {
  color: var(--muted);
  font-size: 12px;
}
.kosong {
  padding: var(--space-8) var(--space-6);
  text-align: center;
  color: var(--muted);
}
.kosong h2 {
  margin: 0 0 var(--space-1);
  font-size: 15px;
  color: var(--text);
}
.kosong p {
  margin: 0;
}
@media (max-width: 1023.98px) {
  .tata {
    grid-template-columns: minmax(0, 1fr);
  }
  .isi-pohon {
    max-height: 420px;
  }
}
</style>
