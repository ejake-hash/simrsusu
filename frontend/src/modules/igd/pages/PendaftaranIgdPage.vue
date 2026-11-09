<script setup lang="ts">
import { useInfiniteQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import Lencana from '@/design-system/components/Lencana.vue'
import Pesan from '@/design-system/components/Pesan.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import HalamanModul from '@/layouts/HalamanModul.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import { jamWib } from '@/modules/antrean/antrean'
import type { Berversi } from '@/modules/organisasi/api'
import { buatKunjunganIgd, daftarKunjunganIgd } from '@/modules/pendaftaran/apiKunjungan'
import CariPasien from '@/modules/pendaftaran/components/CariPasien.vue'
import DialogPasien from '@/modules/pendaftaran/components/DialogPasien.vue'
import PilihCaraBayar from '@/modules/pendaftaran/components/PilihCaraBayar.vue'
import { KUNCI_KUNJUNGAN, LABEL_CARA_BAYAR, LABEL_STATUS, lamaDiIgd, NADA_STATUS } from '@/modules/pendaftaran/kunjungan'
import { formatNoRm, formatTanggal } from '@/modules/pendaftaran/pasien'
import type { Pasien } from '@/modules/pendaftaran/tipe'
import type { CaraBayar, Kunjungan } from '@/modules/pendaftaran/tipeKunjungan'
import { ApiError } from '@/shared/api/klien'
import { boleh } from '@/shared/composables/usePermission'

/**
 * IGD → Pendaftaran IGD (tiket 2.23, kontrak v0.31.0; FR-03-01). Registrasi cepat dengan isian minimal: pasien dari
 * pencarian 2.5 (atau pasien baru/tanpa identitas lewat dialog pasien yang sama dengan layar Pendaftaran pasien), cara
 * bayar, keluhan opsional. Jam tiba = jam server. Daftar IGD aktif disegarkan pelan (15 detik) agar tidak membebani batas
 * laju akun; tanda "lebih dari 6 jam" dari server (7.00.2), bukan hitungan perangkat.
 */
const qc = useQueryClient()

const pasienDipilih = ref<Pasien | null>(null)
const caraBayar = ref<CaraBayar>('non_jkn')
const penjaminId = ref('')
const keluhan = ref('')

// Pasien ber-No. kartu BPJS → JKN lebih dulu; petugas tetap bisa mengganti.
watch(pasienDipilih, (p) => {
  if (p) caraBayar.value = p.no_kartu_bpjs ? 'jkn' : 'non_jkn'
})

// ── Pasien baru (dialog yang sama dengan Pendaftaran pasien, termasuk tanpa identitas) ──
const dialogPasien = ref(false)
function pasienBaruSelesai(hasil: Berversi<Pasien>) {
  tampilkanToast(`Pasien terdaftar dengan No. RM ${formatNoRm(hasil.data.no_rm)}.`, 'ok')
  pasienDipilih.value = hasil.data
}

// ── Registrasi ──
const daftar = useMutation({
  mutationFn: () =>
    buatKunjunganIgd({
      pasien_id: pasienDipilih.value!.id,
      cara_bayar: caraBayar.value,
      penjamin_id: caraBayar.value === 'jkn' ? penjaminId.value || null : null,
      keluhan: keluhan.value.trim() || null,
    }),
  onSuccess: (hasil) => {
    const k = hasil.data
    tampilkanToast(`${k.pasien.nama} terdaftar di IGD: ${k.nomor}, tiba ${k.tiba_pada ? jamWib(k.tiba_pada) : '—'}.`, 'ok')
    pasienDipilih.value = null
    keluhan.value = ''
    void qc.invalidateQueries({ queryKey: KUNCI_KUNJUNGAN.igd })
  },
  // Kunjungan aktif yang menolak registrasi bisa didaftarkan loket lain sesudah daftar terakhir dimuat.
  onError: (e) => {
    if (e instanceof ApiError && e.kode === 'kunjungan_igd_aktif') void qc.invalidateQueries({ queryKey: KUNCI_KUNJUNGAN.igd })
  },
})
watch([pasienDipilih, caraBayar, penjaminId], () => daftar.reset())
const galat = computed(() => daftar.error.value)
const kodeGalat = computed(() => (galat.value instanceof ApiError ? galat.value.kode : null))
const errorPenjamin = computed(() => errorKolom(galat.value, ['penjamin_id']).penjamin_id)
/** Kunjungan IGD aktif milik pasien yang sama (409 kunjungan_igd_aktif): ditandai di daftar. */
const igdAktifSama = computed(() => {
  const e = galat.value
  if (!(e instanceof ApiError) || e.kode !== 'kunjungan_igd_aktif') return null
  const id = (e.rincian[0] as { kunjungan_id?: unknown } | undefined)?.kunjungan_id
  return typeof id === 'string' ? id : null
})
const bolehKirim = computed(() => Boolean(pasienDipilih.value && (caraBayar.value !== 'jkn' || penjaminId.value)))

// ── Daftar IGD aktif ──
const kueri = useInfiniteQuery({
  queryKey: KUNCI_KUNJUNGAN.igd,
  queryFn: ({ pageParam }) => daftarKunjunganIgd(null, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
  // Pendaftaran dari loket lain; segarkan pelan (hanya saat tab terlihat), pola Antrean & display.
  refetchInterval: 15_000,
})
const baris = computed<Kunjungan[]>(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])
const igdAktifTermuat = computed(() => igdAktifSama.value !== null && baris.value.some((k) => k.id === igdAktifSama.value))
const kolom: KolomTabel[] = [
  { kunci: 'nomor', label: 'No. kunjungan', lebar: '190px' },
  { kunci: 'pasien', label: 'Pasien' },
  { kunci: 'tiba', label: 'Jam tiba', lebar: '140px' },
  { kunci: 'lama', label: 'Lama di IGD', lebar: '150px' },
  { kunci: 'cara_bayar', label: 'Cara bayar', prioritas: 3, lebar: '150px' },
  { kunci: 'status', label: 'Status', lebar: '200px' },
]

// Lama di IGD ikut berjalan di antara penyegaran data.
const sekarang = ref(new Date())
let detak: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  detak = setInterval(() => (sekarang.value = new Date()), 30_000)
})
onBeforeUnmount(() => clearInterval(detak))
</script>

<template>
  <HalamanModul
    izin="igd.pendaftaran.lihat"
    judul="Pendaftaran IGD"
    uraian="Daftarkan pasien gawat dengan isian minimal. Jam tiba dicatat dari jam server; identitas pasien boleh dilengkapi kemudian di Pendaftaran pasien."
  >
    <section v-if="boleh('igd.pendaftaran.input')" class="kartu" aria-labelledby="judul-registrasi">
      <div class="kartu-kepala">
        <h2 id="judul-registrasi">Registrasi cepat</h2>
        <Tombol v-if="boleh('pendaftaran.pasien.input')" @click="dialogPasien = true"><Ikon nama="tambah" :ukuran="16" />Pasien baru</Tombol>
      </div>

      <div class="blok">
        <h3 class="blok-judul">Pasien</h3>
        <CariPasien
          v-model="pasienDipilih"
          id-isian="cari-pasien-igd"
          :teks-tidak-cocok="boleh('pendaftaran.pasien.input') ? 'Tidak ada pasien yang cocok. Pakai Pasien baru; pasien tanpa identitas tetap mendapat No. RM.' : 'Tidak ada pasien yang cocok. Minta petugas pendaftaran mendaftarkan pasien baru.'"
        />
      </div>

      <div class="baris-isian">
        <PilihCaraBayar v-model:cara-bayar="caraBayar" v-model:penjamin-id="penjaminId" nama="cara-bayar-igd" :error-penjamin="errorPenjamin" />
        <KolomIsian v-slot="a" label="Keluhan utama (opsional)" petunjuk="Boleh dikosongkan saat tiba. Maksimal 1.000 karakter.">
          <textarea v-bind="a" v-model="keluhan" class="isian" rows="2" maxlength="1000"></textarea>
        </KolomIsian>
      </div>

      <div v-if="galat && (kodeGalat === 'kunjungan_igd_aktif' || !errorPenjamin)" class="blok">
        <Pesan v-if="kodeGalat === 'kunjungan_igd_aktif'" jenis="peringatan">
          <template v-if="igdAktifTermuat">Pasien ini masih tercatat di IGD dan ditandai "Kunjungan aktif pasien ini" di daftar Pasien di IGD. Lanjutkan kunjungan itu; tidak perlu didaftarkan ulang.</template>
          <template v-else>Pasien ini masih tercatat di IGD. Lanjutkan kunjungan yang aktif (lihat daftar Pasien di IGD, muat lagi bila belum tampil); tidak perlu didaftarkan ulang.</template>
        </Pesan>
        <Pesan v-else-if="!errorPenjamin" jenis="error">{{ pesanError(galat) }}</Pesan>
      </div>

      <div class="aksi-kirim">
        <p v-if="!pasienDipilih" class="tafsir">Pilih pasien dulu.</p>
        <p v-else-if="caraBayar === 'jkn' && !penjaminId" class="tafsir">Pilih penjamin BPJS untuk cara bayar JKN.</p>
        <Tombol varian="utama" ukuran="besar" :disabled="!bolehKirim || undefined" :sibuk="daftar.isPending.value" @click="daftar.mutate()">
          Daftarkan ke IGD
        </Tombol>
      </div>
    </section>

    <section class="kartu" aria-labelledby="judul-igd-aktif">
      <div class="kartu-kepala">
        <div>
          <h2 id="judul-igd-aktif">Pasien di IGD</h2>
          <p>Kunjungan IGD yang masih terdaftar atau dilayani, urut jam tiba terlama. Diperbarui otomatis tiap 15 detik.</p>
        </div>
      </div>
      <DataTable
        :kolom="kolom"
        :baris="baris"
        :kunci-baris="(k: Kunjungan) => k.id"
        label="Tabel pasien di IGD"
        :terpilih="igdAktifSama"
        :memuat="kueri.isPending.value"
        :memuat-lagi="kueri.isFetchingNextPage.value"
        :error="kueri.isError.value ? pesanError(kueri.error.value) : null"
        :ada-lagi="kueri.hasNextPage.value"
        teks-kosong="Belum ada pasien di IGD."
        @muat-lagi="kueri.fetchNextPage()"
        @coba-lagi="kueri.refetch()"
      >
        <template #sel-nomor="{ baris: k }"><code>{{ k.nomor }}</code></template>
        <template #sel-pasien="{ baris: k }">
          <b>{{ k.pasien.nama }}</b>
          <span class="ket">No. RM {{ formatNoRm(k.pasien.no_rm) }} · {{ formatTanggal(k.pasien.tanggal_lahir) }}</span>
        </template>
        <template #sel-tiba="{ baris: k }">
          {{ k.tiba_pada ? jamWib(k.tiba_pada) : '—' }}
          <span class="ket">{{ formatTanggal(k.tanggal) }}</span>
        </template>
        <template #sel-lama="{ baris: k }"><span class="angka">{{ lamaDiIgd(k.tiba_pada, sekarang) }}</span></template>
        <template #sel-cara_bayar="{ baris: k }">{{ LABEL_CARA_BAYAR[k.cara_bayar] }}</template>
        <template #sel-status="{ baris: k }">
          <span class="lencana-tumpuk">
            <Lencana :jenis="NADA_STATUS[k.status]">{{ LABEL_STATUS[k.status] }}</Lencana>
            <Lencana v-if="k.lebih_6_jam" jenis="peringatan">Lebih dari 6 jam</Lencana>
            <Lencana v-if="k.id === igdAktifSama" jenis="info">Kunjungan aktif pasien ini</Lencana>
          </span>
        </template>
      </DataTable>
    </section>

    <DialogPasien v-if="boleh('pendaftaran.pasien.input')" v-model:open="dialogPasien" :pasien="null" @selesai="pasienBaruSelesai" />
  </HalamanModul>
</template>

<style scoped>
.blok {
  padding: 0 var(--space-4) var(--space-3);
}
.blok-judul {
  margin: var(--space-2) 0;
  font-size: 14px;
  color: var(--muted);
}
.baris-isian {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
}
.tafsir {
  margin: 0;
  font-size: 13px;
  color: var(--muted);
}
.aksi-kirim {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  border-top: 1px solid var(--border);
}
.ket {
  display: block;
  font-size: 12px;
  color: var(--muted);
}
.angka {
  font-variant-numeric: tabular-nums;
}
.lencana-tumpuk {
  display: inline-flex;
  flex-wrap: wrap;
  gap: var(--space-1);
}
</style>
