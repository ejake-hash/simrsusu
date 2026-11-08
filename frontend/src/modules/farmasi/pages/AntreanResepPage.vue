<script setup lang="ts">
import { useInfiniteQuery, useQuery } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import KeadaanKosong from '@/design-system/components/KeadaanKosong.vue'
import Lencana from '@/design-system/components/Lencana.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import HalamanModul from '@/layouts/HalamanModul.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { LABEL_STATUS, NADA_STATUS } from '@/modules/antrean/antrean'
import { hariIniLokal } from '@/modules/jadwal-praktik/jadwal'
import { formatNoRm, formatTanggal } from '@/modules/pendaftaran/pasien'
import { LABEL_PERINGATAN_RESEP } from '@/modules/rawat-jalan/rawatJalan'
import { daftarDepo, daftarResepDepo, ringkasItemResep, type Resep } from '@/shared/api/resep'
import { formatTanggalWaktu } from '@/shared/utils/waktu'

/**
 * Farmasi → Antrean & telaah resep (tiket 2.17; FR-22-01, FR-02-10): resep elektronik sah satu depo per hari, terlama dulu
 * (urutan antrean farmasi). Hanya membaca; telaah, penyiapan, dan penyerahan menyusul Tahap C. Cakupan depo dipaksa server.
 */
const tanggal = ref(hariIniLokal())
const depoId = ref('')
const status = ref<'sah' | 'batal'>('sah')
const depo = useQuery({ queryKey: ['farmasi', 'depo'], queryFn: daftarDepo, staleTime: 300_000 })
watch(() => depo.data.value, (d) => d?.length === 1 && !depoId.value && (depoId.value = d[0]!.id), { immediate: true })

const filter = computed(() => ({ depo_id: depoId.value, tanggal: tanggal.value, status: status.value }))
const kueri = useInfiniteQuery({
  queryKey: computed(() => ['farmasi', 'resep', filter.value]),
  queryFn: ({ pageParam }) => daftarResepDepo(filter.value, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
  enabled: computed(() => Boolean(depoId.value && tanggal.value)),
  // Resep masuk saat dokter mengesahkan; segarkan pelan agar tidak membebani batas laju akun.
  refetchInterval: 15_000,
})
const baris = computed<Resep[]>(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])

const kolom: KolomTabel[] = [
  { kunci: 'antrean', label: 'Antrean', lebar: '120px' },
  { kunci: 'pasien', label: 'Pasien' },
  { kunci: 'asal', label: 'Poli dan dokter', prioritas: 2 },
  { kunci: 'obat', label: 'Obat' },
  { kunci: 'peringatan', label: 'Peringatan', lebar: '180px' },
  { kunci: 'sah', label: 'Disahkan', lebar: '150px', prioritas: 2 },
]
</script>

<template>
  <HalamanModul izin="farmasi.resep.lihat" judul="Antrean resep" uraian="Resep elektronik yang sudah disahkan dokter, urut waktu masuk antrean farmasi. Telaah dan penyerahan obat menyusul.">
    <section class="kartu" aria-labelledby="judul-resep-depo">
      <div class="kartu-kepala">
        <h2 id="judul-resep-depo">Resep masuk</h2>
      </div>
      <div class="baris-filter">
        <KolomIsian v-slot="a" label="Depo" wajib :error="depo.isError.value ? `Daftar depo tidak dapat dimuat: ${pesanError(depo.error.value)}` : undefined">
          <select v-bind="a" v-model="depoId" class="isian">
            <option value="" disabled>Pilih depo…</option>
            <option v-for="d in depo.data.value ?? []" :key="d.id" :value="d.id">{{ d.nama }}</option>
          </select>
        </KolomIsian>
        <KolomIsian v-slot="a" label="Tanggal" wajib>
          <input v-bind="a" v-model="tanggal" type="date" class="isian" />
        </KolomIsian>
        <KolomIsian v-slot="a" label="Status resep">
          <select v-bind="a" v-model="status" class="isian">
            <option value="sah">Sah</option>
            <option value="batal">Dibatalkan sesudah sah</option>
          </select>
        </KolomIsian>
      </div>

      <KeadaanKosong v-if="!depoId" judul="Pilih depo dulu" uraian="Resep muncul setelah depo dan tanggal dipilih." />
      <DataTable
        v-else
        :kolom="kolom"
        :baris="baris"
        :kunci-baris="(r: Resep) => r.id"
        label="Antrean resep"
        :memuat="kueri.isPending.value"
        :memuat-lagi="kueri.isFetchingNextPage.value"
        :error="kueri.isError.value ? pesanError(kueri.error.value) : null"
        :ada-lagi="kueri.hasNextPage.value"
        teks-kosong="Belum ada resep pada depo, tanggal, dan status ini. Resep muncul setelah dokter mengesahkannya."
        @muat-lagi="kueri.fetchNextPage()"
        @coba-lagi="kueri.refetch()"
      >
        <template #sel-antrean="{ baris: r }">
          <code v-if="r.antrean">{{ r.antrean.nomor_tampil }}</code><span v-else class="ket">Tanpa antrean</span>
          <span class="tanda">
            <Lencana v-if="r.antrean" :jenis="NADA_STATUS[r.antrean.status]">{{ LABEL_STATUS[r.antrean.status] }}</Lencana>
            <Lencana v-if="r.racikan" jenis="info">Racikan</Lencana>
          </span>
        </template>
        <template #sel-pasien="{ baris: r }">
          <b>{{ r.pasien.nama }}</b>
          <span class="ket">No. RM {{ formatNoRm(r.pasien.no_rm) }} · {{ formatTanggal(r.pasien.tanggal_lahir) }}</span>
        </template>
        <template #sel-asal="{ baris: r }">
          {{ r.poliklinik.nama }}<span class="ket">{{ r.dokter?.nama ?? r.penulis?.nama }}</span>
        </template>
        <template #sel-obat="{ baris: r }">
          <span class="ket">{{ r.no_resep }}</span>
          <ul class="obat">
            <li v-for="i in r.item" :key="i.id">{{ ringkasItemResep(i) }}</li>
          </ul>
          <span v-if="r.status === 'batal'" class="ket">Alasan batal: {{ r.alasan_batal }}</span>
        </template>
        <template #sel-peringatan="{ baris: r }">
          <span v-if="!r.peringatan.length" class="ket">Tidak ada</span>
          <ul v-else class="obat">
            <li v-for="p in r.peringatan" :key="p.kunci"><Lencana :jenis="p.jenis === 'alergi' ? 'bahaya' : 'peringatan'">{{ LABEL_PERINGATAN_RESEP[p.jenis] }}</Lencana> <span class="ket">{{ p.tanggapan ? `Alasan dokter: ${p.tanggapan.alasan}` : p.pesan }}</span></li>
          </ul>
        </template>
        <template #sel-sah="{ baris: r }">{{ r.disahkan_pada ? formatTanggalWaktu(r.disahkan_pada) : '-' }}</template>
      </DataTable>
    </section>
  </HalamanModul>
</template>

<style scoped>
.baris-filter {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
}
.ket {
  display: block;
  font-size: 12px;
  color: var(--muted);
}
.tanda {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-1);
  margin-top: var(--space-1);
}
.obat {
  display: grid;
  gap: var(--space-1);
  margin: 0;
  padding-left: var(--space-4);
}
</style>
