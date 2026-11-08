<script setup lang="ts">
import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import KeadaanKosong from '@/design-system/components/KeadaanKosong.vue'
import Lencana from '@/design-system/components/Lencana.vue'
import Pesan from '@/design-system/components/Pesan.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import HalamanModul from '@/layouts/HalamanModul.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { aksiAntrean } from '@/modules/antrean/api'
import { LABEL_STATUS, NADA_STATUS, pesanAksi } from '@/modules/antrean/antrean'
import { hariIniLokal } from '@/modules/jadwal-praktik/jadwal'
import { daftarPoliklinik } from '@/modules/organisasi/apiLokasi'
import { formatNoRm, formatTanggal } from '@/modules/pendaftaran/pasien'
import { boleh } from '@/shared/composables/usePermission'
import { daftarWorklist } from '../api'
import CpptLinimasa from '../components/CpptLinimasa.vue'
import DialogAsesmen from '../components/DialogAsesmen.vue'
import { KUNCI_WORKLIST, LABEL_ASESMEN, LABEL_TAHAP, NADA_TAHAP, lamaMenunggu } from '../rawatJalan'
import type { BarisWorklist, TahapWorklist } from '../tipe'

/**
 * Rawat Jalan → Worklist poliklinik dan Asesmen perawat (tiket 2.13, FR-07-01/02/21). Satu daftar kerja dipakai kedua sub-menu:
 * pasien satu poli pada satu tanggal, urut tunggu. Perawat melihat seluruh poli, dokter hanya pasiennya; cakupan itu dipaksa
 * server. Dari sini petugas memanggil nomor (2.8) dan membuka asesmen awal; asesmen selesai memindahkan pasien ke tahap dokter.
 * `asesmen` = sub-menu Asesmen perawat: gerbang izinnya sendiri dan tahap awal "menunggu perawat".
 * `cppt` = sub-menu CPPT episode (tiket 2.14): pasien dipilih dari daftar yang sama, linimasanya tampil di bawah daftar.
 */
const props = defineProps<{ mode: 'worklist' | 'asesmen' | 'cppt' }>()
const IZIN = { worklist: 'rawat_jalan.worklist.lihat', asesmen: 'rawat_jalan.asesmen_perawat.lihat', cppt: 'rawat_jalan.cppt.lihat' } as const
const JUDUL = { worklist: 'Worklist poliklinik', asesmen: 'Asesmen perawat', cppt: 'CPPT episode' } as const
const URAIAN = {
  worklist: 'Pasien poli hari ini menurut tahapnya. Perawat melihat seluruh poli; dokter hanya pasiennya.',
  asesmen: 'Isi asesmen awal keperawatan pasien poli. Setelah selesai, pasien menunggu dokter.',
  cppt: 'Pilih pasien poli untuk membaca dan menulis catatan perkembangan terintegrasi lintas profesi dan kunjungan.',
} as const
const qc = useQueryClient()
const tanggal = ref(hariIniLokal())
const poliId = ref('')
const tahapAwal = () => (props.mode === 'asesmen' ? 'perawat' : '')
const tahap = ref<TahapWorklist | ''>(tahapAwal())
// Kedua sub-menu memakai instans halaman yang sama (RouterView tanpa key): pindah menu menyetel ulang tahap awalnya.
watch(() => props.mode, () => (tahap.value = tahapAwal()))

const poli = useQuery({ queryKey: ['rawat-jalan', 'poli'], queryFn: async () => (await daftarPoliklinik({ status: 'aktif' }, null, 200)).data, staleTime: 60_000 })
const filter = computed(() => ({ tanggal: tanggal.value, poli_id: poliId.value, tahap: tahap.value || undefined }))
const kueri = useInfiniteQuery({
  queryKey: computed(() => KUNCI_WORKLIST.daftar(filter.value)),
  queryFn: ({ pageParam }) => daftarWorklist(filter.value, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
  enabled: computed(() => Boolean(poliId.value && tanggal.value)),
  // Pasien masuk dan berpindah tahap dari petugas lain; segarkan pelan agar tidak membebani batas laju akun.
  refetchInterval: 15_000,
})
const baris = computed<BarisWorklist[]>(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])

const sekarang = ref(Date.now())
const jam = setInterval(() => (sekarang.value = Date.now()), 30_000)
onBeforeUnmount(() => clearInterval(jam))

const kolom: KolomTabel[] = [
  { kunci: 'nomor', label: 'Nomor', lebar: '100px' },
  { kunci: 'pasien', label: 'Pasien' },
  { kunci: 'dokter', label: 'Dokter', prioritas: 2 },
  { kunci: 'tahap', label: 'Tahap', lebar: '200px' },
  { kunci: 'asesmen', label: 'Asesmen', lebar: '120px', prioritas: 2 },
  { kunci: 'alergi', label: 'Alergi', lebar: '110px' },
  { kunci: 'tunggu', label: 'Menunggu', lebar: '130px', prioritas: 2 },
  { kunci: 'aksi', label: 'Aksi', rata: 'kanan', lebar: '260px' },
]

const bisaPanggil = computed(() => boleh('pendaftaran.antrean.perbaiki'))
const bisaAsesmen = computed(() => boleh('rawat_jalan.asesmen_perawat.lihat'))
const panggil = useMutation({
  mutationFn: (b: BarisWorklist) => aksiAntrean(b.antrean_id, 'panggil'),
  onSuccess: (a) => {
    tampilkanToast(`Nomor ${a.nomor_tampil} dipanggil.`, 'ok')
    void qc.invalidateQueries({ queryKey: KUNCI_WORKLIST.semua })
  },
  // Status di layar bisa tertinggal dari server (petugas lain); muat ulang supaya tombolnya mengikuti.
  onError: () => void qc.invalidateQueries({ queryKey: KUNCI_WORKLIST.semua }),
})
const sibukId = computed(() => (panggil.isPending.value ? panggil.variables.value?.antrean_id : null))

const terpilih = ref<BarisWorklist | null>(null)
const dialogBuka = ref(false)
function bukaAsesmen(b: BarisWorklist) {
  terpilih.value = b
  dialogBuka.value = true
}
const bisaCppt = computed(() => boleh('rawat_jalan.cppt.lihat'))
const pasienCppt = ref<BarisWorklist | null>(null)
// Ganti poli atau tanggal: pasien terpilih belum tentu ada di daftar baru.
watch([poliId, tanggal], () => (pasienCppt.value = null))
const labelAsesmen = (b: BarisWorklist) => (!b.asesmen_awal ? 'Mulai asesmen' : b.asesmen_awal.status === 'draf' ? 'Lanjutkan asesmen' : 'Lihat asesmen')
const galatPilihan = (e: unknown) => (e ? `Daftar poliklinik tidak dapat dimuat: ${pesanError(e)}` : undefined)
</script>

<template>
  <HalamanModul
    :izin="IZIN[mode]"
    :judul="JUDUL[mode]"
    :uraian="URAIAN[mode]"
  >
    <section class="kartu" aria-labelledby="judul-worklist">
      <div class="kartu-kepala">
        <h2 id="judul-worklist">Daftar pasien poli</h2>
      </div>
      <div class="baris-filter">
        <KolomIsian v-slot="a" label="Tanggal" wajib>
          <input v-bind="a" v-model="tanggal" type="date" class="isian" />
        </KolomIsian>
        <KolomIsian v-slot="a" label="Poliklinik" wajib :error="galatPilihan(poli.error.value)">
          <select v-bind="a" v-model="poliId" class="isian">
            <option value="" disabled>Pilih poliklinik…</option>
            <option v-for="p in poli.data.value ?? []" :key="p.id" :value="p.id">{{ p.nama }}</option>
          </select>
        </KolomIsian>
        <KolomIsian v-slot="a" label="Tahap">
          <select v-bind="a" v-model="tahap" class="isian">
            <option value="">Semua tahap</option>
            <option v-for="(label, kunci) in LABEL_TAHAP" :key="kunci" :value="kunci">{{ label }}</option>
          </select>
        </KolomIsian>
      </div>

      <div class="isi-pesan" role="alert">
        <Pesan v-if="panggil.isError.value" jenis="error">{{ pesanAksi(panggil.error.value) }}</Pesan>
      </div>

      <KeadaanKosong v-if="!poliId" judul="Pilih poliklinik dulu" uraian="Daftar pasien muncul setelah poliklinik dan tanggal dipilih." />
      <DataTable
        v-else
        :kolom="kolom"
        :baris="baris"
        :kunci-baris="(b: BarisWorklist) => b.antrean_id"
        label="Worklist poliklinik"
        :memuat="kueri.isPending.value"
        :memuat-lagi="kueri.isFetchingNextPage.value"
        :error="kueri.isError.value ? pesanError(kueri.error.value) : null"
        :ada-lagi="kueri.hasNextPage.value"
        teks-kosong="Belum ada pasien pada poli, tanggal, dan tahap ini. Pasien muncul setelah kunjungannya didaftarkan."
        @muat-lagi="kueri.fetchNextPage()"
        @coba-lagi="kueri.refetch()"
      >
        <template #sel-nomor="{ baris: b }"><code>{{ b.nomor_tampil }}</code></template>
        <template #sel-pasien="{ baris: b }">
          <b>{{ b.pasien.nama }}</b>
          <span class="ket">No. RM {{ formatNoRm(b.pasien.no_rm) }} · {{ formatTanggal(b.pasien.tanggal_lahir) }}</span>
        </template>
        <template #sel-dokter="{ baris: b }">{{ b.dokter.nama }}</template>
        <template #sel-tahap="{ baris: b }">
          <Lencana :jenis="NADA_TAHAP[b.tahap]">{{ LABEL_TAHAP[b.tahap] }}</Lencana>
          <span class="ket">Antrean: <Lencana :jenis="NADA_STATUS[b.status_antrean]">{{ LABEL_STATUS[b.status_antrean] }}</Lencana></span>
        </template>
        <template #sel-asesmen="{ baris: b }">{{ b.asesmen_awal ? LABEL_ASESMEN[b.asesmen_awal.status] : 'Belum dimulai' }}</template>
        <template #sel-alergi="{ baris: b }">
          <Lencana v-if="b.ada_alergi" jenis="peringatan">Ada alergi</Lencana>
          <span v-else class="ket">Tidak tercatat</span>
        </template>
        <template #sel-tunggu="{ baris: b }">{{ b.status_antrean === 'menunggu' || b.status_antrean === 'dipanggil' ? lamaMenunggu(b.menunggu_sejak, sekarang) : '—' }}</template>
        <template #sel-aksi="{ baris: b }">
          <div class="aksi-baris">
            <Tombol v-if="bisaPanggil && (b.status_antrean === 'menunggu' || b.status_antrean === 'dipanggil')" :varian="b.status_antrean === 'menunggu' ? 'utama' : 'kedua'" :sibuk="sibukId === b.antrean_id" :aria-label="`${b.status_antrean === 'dipanggil' ? 'Panggil ulang' : 'Panggil'} ${b.nomor_tampil}`" @click="panggil.mutate(b)">
              {{ b.status_antrean === 'dipanggil' ? 'Panggil ulang' : 'Panggil' }}
            </Tombol>
            <Tombol v-if="bisaCppt" :aria-label="`Buka CPPT ${b.nomor_tampil}`" :aria-pressed="pasienCppt?.kunjungan_id === b.kunjungan_id" @click="pasienCppt = b">CPPT</Tombol>
            <Tombol v-if="bisaAsesmen && mode !== 'cppt'" :aria-label="`${labelAsesmen(b)} ${b.nomor_tampil}`" @click="bukaAsesmen(b)">{{ labelAsesmen(b) }}</Tombol>
          </div>
        </template>
      </DataTable>
    </section>

    <CpptLinimasa v-if="pasienCppt && bisaCppt" :key="pasienCppt.kunjungan_id" :baris="pasienCppt" />
    <DialogAsesmen v-if="terpilih" :key="terpilih.kunjungan_id" v-model:open="dialogBuka" :baris="terpilih" />
  </HalamanModul>
</template>

<style scoped>
.baris-filter {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
}
.isi-pesan:not(:empty) {
  padding: 0 var(--space-4) var(--space-3);
}
.ket {
  display: block;
  font-size: 12px;
  color: var(--muted);
}
.aksi-baris {
  display: inline-flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: var(--space-2);
}
</style>
