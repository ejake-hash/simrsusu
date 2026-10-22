<script setup lang="ts">
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import Dialog from '@/design-system/components/Dialog.vue'
import KeadaanKosong from '@/design-system/components/KeadaanKosong.vue'
import Lencana from '@/design-system/components/Lencana.vue'
import Pesan from '@/design-system/components/Pesan.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import HalamanModul from '@/layouts/HalamanModul.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import { daftarJadwal } from '@/modules/jadwal-praktik/api'
import { hariIniLokal, labelHari, LABEL_ALASAN, nadaSisa } from '@/modules/jadwal-praktik/jadwal'
import type { JadwalPraktik } from '@/modules/jadwal-praktik/tipe'
import { daftarPoliklinik } from '@/modules/organisasi/apiLokasi'
import { daftarPenjamin } from '@/modules/penjamin/api'
import { ApiError } from '@/shared/api/klien'
import { refDebounced } from '@/shared/composables/refDebounced'
import { boleh } from '@/shared/composables/usePermission'
import { cariPasien } from '../api'
import { KUNCI_KUNJUNGAN, LABEL_CARA_BAYAR, LABEL_STATUS, NADA_STATUS } from '../kunjungan'
import { formatNoRm, formatTanggal } from '../pasien'
import type { Pasien } from '../tipe'
import { batalkanKunjungan, buatKunjungan, daftarKunjungan } from '../apiKunjungan'
import type { CaraBayar, Kunjungan, SlotTerdekat } from '../tipeKunjungan'

/**
 * Pendaftaran → Pendaftaran kunjungan poli (tiket 2.6, kontrak v0.20.0). Petugas memilih pasien, poli, dan dokter dari
 * jadwal praktik hari itu (sisa kuota JKN/non-JKN tampil per slot), lalu mendaftarkan kunjungan — episode rawat jalan
 * harian terbentuk otomatis di server (7.00.2). Papan kunjungan poli hari itu dapat dibatalkan dengan alasan (FR-01-21).
 * Memakai data tiruan kontrak sampai backend 2.6 tersedia.
 */
const qc = useQueryClient()

const tanggal = ref(hariIniLokal())
const poliId = ref('')
const jadwalId = ref('')
const caraBayar = ref<CaraBayar>('jkn')
const penjaminId = ref('')
const keluhan = ref('')
const pasienDipilih = ref<Pasien | null>(null)

// ── Pilihan poliklinik ──
const poli = useQuery({ queryKey: ['kunjungan', 'poli'], queryFn: async () => (await daftarPoliklinik({ status: 'aktif' }, null, 200)).data, staleTime: 60_000 })

// ── Jadwal praktik (poli + dokter + kuota) pada tanggal terpilih ──
const filterJadwal = computed(() => ({ tanggal: tanggal.value, poli_id: poliId.value, status: 'aktif' as const }))
const jadwalKueri = useQuery({
  queryKey: computed(() => ['kunjungan', 'jadwal', tanggal.value, poliId.value]),
  queryFn: async () => (await daftarJadwal(filterJadwal.value, null, 200)).data,
  enabled: computed(() => Boolean(poliId.value && tanggal.value)),
})
const jadwalBaris = computed<JadwalPraktik[]>(() => jadwalKueri.data.value ?? [])
const jadwalTerpilih = computed(() => jadwalBaris.value.find((j) => j.id === jadwalId.value) ?? null)
const sisaTerpilih = computed(() => {
  const k = jadwalTerpilih.value?.pada_tanggal
  if (!k) return null
  return caraBayar.value === 'jkn' ? k.sisa_jkn : k.sisa_non_jkn
})

// ── Penjamin BPJS untuk cara bayar JKN ──
const penjaminBpjs = useQuery({
  queryKey: ['kunjungan', 'penjamin-bpjs'],
  queryFn: async () => (await daftarPenjamin({ status: 'aktif', jenis: 'bpjs' }, null, 200)).data,
  enabled: computed(() => caraBayar.value === 'jkn'),
  staleTime: 60_000,
})
// Bila hanya satu penjamin BPJS, pilih otomatis supaya petugas tidak menebak.
watch([() => caraBayar.value, () => penjaminBpjs.data.value], () => {
  const d = penjaminBpjs.data.value ?? []
  if (caraBayar.value === 'jkn' && !penjaminId.value && d.length === 1) penjaminId.value = d[0]!.id
  if (caraBayar.value !== 'jkn') penjaminId.value = ''
})

// ── Pencarian pasien ──
const cariKata = ref('')
const cariTunda = refDebounced(cariKata, 350)
const kataPasien = computed(() => cariTunda.value.trim())
const pasienKueri = useQuery({
  queryKey: computed(() => ['kunjungan', 'cari-pasien', kataPasien.value]),
  queryFn: () => cariPasien(kataPasien.value),
  enabled: computed(() => kataPasien.value.length > 0 && !pasienDipilih.value),
})
const hasilPasien = computed<Pasien[]>(() => pasienKueri.data.value?.data ?? [])
const pesanBentukPasien = computed(() => {
  const e = pasienKueri.error.value
  return e instanceof ApiError && (e.kode === 'kata_cari_tidak_dikenali' || e.kode === 'kata_cari_wajib') ? e.message : null
})
function pilihPasien(p: Pasien) {
  pasienDipilih.value = p
  cariKata.value = ''
}
function gantiPasien() {
  pasienDipilih.value = null
}
const jk = (p: { jenis_kelamin: string }) => (p.jenis_kelamin === 'laki_laki' ? 'L' : p.jenis_kelamin === 'perempuan' ? 'P' : '—')

// Ganti poli/tanggal → lepas jadwal terpilih; ganti cara bayar → bersihkan error.
watch([poliId, tanggal], () => {
  jadwalId.value = ''
})
watch([jadwalId, () => pasienDipilih.value, caraBayar], () => buat.reset())

// ── Daftarkan kunjungan ──
const buat = useMutation({
  mutationFn: () =>
    buatKunjungan({
      pasien_id: pasienDipilih.value!.id,
      jadwal_praktik_id: jadwalId.value,
      tanggal: tanggal.value,
      cara_bayar: caraBayar.value,
      penjamin_id: caraBayar.value === 'jkn' ? penjaminId.value || null : null,
      keluhan: keluhan.value.trim() || null,
    }),
  onSuccess: (hasil) => {
    tampilkanToast(`Kunjungan ${hasil.data.nomor} terdaftar untuk ${hasil.data.pasien.nama}.`, 'ok')
    keluhan.value = ''
    pasienDipilih.value = null
    void qc.invalidateQueries({ queryKey: KUNCI_KUNJUNGAN.semua })
    void qc.invalidateQueries({ queryKey: ['kunjungan', 'jadwal'] })
  },
})
const galatBuat = computed(() => buat.error.value)
const errorKolomBuat = computed(() => errorKolom(galatBuat.value, ['pasien_id', 'jadwal_praktik_id', 'tanggal', 'cara_bayar', 'penjamin_id']))
const slotAlternatif = computed<SlotTerdekat[]>(() => {
  const e = galatBuat.value
  return e instanceof ApiError && e.kode === 'kuota_habis' ? (e.rincian as unknown as SlotTerdekat[]) : []
})
const jadwalTersedia = computed(() => jadwalTerpilih.value?.pada_tanggal?.tersedia !== false)
const bolehKirim = computed(() => Boolean(pasienDipilih.value && jadwalId.value && jadwalTersedia.value && (caraBayar.value !== 'jkn' || penjaminId.value)))
function pilihSlotAlternatif(s: SlotTerdekat) {
  jadwalId.value = s.jadwal_praktik_id
  if (caraBayar.value === 'jkn' ? s.sisa_jkn <= 0 : s.sisa_non_jkn <= 0) caraBayar.value = s.sisa_jkn > 0 ? 'jkn' : 'non_jkn'
}

// ── Papan kunjungan poli hari itu ──
const statusPapan = ref<'aktif' | 'batal'>('aktif')
const kunjunganKueri = useQuery({
  queryKey: computed(() => KUNCI_KUNJUNGAN.daftar({ tanggal: tanggal.value, poli_id: poliId.value, status: statusPapan.value })),
  queryFn: async () => (await daftarKunjungan({ tanggal: tanggal.value, poli_id: poliId.value, status: statusPapan.value === 'batal' ? 'batal' : undefined }, null, 200)).data,
  enabled: computed(() => Boolean(poliId.value && tanggal.value)),
})
const kunjunganBaris = computed<Kunjungan[]>(() => kunjunganKueri.data.value ?? [])
const kolomPapan = computed<KolomTabel[]>(() => [
  { kunci: 'nomor', label: 'No. kunjungan', lebar: '170px' },
  { kunci: 'pasien', label: 'Pasien' },
  { kunci: 'dokter', label: 'Dokter', prioritas: 2 },
  { kunci: 'cara_bayar', label: 'Cara bayar', prioritas: 3, lebar: '150px' },
  { kunci: 'status', label: 'Status', lebar: '130px' },
  { kunci: 'aksi', label: 'Aksi', rata: 'kanan', lebar: '110px' },
])

// ── Pembatalan kunjungan ──
const dialogBatal = ref(false)
const targetBatal = ref<Kunjungan | null>(null)
const alasanBatal = ref('')
function bukaBatal(k: Kunjungan) {
  targetBatal.value = k
  alasanBatal.value = ''
  batal.reset()
  dialogBatal.value = true
}
const batal = useMutation({
  mutationFn: () => batalkanKunjungan(targetBatal.value!.id, alasanBatal.value.trim()),
  onSuccess: (hasil) => {
    tampilkanToast(`Kunjungan ${hasil.data.nomor} dibatalkan.`, 'ok')
    dialogBatal.value = false
    void qc.invalidateQueries({ queryKey: KUNCI_KUNJUNGAN.semua })
    void qc.invalidateQueries({ queryKey: ['kunjungan', 'jadwal'] })
  },
})
</script>

<template>
  <HalamanModul
    izin="pendaftaran.pasien.lihat"
    judul="Pendaftaran kunjungan poli"
    uraian="Daftarkan pasien ke poliklinik dan dokter dari jadwal praktik. Episode rawat jalan harian terbentuk otomatis; kunjungan yang belum dilayani dapat dibatalkan dengan alasan."
  >
    <section class="kartu" aria-labelledby="judul-daftar">
      <div class="kartu-kepala">
        <h2 id="judul-daftar">Daftarkan kunjungan</h2>
      </div>

      <div class="baris-filter">
        <KolomIsian v-slot="a" label="Tanggal kunjungan" wajib>
          <input v-bind="a" v-model="tanggal" type="date" class="isian" />
        </KolomIsian>
        <KolomIsian v-slot="a" label="Poliklinik" wajib>
          <select v-bind="a" v-model="poliId" class="isian">
            <option value="" disabled>Pilih poliklinik…</option>
            <option v-for="p in poli.data.value ?? []" :key="p.id" :value="p.id">{{ p.nama }}</option>
          </select>
        </KolomIsian>
      </div>

      <!-- Dokter dari jadwal + sisa kuota -->
      <div v-if="poliId" class="blok">
        <h3 class="blok-judul">Dokter &amp; jadwal</h3>
        <KeadaanKosong
          v-if="!jadwalKueri.isPending.value && jadwalBaris.length === 0 && !jadwalKueri.isError.value"
          judul="Tidak ada jadwal praktik"
          uraian="Tidak ada dokter yang praktik di poli ini pada tanggal tersebut. Pilih tanggal atau poliklinik lain."
        />
        <DataTable
          v-else
          :kolom="[
            { kunci: 'dokter', label: 'Dokter' },
            { kunci: 'jam', label: 'Hari & jam', prioritas: 2, lebar: '200px' },
            { kunci: 'jkn', label: 'Sisa JKN', rata: 'kanan', lebar: '120px' },
            { kunci: 'non', label: 'Sisa non-JKN', rata: 'kanan', lebar: '130px' },
          ]"
          :baris="jadwalBaris"
          :kunci-baris="(j: JadwalPraktik) => j.id"
          label="Jadwal praktik poliklinik"
          dapat-dipilih
          :terpilih="jadwalId || null"
          :memuat="jadwalKueri.isPending.value"
          :error="jadwalKueri.isError.value ? pesanError(jadwalKueri.error.value) : null"
          teks-kosong="Tidak ada jadwal praktik yang cocok."
          @pilih="(j: JadwalPraktik) => (jadwalId = j.id)"
          @coba-lagi="jadwalKueri.refetch()"
        >
          <template #sel-dokter="{ baris: j }">
            <b>{{ j.pegawai.nama }}</b>
            <span v-if="j.pada_tanggal && !j.pada_tanggal.tersedia" class="ket">{{ j.pada_tanggal.alasan_tidak_tersedia ? LABEL_ALASAN[j.pada_tanggal.alasan_tidak_tersedia] : 'Tidak tersedia' }}</span>
          </template>
          <template #sel-jam="{ baris: j }">{{ labelHari(j.hari) }} · {{ j.jam_mulai }}–{{ j.jam_selesai }}</template>
          <template #sel-jkn="{ baris: j }">
            <span v-if="j.pada_tanggal" class="pil" :class="`pil-${j.pada_tanggal.tersedia ? nadaSisa(j.pada_tanggal.sisa_jkn, j.kuota_jkn) : 'netral'}`">{{ j.pada_tanggal.sisa_jkn }} / {{ j.kuota_jkn }}</span>
            <template v-else>{{ j.kuota_jkn }}</template>
          </template>
          <template #sel-non="{ baris: j }">
            <span v-if="j.pada_tanggal" class="pil" :class="`pil-${j.pada_tanggal.tersedia ? nadaSisa(j.pada_tanggal.sisa_non_jkn, j.kuota_non_jkn) : 'netral'}`">{{ j.pada_tanggal.sisa_non_jkn }} / {{ j.kuota_non_jkn }}</span>
            <template v-else>{{ j.kuota_non_jkn }}</template>
          </template>
        </DataTable>
      </div>

      <!-- Pilih pasien -->
      <div class="blok">
        <h3 class="blok-judul">Pasien</h3>
        <div v-if="pasienDipilih" class="pasien-terpilih">
          <div>
            <b>{{ pasienDipilih.nama }}</b>
            <span class="ket">No. RM {{ formatNoRm(pasienDipilih.no_rm) }} · {{ formatTanggal(pasienDipilih.tanggal_lahir) }} · {{ jk(pasienDipilih) }}</span>
          </div>
          <Tombol varian="tautan" @click="gantiPasien">Ganti pasien</Tombol>
        </div>
        <template v-else>
          <div class="cari-baris">
            <Ikon nama="cari" :ukuran="18" />
            <label class="sr-only" for="cari-pasien-kunjungan">Cari pasien</label>
            <input id="cari-pasien-kunjungan" v-model="cariKata" type="search" class="isian isian-cari" autocomplete="off" placeholder="Cari pasien: NIK, No. RM, No. kartu BPJS, atau nama + tanggal lahir" />
          </div>
          <p v-if="pesanBentukPasien" class="tafsir" role="status">{{ pesanBentukPasien }}</p>
          <ul v-else-if="kataPasien && hasilPasien.length" class="hasil-pasien">
            <li v-for="p in hasilPasien" :key="p.id">
              <button type="button" class="baris-pasien" @click="pilihPasien(p)">
                <span class="baris-pasien-utama"><b>{{ p.nama }}</b><Lencana v-if="p.meninggal_pada" jenis="netral" class="tanda">Meninggal</Lencana></span>
                <span class="ket">No. RM {{ formatNoRm(p.no_rm) }} · {{ formatTanggal(p.tanggal_lahir) }} · {{ jk(p) }}</span>
              </button>
            </li>
          </ul>
          <p v-else-if="kataPasien && !pasienKueri.isPending.value" class="tafsir" role="status">Tidak ada pasien yang cocok. Daftarkan pasien baru di layar Pendaftaran pasien lebih dulu.</p>
          <p v-else class="tafsir">Mulai dengan mencari pasien yang sudah terdaftar.</p>
        </template>
      </div>

      <!-- Cara bayar & keluhan -->
      <div class="baris-filter">
        <fieldset class="kolom-bayar">
          <legend class="kolom-label">Cara bayar <span class="kolom-wajib" aria-hidden="true">*</span></legend>
          <label v-for="(label, nilai) in LABEL_CARA_BAYAR" :key="nilai" class="radio">
            <input v-model="caraBayar" type="radio" name="cara-bayar" :value="nilai" />
            <span>{{ label }}</span>
          </label>
        </fieldset>
        <KolomIsian v-if="caraBayar === 'jkn'" v-slot="a" label="Penjamin (BPJS)" wajib :error="errorKolomBuat.penjamin_id">
          <select v-bind="a" v-model="penjaminId" class="isian">
            <option value="" disabled>Pilih penjamin…</option>
            <option v-for="pj in penjaminBpjs.data.value ?? []" :key="pj.id" :value="pj.id">{{ pj.nama }}</option>
          </select>
        </KolomIsian>
      </div>
      <KolomIsian v-slot="a" label="Keluhan awal (opsional)" petunjuk="Alasan kunjungan, bukan diagnosis. Maksimal 500 karakter.">
        <textarea v-bind="a" v-model="keluhan" class="isian" rows="2" maxlength="500"></textarea>
      </KolomIsian>

      <Pesan v-if="galatBuat && slotAlternatif.length === 0" jenis="error">{{ pesanError(galatBuat) }}</Pesan>
      <Pesan v-else-if="galatBuat && slotAlternatif.length" jenis="peringatan">
        Kuota dokter ini penuh. Slot terdekat yang masih tersedia di poli ini:
        <ul class="slot-alternatif">
          <li v-for="s in slotAlternatif" :key="s.jadwal_praktik_id">
            {{ s.pegawai.nama }} ({{ s.jam_mulai }}) — sisa JKN {{ s.sisa_jkn }}, non-JKN {{ s.sisa_non_jkn }}
            <Tombol varian="tautan" @click="pilihSlotAlternatif(s)">Pilih dokter ini</Tombol>
          </li>
        </ul>
      </Pesan>

      <div class="aksi-kirim">
        <p v-if="jadwalTerpilih && !jadwalTersedia" class="tafsir" role="status">
          {{ jadwalTerpilih.pegawai.nama }} tidak tersedia pada tanggal ini. Pilih dokter atau tanggal lain.
        </p>
        <p v-else-if="jadwalTerpilih && sisaTerpilih !== null" class="tafsir">
          Sisa kuota {{ caraBayar === 'jkn' ? 'JKN' : 'non-JKN' }} dr. {{ jadwalTerpilih.pegawai.nama }}: <b>{{ sisaTerpilih }}</b>
        </p>
        <Tombol v-can="'pendaftaran.pasien.input'" varian="utama" :disabled="!bolehKirim || undefined" :sibuk="buat.isPending.value" @click="buat.mutate()">
          Daftarkan kunjungan
        </Tombol>
      </div>
    </section>

    <section class="kartu" aria-labelledby="judul-papan">
      <div class="kartu-kepala">
        <div>
          <h2 id="judul-papan">Kunjungan poli hari itu</h2>
          <p>Kunjungan pada poliklinik dan tanggal yang dipilih di atas.</p>
        </div>
        <div class="segmen" role="group" aria-label="Tampilkan kunjungan">
          <button type="button" :class="{ aktif: statusPapan === 'aktif' }" :aria-pressed="statusPapan === 'aktif'" @click="statusPapan = 'aktif'">Aktif</button>
          <button type="button" :class="{ aktif: statusPapan === 'batal' }" :aria-pressed="statusPapan === 'batal'" @click="statusPapan = 'batal'">Dibatalkan</button>
        </div>
      </div>

      <KeadaanKosong v-if="!poliId" judul="Pilih poliklinik dulu" uraian="Papan kunjungan muncul setelah poliklinik dan tanggal dipilih." />
      <DataTable
        v-else
        :kolom="kolomPapan"
        :baris="kunjunganBaris"
        :kunci-baris="(k: Kunjungan) => k.id"
        label="Papan kunjungan poliklinik"
        :memuat="kunjunganKueri.isPending.value"
        :error="kunjunganKueri.isError.value ? pesanError(kunjunganKueri.error.value) : null"
        :teks-kosong="statusPapan === 'batal' ? 'Tidak ada kunjungan yang dibatalkan.' : 'Belum ada kunjungan pada poli dan tanggal ini.'"
        @coba-lagi="kunjunganKueri.refetch()"
      >
        <template #sel-nomor="{ baris: k }"><code>{{ k.nomor }}</code></template>
        <template #sel-pasien="{ baris: k }">
          <b>{{ k.pasien.nama }}</b>
          <span class="ket">No. RM {{ formatNoRm(k.pasien.no_rm) }} · {{ formatTanggal(k.pasien.tanggal_lahir) }}</span>
        </template>
        <template #sel-dokter="{ baris: k }">{{ k.dokter.nama }}</template>
        <template #sel-cara_bayar="{ baris: k }">{{ LABEL_CARA_BAYAR[k.cara_bayar] }}</template>
        <template #sel-status="{ baris: k }">
          <Lencana :jenis="NADA_STATUS[k.status]">{{ LABEL_STATUS[k.status] }}</Lencana>
          <span v-if="k.status === 'batal' && k.alasan_batal" class="ket">{{ k.alasan_batal }}</span>
        </template>
        <template #sel-aksi="{ baris: k }">
          <Tombol v-if="k.status === 'terdaftar' && boleh('pendaftaran.pasien.batal')" varian="bahaya" :aria-label="`Batalkan kunjungan ${k.nomor}`" @click="bukaBatal(k)">Batal</Tombol>
          <span v-else class="ket" aria-hidden="true">—</span>
        </template>
      </DataTable>
    </section>

    <Dialog v-model:open="dialogBatal" judul="Batalkan kunjungan" :uraian="targetBatal ? `Kunjungan ${targetBatal.nomor} · ${targetBatal.pasien.nama}` : ''" peringatan :sibuk="batal.isPending.value">
      <KolomIsian v-slot="a" label="Alasan pembatalan" wajib petunjuk="Minimal 5 karakter. Kunjungan tidak dihapus; alasan tercatat di jejak audit." :error="batal.error.value ? pesanError(batal.error.value) : undefined">
        <textarea v-bind="a" v-model="alasanBatal" class="isian" rows="3" maxlength="500"></textarea>
      </KolomIsian>
      <template #aksi>
        <Tombol @click="dialogBatal = false">Tutup</Tombol>
        <Tombol varian="bahaya" :disabled="alasanBatal.trim().length < 5 || undefined" :sibuk="batal.isPending.value" @click="batal.mutate()">Batalkan kunjungan</Tombol>
      </template>
    </Dialog>
  </HalamanModul>
</template>

<style scoped>
.baris-filter {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
}
.blok {
  padding: 0 var(--space-4) var(--space-3);
}
.blok-judul {
  margin: var(--space-2) 0;
  font-size: 14px;
  color: var(--muted);
}
.cari-baris {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  color: var(--muted);
}
.isian-cari {
  flex: 1;
  min-width: 0;
}
.tafsir {
  margin: var(--space-2) 0 0;
  font-size: 13px;
  color: var(--muted);
}
.hasil-pasien {
  list-style: none;
  margin: var(--space-2) 0 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
}
.baris-pasien {
  display: flex;
  flex-direction: column;
  gap: 2px;
  width: 100%;
  padding: var(--space-2) var(--space-3);
  text-align: left;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-input);
  cursor: pointer;
}
.baris-pasien:hover {
  border-color: var(--accent);
}
.baris-pasien-utama {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
}
.pasien-terpilih {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  padding: var(--space-3);
  background: var(--bg);
  border-radius: var(--radius-input);
}
.ket {
  display: block;
  font-size: 12px;
  color: var(--muted);
}
.kolom-bayar {
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
  margin: 0;
  padding: 0;
  border: 0;
}
.radio {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  font-size: 14px;
}
.slot-alternatif {
  margin: var(--space-1) 0 0;
  padding-left: var(--space-4);
}
.aksi-kirim {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  border-top: 1px solid var(--border);
}
.segmen {
  display: inline-flex;
  border: 1px solid var(--border);
  border-radius: var(--radius-input);
  overflow: hidden;
}
.segmen button {
  padding: var(--space-1) var(--space-3);
  background: var(--surface);
  border: 0;
  cursor: pointer;
  font-size: 13px;
  color: var(--muted);
}
.segmen button.aktif {
  background: var(--accent);
  color: var(--accent-ink);
}
.pil {
  font-variant-numeric: tabular-nums;
}
</style>
