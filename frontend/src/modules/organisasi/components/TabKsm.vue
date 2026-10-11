<script setup lang="ts">
import { useInfiniteQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { boleh } from '@/shared/composables/usePermission'
import { refDebounced } from '@/shared/composables/refDebounced'
import { formatTanggalWaktu } from '@/shared/utils/waktu'
import { ambilKsm, daftarKsm, type Berversi } from '../api'
import { KUNCI_ORG } from '../organisasi'
import type { Ksm } from '../tipe'
import DialogKsm from './DialogKsm.vue'
import DialogStatus from './DialogStatus.vue'

/** Daftar KSM (GET /ksm berkursor, cari, status) dengan tambah, ubah, nonaktifkan. KSM data seluruh RS. */
const qc = useQueryClient()
const cari = ref('')
const cariTunda = refDebounced(cari, 300)
const status = ref<'aktif' | 'nonaktif' | ''>('aktif')
const filter = computed(() => ({ cari: cariTunda.value.trim() || undefined, status: status.value || undefined }))

const kueri = useInfiniteQuery({
  queryKey: computed(() => KUNCI_ORG.ksm(filter.value)),
  queryFn: ({ pageParam }) => daftarKsm(filter.value, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
})
const baris = computed(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])

const kolom = computed<KolomTabel[]>(() => [
  { kunci: 'kode', label: 'Kode', lebar: '150px' },
  { kunci: 'nama', label: 'Nama KSM' },
  { kunci: 'ketua', label: 'Ketua', prioritas: 3 },
  { kunci: 'status', label: 'Status', lebar: '110px' },
  { kunci: 'diubah_pada', label: 'Terakhir diubah', prioritas: 2, lebar: '190px' },
  ...(boleh('master_data.organisasi.perbaiki') ? [{ kunci: 'aksi', label: 'Aksi', rata: 'kanan' as const, lebar: '200px' }] : []),
])

const dialogKsm = ref(false)
const dialogStatus = ref(false)
const target = ref<Berversi<Ksm> | null>(null)
const memuatId = ref<string | null>(null)
const errorAksi = ref<string | null>(null)

function tambah() {
  target.value = null
  dialogKsm.value = true
}
/** ETag dari GET /ksm/{id}: daftar tidak membawa header versi per baris. */
async function buka(k: Ksm, aksi: 'ubah' | 'status') {
  memuatId.value = k.id
  errorAksi.value = null
  try {
    target.value = await ambilKsm(k.id)
    if (aksi === 'ubah') dialogKsm.value = true
    else dialogStatus.value = true
  } catch (e) {
    errorAksi.value = pesanError(e)
  } finally {
    memuatId.value = null
  }
}
function selesai() {
  void qc.invalidateQueries({ queryKey: KUNCI_ORG.semuaKsm })
}
</script>

<template>
  <section class="kartu" aria-labelledby="judul-ksm">
    <div class="kartu-kepala">
      <div>
        <h2 id="judul-ksm">Kelompok Staf Medis (KSM)</h2>
        <p>Dipakai untuk cakupan DPJP, restriksi formularium, dan eskalasi ke ketua KSM.</p>
      </div>
      <button v-can="'master_data.organisasi.input'" type="button" class="tombol" @click="tambah"><Ikon nama="tambah" :ukuran="16" />Tambah KSM</button>
    </div>
    <div class="filter">
      <label class="sr-only" for="cari-ksm">Cari KSM</label>
      <input id="cari-ksm" v-model="cari" type="search" class="isian" placeholder="Cari kode atau nama" />
      <label class="sr-only" for="status-ksm">Status KSM</label>
      <select id="status-ksm" v-model="status" class="isian">
        <option value="aktif">Aktif</option>
        <option value="nonaktif">Nonaktif</option>
        <option value="">Semua status</option>
      </select>
    </div>
    <p v-if="errorAksi" class="catatan catatan-bahaya galat" role="alert">
      <Ikon nama="peringatan" :ukuran="16" />
      <span>{{ errorAksi }}</span>
    </p>
    <DataTable
      :kolom="kolom"
      :baris="baris"
      :kunci-baris="(k: Ksm) => k.id"
      label="Daftar KSM"
      :memuat="kueri.isPending.value"
      :memuat-lagi="kueri.isFetchingNextPage.value"
      :error="kueri.isError.value ? pesanError(kueri.error.value) : null"
      :ada-lagi="kueri.hasNextPage.value"
      teks-kosong="Belum ada KSM yang cocok."
      @muat-lagi="kueri.fetchNextPage()"
      @coba-lagi="kueri.refetch()"
    >
      <template #sel-kode="{ baris: k }"><code>{{ k.kode }}</code></template>
      <template #sel-ketua="{ baris: k }">{{ k.ketua?.nama ?? '—' }}</template>
      <template #sel-status="{ baris: k }">
        <span class="pil" :class="k.status === 'aktif' ? 'pil-ok' : 'pil-netral'">{{ k.status === 'aktif' ? 'Aktif' : 'Nonaktif' }}</span>
      </template>
      <template #sel-diubah_pada="{ baris: k }">{{ formatTanggalWaktu(k.diubah_pada) }}</template>
      <template #sel-aksi="{ baris: k }">
        <span class="aksi">
          <button type="button" class="tombol" :disabled="memuatId === k.id" :aria-label="`Ubah ${k.nama}`" @click="buka(k, 'ubah')">Ubah</button>
          <button type="button" class="tombol" :disabled="memuatId === k.id" :aria-label="`${k.status === 'aktif' ? 'Nonaktifkan' : 'Aktifkan'} ${k.nama}`" @click="buka(k, 'status')">
            {{ k.status === 'aktif' ? 'Nonaktifkan' : 'Aktifkan' }}
          </button>
        </span>
      </template>
    </DataTable>

    <DialogKsm v-model:open="dialogKsm" :ksm="target" @selesai="selesai" @muat-ulang="selesai" />
    <DialogStatus v-model:open="dialogStatus" jenis="ksm" :target="target" @selesai="selesai" @muat-ulang="selesai" />
  </section>
</template>

<style scoped>
.filter {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 160px;
  gap: var(--space-2);
  padding: var(--space-3) var(--space-4);
}
.galat {
  margin: 0 var(--space-4) var(--space-3);
}
.aksi {
  display: inline-flex;
  gap: var(--space-2);
}
@media (max-width: 599.98px) {
  .filter {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
