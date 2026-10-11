<script setup lang="ts">
import { useQueries, useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PohonData from '@/design-system/components/PohonData.vue'
import type { SimpulPohon } from '@/design-system/components/pohon'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { refDebounced } from '@/shared/composables/refDebounced'
import { daftarUnit, semuaAnakUnit, type Berversi } from '../api'
import { KUNCI_ORG, LABEL_JENIS, teksJalur } from '../organisasi'
import type { UnitOrganisasi } from '../tipe'
import DialogStatus from './DialogStatus.vue'
import DialogUnit from './DialogUnit.vue'
import RincianUnit from './RincianUnit.vue'

/**
 * Pohon unit organisasi (muat-lambat per induk, GET /unit-organisasi?akar / ?induk_id) dan rincian unit terpilih
 * (?unit= di URL agar bertahan saat muat ulang). Akun ber-cakupan unit hanya melihat subpohonnya (dipaksa server).
 * Cari kode/nama menampilkan daftar datar dengan jalur induk; memilih hasil membuka cabangnya di pohon.
 */
const route = useRoute()
const router = useRouter()
const qc = useQueryClient()

const terpilih = computed(() => (typeof route.query.unit === 'string' ? route.query.unit : null))
const terbuka = ref<string[]>([])

function pilih(id: string | null) {
  if (id === terpilih.value) return
  void router.replace({ query: { ...route.query, unit: id ?? undefined } })
}

// Anak per induk yang terbuka; akar selalu dimuat. Invalidasi KUNCI_ORG.semuaUnit memuat ulang yang terlihat.
const kueriAnak = useQueries({
  queries: computed(() =>
    [null, ...terbuka.value].map((induk) => ({
      queryKey: KUNCI_ORG.unitAnak(induk),
      queryFn: () => semuaAnakUnit(induk),
      staleTime: 60_000,
    })),
  ),
})
const anakPer = computed(() => {
  const peta = new Map<string, { data?: UnitOrganisasi[]; memuat: boolean; gagal: boolean }>()
  ;[null, ...terbuka.value].forEach((induk, i) => {
    const q = kueriAnak.value[i]
    peta.set(induk ?? '', { data: q?.data, memuat: !!q?.isPending, gagal: !!q?.isError })
  })
  return peta
})
const akar = computed(() => anakPer.value.get(''))

function keSimpul(u: UnitOrganisasi): SimpulPohon {
  const anak = anakPer.value.get(u.id)
  return {
    id: u.id,
    label: u.nama,
    keterangan: u.status === 'nonaktif' ? `${u.kode} · nonaktif` : u.kode,
    adaAnak: u.jumlah_anak > 0,
    memuat: anak?.memuat,
    anak: anak?.data?.map(keSimpul),
  }
}
const simpul = computed(() => akar.value?.data?.map(keSimpul) ?? [])
const indeksUnit = computed(() => {
  const peta = new Map<string, UnitOrganisasi>()
  for (const v of anakPer.value.values()) v.data?.forEach((u) => peta.set(u.id, u))
  return peta
})
const gagalAnak = computed(() => [...anakPer.value.entries()].some(([k, v]) => k !== '' && v.gagal))

// ── Cari ──
const cari = ref('')
const cariTunda = refDebounced(cari, 300)
const hasilCari = useQuery({
  queryKey: computed(() => KUNCI_ORG.cariUnit(`pohon:${cariTunda.value.trim()}`)),
  queryFn: async () => (await daftarUnit({ cari: cariTunda.value.trim() }, null, 50)).data,
  enabled: computed(() => cariTunda.value.trim().length > 0),
})
const modeCari = computed(() => cari.value.trim().length > 0)
function bukaJalur(u: Pick<UnitOrganisasi, 'jalur'>) {
  terbuka.value = [...new Set([...terbuka.value, ...u.jalur.map((j) => j.id)])]
}
function pilihHasil(u: UnitOrganisasi) {
  bukaJalur(u)
  cari.value = ''
  pilih(u.id)
}

// ── Rincian dan dialog ──
const rincian = ref<InstanceType<typeof RincianUnit> | null>(null)
const dialogUnit = ref(false)
const modeDialog = ref<'buat' | 'ubah'>('buat')
const indukBaru = ref<UnitOrganisasi | null>(null)
const unitDiubah = ref<Berversi<UnitOrganisasi> | null>(null)
const dialogStatus = ref(false)

function tambah(induk: UnitOrganisasi | null) {
  modeDialog.value = 'buat'
  indukBaru.value = induk
  unitDiubah.value = null
  dialogUnit.value = true
}
function ubah(u: Berversi<UnitOrganisasi>) {
  modeDialog.value = 'ubah'
  unitDiubah.value = u
  dialogUnit.value = true
}
function ubahStatus(u: Berversi<UnitOrganisasi>) {
  unitDiubah.value = u
  dialogStatus.value = true
}

// Unit yang dibuka dari URL: buka cabangnya sekali agar terlihat di pohon.
watch(
  () => rincian.value?.unit?.data,
  (u) => {
    if (u) bukaJalur(u)
  },
)

async function selesai(hasil: Berversi<UnitOrganisasi>) {
  qc.setQueryData(KUNCI_ORG.unit(hasil.data.id), hasil)
  if (hasil.data.induk_id) bukaJalur({ jalur: [...hasil.data.jalur] })
  await qc.invalidateQueries({ queryKey: KUNCI_ORG.semuaUnit })
  pilih(hasil.data.id)
}
function muatUlang() {
  void qc.invalidateQueries({ queryKey: KUNCI_ORG.semuaUnit })
}
</script>

<template>
  <div class="tata">
    <section class="kartu pohon-kartu" aria-labelledby="judul-pohon-unit">
      <div class="kartu-kepala">
        <h2 id="judul-pohon-unit">Struktur unit</h2>
        <button v-can="'master_data.organisasi.input'" type="button" class="tombol" @click="tambah(null)"><Ikon nama="tambah" :ukuran="16" />Unit teratas</button>
      </div>
      <div class="filter">
        <label class="sr-only" for="cari-unit">Cari unit</label>
        <input id="cari-unit" v-model="cari" type="search" class="isian" placeholder="Cari kode atau nama unit" />
      </div>

      <div class="isi-pohon">
        <template v-if="modeCari">
          <p v-if="hasilCari.isFetching.value && !hasilCari.data.value" class="status" role="status">Mencari…</p>
          <p v-else-if="hasilCari.isError.value" class="catatan catatan-bahaya" role="alert">{{ pesanError(hasilCari.error.value) }}</p>
          <p v-else-if="hasilCari.data.value && !hasilCari.data.value.length" class="status" role="status">Tidak ada unit yang cocok.</p>
          <ul v-else class="hasil-cari" aria-label="Hasil pencarian unit">
            <li v-for="u in hasilCari.data.value ?? []" :key="u.id">
              <button type="button" class="hasil" :aria-current="u.id === terpilih ? 'true' : undefined" @click="pilihHasil(u)">
                <span class="nama">{{ u.nama }}<span v-if="u.status === 'nonaktif'" class="pil pil-netral">Nonaktif</span></span>
                <span class="ket"><code>{{ u.kode }}</code> · {{ LABEL_JENIS[u.jenis] }}<template v-if="u.jalur.length"> · {{ teksJalur(u) }}</template></span>
              </button>
            </li>
          </ul>
        </template>
        <template v-else>
          <p v-if="akar?.memuat" class="status" role="status">Memuat struktur unit…</p>
          <div v-else-if="akar?.gagal" role="alert">
            <p class="catatan catatan-bahaya">{{ pesanError(kueriAnak[0]?.error) }}</p>
            <button type="button" class="tombol" @click="kueriAnak[0]?.refetch()">Coba lagi</button>
          </div>
          <p v-else-if="!simpul.length" class="status">Belum ada unit. Mulai dengan menambah unit teratas, mis. instalasi atau bidang.</p>
          <PohonData v-else v-model:terbuka="terbuka" :terpilih="terpilih" :simpul="simpul" label="Struktur unit organisasi" @update:terpilih="pilih" />
          <p v-if="gagalAnak" class="catatan catatan-bahaya" role="alert">Sebagian cabang gagal dimuat. Lipat lalu buka lagi untuk mencoba.</p>
        </template>
      </div>
    </section>

    <RincianUnit v-if="terpilih" :key="terpilih" ref="rincian" :unit-id="terpilih" :ringkas="indeksUnit.get(terpilih) ?? null" @tambah="tambah" @ubah="ubah" @ubah-status="ubahStatus" @tutup="pilih(null)" />
    <section v-else class="kartu kosong">
      <h2>Pilih unit</h2>
      <p>Pilih unit di pohon untuk melihat rinciannya, menambah sub-unit, atau mengubahnya.</p>
    </section>

    <DialogUnit v-model:open="dialogUnit" :mode="modeDialog" :unit="unitDiubah" :induk="indukBaru" @selesai="selesai" @muat-ulang="muatUlang" />
    <DialogStatus v-model:open="dialogStatus" jenis="unit" :target="unitDiubah" @selesai="(h) => selesai(h as Berversi<UnitOrganisasi>)" @muat-ulang="muatUlang" />
  </div>
</template>

<style scoped>
.tata {
  display: grid;
  grid-template-columns: minmax(300px, 380px) minmax(0, 1fr);
  align-items: start;
  gap: var(--space-4);
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
