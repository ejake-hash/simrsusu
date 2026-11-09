<script setup lang="ts">
import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import Dialog from '@/design-system/components/Dialog.vue'
import KeadaanKosong from '@/design-system/components/KeadaanKosong.vue'
import Lencana from '@/design-system/components/Lencana.vue'
import Pesan from '@/design-system/components/Pesan.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import HalamanModul from '@/layouts/HalamanModul.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { daftarJadwal } from '@/modules/jadwal-praktik/api'
import { hariIniLokal } from '@/modules/jadwal-praktik/jadwal'
import { daftarPoliklinik } from '@/modules/organisasi/apiLokasi'
import { formatNoRm, formatTanggal } from '@/modules/pendaftaran/pasien'
import { boleh } from '@/shared/composables/usePermission'
import { LABEL_ALASAN_PRIORITAS } from '../anjungan'
import { aksiAntrean, daftarAntrean } from '../api'
import { jamWib, KUNCI_ANTREAN, LABEL_STATUS, NADA_STATUS, pesanAksi } from '../antrean'
import type { AksiAntrean, Antrean, FilterAntrean, StatusAntrean, TitikLayar } from '../tipe'

/**
 * Pendaftaran → Antrean & display (tiket 2.8, FR-02-03/09/11; loket 2.20). Antrean satu poli, atau loket pendaftaran
 * seluruh RS, pada satu tanggal, urut tunggu; petugas memanggil, menandai dilewati, menyisipkan kembali, atau membatalkan.
 * Di loket, nomor prioritas `P-` dari anjungan didahulukan server dan alasan pilihan pasien tampil untuk dinilai petugas
 * (FR-02-07). Aturan (batas panggilan, jeda, sisip) dijaga server dari data induk; penolakannya (409) tampil apa adanya.
 */
const qc = useQueryClient()
const titik = ref<TitikLayar>('poli')
const loket = computed(() => titik.value === 'loket')
const tanggal = ref(hariIniLokal())
const poliId = ref('')
const pegawaiId = ref('')
const status = ref<StatusAntrean | ''>('')

const poli = useQuery({ queryKey: ['antrean', 'poli'], queryFn: async () => (await daftarPoliklinik({ status: 'aktif' }, null, 200)).data, staleTime: 60_000 })
const jadwal = useQuery({
  queryKey: computed(() => ['antrean', 'dokter', tanggal.value, poliId.value]),
  queryFn: async () => (await daftarJadwal({ tanggal: tanggal.value, poli_id: poliId.value, status: 'aktif' }, null, 200)).data,
  enabled: computed(() => Boolean(poliId.value && tanggal.value)),
})
const dokter = computed(() => [...new Map((jadwal.data.value ?? []).map((j) => [j.pegawai.id, j.pegawai])).values()])
watch([poliId, tanggal], () => {
  pegawaiId.value = ''
})
const galatPilihan = (e: unknown, apa: string) => (e ? `Daftar ${apa} tidak dapat dimuat: ${pesanError(e)}` : undefined)

// Loket: tanpa poli/dokter (server menolak 422 bila dikirim).
const filter = computed<FilterAntrean>(() =>
  loket.value
    ? { tanggal: tanggal.value, titik: 'loket', status: status.value || undefined }
    : { tanggal: tanggal.value, poli_id: poliId.value, pegawai_id: pegawaiId.value || undefined, status: status.value || undefined },
)
const siap = computed(() => Boolean(tanggal.value && (loket.value || poliId.value)))
const kueri = useInfiniteQuery({
  queryKey: computed(() => KUNCI_ANTREAN.daftar(filter.value)),
  queryFn: ({ pageParam }) => daftarAntrean(filter.value, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
  enabled: siap,
  // Antrean berubah dari petugas lain; segarkan pelan (hanya saat tab terlihat) agar tidak membebani batas laju akun.
  refetchInterval: 15_000,
})
const baris = computed<Antrean[]>(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])
const KOLOM_POLI: KolomTabel[] = [{ kunci: 'pasien', label: 'Pasien' }, { kunci: 'dokter', label: 'Dokter', prioritas: 2 }]
const KOLOM_LOKET: KolomTabel[] = [{ kunci: 'prioritas', label: 'Prioritas' }]
const kolom = computed<KolomTabel[]>(() => [
  { kunci: 'nomor', label: 'Nomor', lebar: '110px' },
  ...(loket.value ? KOLOM_LOKET : KOLOM_POLI),
  { kunci: 'status', label: 'Status', lebar: '210px' },
  { kunci: 'aksi', label: 'Aksi', rata: 'kanan', lebar: '300px' },
])
const labelTabel = computed(() => (loket.value ? 'Antrean loket pendaftaran' : 'Antrean poliklinik'))

const bolehUbah = computed(() => boleh('pendaftaran.antrean.perbaiki'))
const BISA_BATAL: StatusAntrean[] = ['menunggu', 'dipanggil', 'dilewati']

const kerjakan = useMutation({
  mutationFn: (v: { a: Antrean; aksi: AksiAntrean; alasan?: string }) => aksiAntrean(v.a.id, v.aksi, v.alasan),
  onSuccess: (hasil, v) => {
    const kata = { panggil: 'dipanggil', lewati: 'dilewati', sisip: 'disisipkan kembali', batal: 'dibatalkan' }[v.aksi]
    tampilkanToast(`Nomor ${hasil.nomor_tampil} ${kata}.`, 'ok')
    dialogBatal.value = false
    void qc.invalidateQueries({ queryKey: KUNCI_ANTREAN.semua })
  },
  // Status di layar bisa tertinggal dari server (petugas lain); muat ulang supaya tombolnya mengikuti.
  onError: () => void qc.invalidateQueries({ queryKey: KUNCI_ANTREAN.semua }),
})
const sibukId = computed(() => (kerjakan.isPending.value ? kerjakan.variables.value?.a.id : null))

const dialogBatal = ref(false)
const target = ref<Antrean | null>(null)
const alasan = ref('')
function bukaBatal(a: Antrean) {
  target.value = a
  alasan.value = ''
  kerjakan.reset()
  dialogBatal.value = true
}
const identitas = (a: Antrean) =>
  a.pasien ? `${a.pasien.nama}, No. RM ${formatNoRm(a.pasien.no_rm)}, lahir ${formatTanggal(a.pasien.tanggal_lahir)}` : a.titik === 'loket' ? 'nomor loket, belum tertaut pasien' : 'tanpa identitas pasien'
</script>

<template>
  <HalamanModul
    izin="pendaftaran.antrean.lihat"
    judul="Antrean & display"
    uraian="Panggil nomor antrean poli atau loket pendaftaran. Layar display di ruang tunggu hanya menampilkan nomor, inisial, dan tujuan."
  >
    <section class="kartu" aria-labelledby="judul-antrean">
      <div class="kartu-kepala">
        <h2 id="judul-antrean">{{ loket ? 'Antrean loket' : 'Antrean poli' }}</h2>
      </div>
      <div class="baris-filter">
        <KolomIsian v-slot="a" label="Titik antrean">
          <select v-bind="a" v-model="titik" class="isian">
            <option value="poli">Poliklinik</option>
            <option value="loket">Loket pendaftaran</option>
          </select>
        </KolomIsian>
        <KolomIsian v-slot="a" label="Tanggal" wajib>
          <input v-bind="a" v-model="tanggal" type="date" class="isian" />
        </KolomIsian>
        <KolomIsian v-if="!loket" v-slot="a" label="Poliklinik" wajib :error="galatPilihan(poli.error.value, 'poliklinik')">
          <select v-bind="a" v-model="poliId" class="isian">
            <option value="" disabled>Pilih poliklinik…</option>
            <option v-for="p in poli.data.value ?? []" :key="p.id" :value="p.id">{{ p.nama }}</option>
          </select>
        </KolomIsian>
        <KolomIsian v-if="!loket" v-slot="a" label="Dokter" :error="galatPilihan(jadwal.error.value, 'dokter')">
          <select v-bind="a" v-model="pegawaiId" class="isian" :disabled="!poliId || undefined">
            <option value="">Semua dokter</option>
            <option v-for="d in dokter" :key="d.id" :value="d.id">{{ d.nama }}</option>
          </select>
        </KolomIsian>
        <KolomIsian v-slot="a" label="Status">
          <select v-bind="a" v-model="status" class="isian">
            <option value="">Semua kecuali batal</option>
            <option v-for="(label, kunci) in LABEL_STATUS" :key="kunci" :value="kunci">{{ label }}</option>
          </select>
        </KolomIsian>
      </div>

      <div class="isi-pesan" role="alert">
        <Pesan v-if="kerjakan.isError.value && !dialogBatal" jenis="error">{{ pesanAksi(kerjakan.error.value) }}</Pesan>
      </div>

      <KeadaanKosong v-if="!siap && loket" judul="Pilih tanggal dulu" uraian="Antrean loket muncul setelah tanggal dipilih." />
      <KeadaanKosong v-else-if="!siap" judul="Pilih poliklinik dulu" uraian="Antrean muncul setelah poliklinik dan tanggal dipilih." />
      <DataTable
        v-else
        :kolom="kolom"
        :baris="baris"
        :kunci-baris="(a: Antrean) => a.id"
        :label="labelTabel"
        :memuat="kueri.isPending.value"
        :memuat-lagi="kueri.isFetchingNextPage.value"
        :error="kueri.isError.value ? pesanError(kueri.error.value) : null"
        :ada-lagi="kueri.hasNextPage.value"
        :teks-kosong="loket ? 'Belum ada nomor loket pada tanggal ini. Nomor terbit saat pasien mengambilnya di anjungan.' : 'Belum ada nomor antrean pada poli dan tanggal ini. Nomor terbit saat kunjungan didaftarkan.'"
        @muat-lagi="kueri.fetchNextPage()"
        @coba-lagi="kueri.refetch()"
      >
        <template #sel-nomor="{ baris: a }"><code>{{ a.nomor_tampil }}</code></template>
        <template #sel-prioritas="{ baris: a }">
          <template v-if="a.prioritas">
            <Lencana jenis="peringatan">Prioritas</Lencana>
            <span v-if="a.alasan_prioritas" class="ket">{{ LABEL_ALASAN_PRIORITAS[a.alasan_prioritas] }} (dipilih pasien, pastikan di loket)</span>
          </template>
          <span v-else class="ket">Biasa</span>
        </template>
        <template #sel-pasien="{ baris: a }">
          <template v-if="a.pasien">
            <b>{{ a.pasien.nama }}</b>
            <span class="ket">No. RM {{ formatNoRm(a.pasien.no_rm) }} · {{ formatTanggal(a.pasien.tanggal_lahir) }}</span>
          </template>
          <span v-else class="ket">—</span>
        </template>
        <template #sel-dokter="{ baris: a }">{{ a.dokter?.nama ?? '' }}</template>
        <template #sel-status="{ baris: a }">
          <Lencana :jenis="NADA_STATUS[a.status]">{{ LABEL_STATUS[a.status] }}</Lencana>
          <span v-if="a.panggilan_ke > 0 && a.status !== 'batal'" class="ket">Panggilan {{ a.panggilan_ke }} dari {{ a.panggilan_maks }}<template v-if="a.lokasi_panggil"> · {{ a.lokasi_panggil.nama }}</template></span>
          <span v-if="a.status === 'dipanggil' && a.boleh_panggil_pada" class="ket">Panggil ulang mulai {{ jamWib(a.boleh_panggil_pada) }}</span>
          <span v-if="a.status === 'batal' && a.alasan_batal" class="ket">{{ a.alasan_batal }}</span>
        </template>
        <template #sel-aksi="{ baris: a }">
          <div v-if="bolehUbah" class="aksi-baris">
            <Tombol v-if="a.status === 'menunggu' || a.status === 'dipanggil'" varian="utama" :sibuk="sibukId === a.id" :aria-label="`${a.status === 'dipanggil' ? 'Panggil ulang' : 'Panggil'} ${a.nomor_tampil}`" @click="kerjakan.mutate({ a, aksi: 'panggil' })">
              {{ a.status === 'dipanggil' ? 'Panggil ulang' : 'Panggil' }}
            </Tombol>
            <Tombol v-if="a.status === 'dipanggil'" :sibuk="sibukId === a.id" :aria-label="`Lewati ${a.nomor_tampil}`" @click="kerjakan.mutate({ a, aksi: 'lewati' })">Lewati</Tombol>
            <Tombol v-if="a.status === 'dilewati'" :sibuk="sibukId === a.id" :aria-label="`Sisipkan ${a.nomor_tampil}`" @click="kerjakan.mutate({ a, aksi: 'sisip' })">Sisipkan</Tombol>
            <Tombol v-if="BISA_BATAL.includes(a.status)" varian="bahaya" :aria-label="`Batalkan ${a.nomor_tampil}`" @click="bukaBatal(a)">Batal</Tombol>
          </div>
        </template>
      </DataTable>
    </section>

    <Dialog v-model:open="dialogBatal" judul="Batalkan nomor antrean" :uraian="target ? `${target.nomor_tampil} · ${identitas(target)}` : ''" peringatan :sibuk="kerjakan.isPending.value">
      <KolomIsian v-slot="a" label="Alasan pembatalan" wajib petunjuk="Minimal 5 karakter. Nomor tidak dihapus; alasan tercatat di jejak audit. Kunjungannya tidak ikut batal." :error="kerjakan.isError.value ? pesanAksi(kerjakan.error.value) : undefined">
        <textarea v-bind="a" v-model="alasan" class="isian" rows="3" maxlength="500"></textarea>
      </KolomIsian>
      <template #aksi>
        <Tombol @click="dialogBatal = false">Tutup</Tombol>
        <Tombol varian="bahaya" :disabled="alasan.trim().length < 5 || undefined" :sibuk="kerjakan.isPending.value" @click="target && kerjakan.mutate({ a: target, aksi: 'batal', alasan: alasan.trim() })">Batalkan nomor</Tombol>
      </template>
    </Dialog>
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
