<script setup lang="ts">
import { useInfiniteQuery } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { refDebounced } from '@/shared/composables/refDebounced'
import { daftarPegawai } from '../api'
import { KUNCI_SDM, LABEL_PROFESI, LABEL_STATUS_IZIN, LABEL_STATUS_PEGAWAI, PIL_PROFESI, PIL_STATUS_IZIN, PIL_STATUS_PEGAWAI } from '../sdm'
import type { FilterPegawai, PegawaiRingkas, Profesi, StatusPegawai } from '../tipe'

/**
 * Daftar pegawai (tiket 1.8d fe 1): cari nama/NIP, filter status dan profesi, "Muat lagi" berkursor. Urutan dari
 * server (terbaru dicatat dulu): kursor di query string tidak boleh memuat data pribadi, jadi tidak diurut nama, dan
 * klien tidak mengurutkan ulang antarhalaman. NIK hanya tersamar; tidak bisa dicari.
 */
const terpilih = defineModel<string | null>({ required: true })
const emit = defineEmits<{ tambah: [] }>()

const cari = ref('')
const cariTunda = refDebounced(cari, 300)
const status = ref<StatusPegawai | ''>('')
const profesi = ref<Profesi | ''>('')
const filter = computed<FilterPegawai>(() => ({ cari: cariTunda.value.trim() || undefined, status: status.value || undefined, profesi: profesi.value || undefined }))

const kueri = useInfiniteQuery({
  queryKey: computed(() => KUNCI_SDM.daftarPegawai(filter.value)),
  queryFn: ({ pageParam }) => daftarPegawai(filter.value, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
})
const baris = computed(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])

const KOLOM: KolomTabel[] = [
  { kunci: 'nama', label: 'Pegawai' },
  { kunci: 'unit', label: 'Unit', prioritas: 3 },
  { kunci: 'izin', label: 'Izin praktik', prioritas: 2, lebar: '130px' },
  { kunci: 'akun', label: 'Akun', prioritas: 3, lebar: '120px' },
  { kunci: 'status', label: 'Status', lebar: '110px' },
]
</script>

<template>
  <section class="kartu daftar" aria-labelledby="judul-daftar-pegawai">
    <div class="kartu-kepala">
      <div>
        <h2 id="judul-daftar-pegawai">Data pegawai</h2>
        <p>Satu data untuk izin praktik, kewenangan klinis, dan akun aplikasi. Urut: terbaru dicatat.</p>
      </div>
      <button v-can="'sdm.pegawai.input'" type="button" class="tombol" @click="emit('tambah')"><Ikon nama="tambah" :ukuran="16" />Tambah pegawai</button>
    </div>
    <div class="filter">
      <label>
        <span class="sr-only">Cari pegawai</span>
        <input v-model="cari" type="search" class="isian" placeholder="Cari nama atau NIP" maxlength="100" />
      </label>
      <label>
        <span class="sr-only">Status pegawai</span>
        <select v-model="status" class="isian">
          <option value="">Semua status</option>
          <option v-for="(label, nilai) in LABEL_STATUS_PEGAWAI" :key="nilai" :value="nilai">{{ label }}</option>
        </select>
      </label>
      <label>
        <span class="sr-only">Profesi</span>
        <select v-model="profesi" class="isian">
          <option value="">Semua profesi</option>
          <option v-for="[nilai, label] in PIL_PROFESI" :key="nilai" :value="nilai">{{ label }}</option>
        </select>
      </label>
    </div>
    <DataTable
      :kolom="KOLOM"
      :baris="baris"
      :kunci-baris="(p: PegawaiRingkas) => p.id"
      label="Daftar pegawai"
      dapat-dipilih
      :terpilih="terpilih"
      :memuat="kueri.isPending.value"
      :memuat-lagi="kueri.isFetchingNextPage.value"
      :error="kueri.isError.value ? pesanError(kueri.error.value) : null"
      :ada-lagi="kueri.hasNextPage.value"
      teks-kosong="Tidak ada pegawai yang cocok dengan filter."
      @pilih="(p: PegawaiRingkas) => (terpilih = p.id)"
      @muat-lagi="kueri.fetchNextPage()"
      @coba-lagi="kueri.refetch()"
    >
      <template #sel-nama="{ baris: p }">
        <b>{{ p.nama }}</b>
        <span class="ket">{{ [p.nip, p.jabatan ?? (p.profesi ? LABEL_PROFESI[p.profesi as Profesi] : null)].filter(Boolean).join(' · ') || '—' }}</span>
      </template>
      <template #sel-unit="{ baris: p }">{{ p.unit?.nama ?? '—' }}</template>
      <template #sel-izin="{ baris: p }">
        <span v-if="p.izin_praktik_status" class="pil" :class="PIL_STATUS_IZIN[p.izin_praktik_status as keyof typeof PIL_STATUS_IZIN]">
          {{ LABEL_STATUS_IZIN[p.izin_praktik_status as keyof typeof LABEL_STATUS_IZIN] }}
        </span>
        <span v-else class="ket">—</span>
      </template>
      <template #sel-akun="{ baris: p }">
        <span v-if="p.akun" class="pil" :class="p.akun.status === 'aktif' ? 'pil-ok' : 'pil-netral'">{{ p.akun.status === 'aktif' ? 'Aktif' : 'Nonaktif' }}</span>
        <span v-else-if="p.punya_akun" class="ket">Punya akun</span>
        <span v-else class="ket">Belum ada</span>
      </template>
      <template #sel-status="{ baris: p }">
        <span class="pil" :class="PIL_STATUS_PEGAWAI[p.status as StatusPegawai]">{{ LABEL_STATUS_PEGAWAI[p.status as StatusPegawai] }}</span>
      </template>
    </DataTable>
  </section>
</template>

<style scoped>
.daftar {
  min-width: 0;
}
.filter {
  display: grid;
  grid-template-columns: minmax(180px, 2fr) repeat(2, minmax(130px, 1fr));
  gap: var(--space-2);
  padding: var(--space-3) var(--space-4);
}
.ket {
  display: block;
  color: var(--muted);
  font-size: 12px;
}
@media (max-width: 599.98px) {
  .filter {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
