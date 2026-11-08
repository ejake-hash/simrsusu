<script setup lang="ts">
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, onMounted, ref, watch } from 'vue'
import Lencana from '@/design-system/components/Lencana.vue'
import PopoverPencarian, { type ItemPencarian } from '@/design-system/components/PopoverPencarian.vue'
import Pesan from '@/design-system/components/Pesan.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { formatNoRm, formatTanggal } from '@/modules/pendaftaran/pasien'
import { boleh } from '@/shared/composables/usePermission'
import { catatDiagnosis, cariKodeIcd10, daftarDiagnosis, mulaiPeriksa, ringkasanKlinis, selesaiPeriksa, ubahDiagnosis } from '../api'
import { KUNCI_WORKLIST, LABEL_JENIS_DIAGNOSIS, pesanPemeriksaan } from '../rawatJalan'
import type { BarisWorklist, Diagnosis, JenisDiagnosis } from '../tipe'
import PanelAlergi from './PanelAlergi.vue'

/**
 * Pemeriksaan dokter rawat jalan (tiket 2.15; FR-07-03, FR-07-05, FR-02-09). Membuka panel = dokter membuka RM: kunjungan
 * `terdaftar` menjadi `dilayani` (task 4). Ringkasan klinis dari data yang sudah ada, alergi, diagnosis ICD-10 dari paket
 * yang dimuat RS, lalu Selesaikan kunjungan (task 5) setelah diagnosis utama ada dan CPPT dokter tidak ada yang draf.
 */
const props = defineProps<{ baris: BarisWorklist }>()
const qc = useQueryClient()
const kunjunganId = computed(() => props.baris.kunjungan_id)
const selesai = computed(() => props.baris.tahap === 'selesai' || status.value === 'selesai')
const identitas = computed(() => `${props.baris.pasien.nama} · No. RM ${formatNoRm(props.baris.pasien.no_rm)} · lahir ${formatTanggal(props.baris.pasien.tanggal_lahir)}`)

const bisaInput = computed(() => boleh('rawat_jalan.pemeriksaan.input'))
const bisaSelesai = computed(() => boleh('rawat_jalan.pemeriksaan.sahkan'))
const status = ref<string | null>(null)
const etag = ref<string | null>(null)
const mulai = useMutation({
  mutationFn: () => mulaiPeriksa(kunjunganId.value),
  onSuccess: ({ data, etag: e }) => {
    status.value = data.status
    etag.value = e
    void qc.invalidateQueries({ queryKey: KUNCI_WORKLIST.semua })
  },
})
onMounted(() => {
  if (!selesai.value && bisaInput.value) mulai.mutate()
})
const dilayani = computed(() => status.value === 'dilayani')

const ringkasan = useQuery({ queryKey: computed(() => KUNCI_WORKLIST.ringkasan(props.baris.pasien.id)), queryFn: () => ringkasanKlinis(props.baris.pasien.id) })
const diagnosis = useQuery({ queryKey: computed(() => KUNCI_WORKLIST.diagnosis(kunjunganId.value)), queryFn: async () => (await daftarDiagnosis(kunjunganId.value)).data })
const adaUtama = computed(() => (diagnosis.data.value ?? []).some((d) => d.jenis === 'utama'))
const segarkan = () => {
  void qc.invalidateQueries({ queryKey: KUNCI_WORKLIST.diagnosis(kunjunganId.value) })
  void qc.invalidateQueries({ queryKey: KUNCI_WORKLIST.ringkasan(props.baris.pasien.id) })
}

// Pencarian kode: paket ICD-10 milik RS (K7); server menolak kode di luar paket.
const cariBuka = ref(false)
const kueri = ref('')
const hasil = ref<ItemPencarian[]>([])
const statusCari = ref('Ketik minimal 2 huruf kode atau nama diagnosis.')
const galatCari = ref<string | null>(null)
let jeda: ReturnType<typeof setTimeout> | undefined
watch(kueri, (q) => {
  clearTimeout(jeda)
  hasil.value = []
  if (q.trim().length < 2) return void (statusCari.value = 'Ketik minimal 2 huruf kode atau nama diagnosis.')
  statusCari.value = 'Mencari…'
  jeda = setTimeout(async () => {
    try {
      const kode = await cariKodeIcd10(q.trim())
      if (kueri.value !== q) return
      hasil.value = kode.map((k) => ({ kode: k.kode, label: k.tampilan, pil: k.kode }))
      statusCari.value = kode.length ? `${kode.length} kode ditemukan.` : 'Tidak ada kode yang cocok di paket ICD-10 RS.'
      galatCari.value = null
    } catch (e) {
      statusCari.value = 'Pencarian gagal.'
      galatCari.value = pesanPemeriksaan(e)
      cariBuka.value = false
    }
  }, 250)
})
const jenisBaru = ref<JenisDiagnosis>('utama')
watch(adaUtama, (ada) => (jenisBaru.value = ada ? 'sekunder' : 'utama'), { immediate: true })
const catat = useMutation({
  mutationFn: (kode: string) => catatDiagnosis(kunjunganId.value, kode, jenisBaru.value),
  onSuccess: (d) => {
    tampilkanToast(`Diagnosis ${d.kode} dicatat sebagai ${LABEL_JENIS_DIAGNOSIS[d.jenis].toLowerCase()}.`, 'ok')
    segarkan()
  },
})

const ubah = useMutation({
  mutationFn: (a: { d: Diagnosis; isian: Parameters<typeof ubahDiagnosis>[1] }) => ubahDiagnosis(a.d, a.isian),
  onSuccess: () => {
    sasaran.value = null
    segarkan()
  },
})
const sasaran = ref<Diagnosis | null>(null)
// Galat pembatalan tampil di kolom alasan; galat ganti jenis tampil di bawah daftar.
const batalGagal = computed(() => ubah.isError.value && 'status' in (ubah.variables.value?.isian ?? {}))
const alasan = ref('')
function mulaiBatal(d: Diagnosis) {
  sasaran.value = d
  alasan.value = ''
  ubah.reset()
}

const tutup = useMutation({
  mutationFn: () => selesaiPeriksa(kunjunganId.value, etag.value ?? '""'),
  onSuccess: ({ data }) => {
    status.value = data.status
    tampilkanToast(`Kunjungan ${props.baris.pasien.nama} selesai.`, 'ok')
    void qc.invalidateQueries({ queryKey: KUNCI_WORKLIST.semua })
  },
})
</script>

<template>
  <section class="kartu panel" aria-labelledby="judul-periksa">
    <div class="kartu-kepala">
      <h2 id="judul-periksa">Pemeriksaan dokter</h2>
      <p class="identitas"><b>{{ baris.pasien.nama }}</b> · No. RM {{ formatNoRm(baris.pasien.no_rm) }} · lahir {{ formatTanggal(baris.pasien.tanggal_lahir) }} · {{ baris.nomor_tampil }}</p>
    </div>
    <div class="isi">
      <p v-if="mulai.isPending.value" class="ket" role="status">Membuka rekam medis…</p>
      <Pesan v-else-if="mulai.isError.value" jenis="error">{{ pesanPemeriksaan(mulai.error.value) }}</Pesan>
      <Pesan v-else-if="selesai" jenis="info">Kunjungan ini sudah selesai. Diagnosis tidak bisa diubah lagi dari sini.</Pesan>

      <section aria-labelledby="judul-ringkasan">
        <h3 id="judul-ringkasan">Ringkasan klinis</h3>
        <p v-if="ringkasan.isPending.value" class="ket" role="status">Memuat ringkasan…</p>
        <Pesan v-else-if="ringkasan.isError.value" jenis="error">Ringkasan tidak dapat dimuat: {{ pesanPemeriksaan(ringkasan.error.value) }}</Pesan>
        <div v-else-if="ringkasan.data.value" class="dua-kolom">
          <div>
            <h4>Diagnosis aktif</h4>
            <p v-if="!ringkasan.data.value.diagnosis_aktif.length" class="ket">Belum ada diagnosis tercatat.</p>
            <ul v-else class="daftar-ringkas">
              <li v-for="d in ringkasan.data.value.diagnosis_aktif" :key="d.kode"><code>{{ d.kode }}</code> {{ d.tampilan }} <span class="ket">{{ formatTanggal(d.tanggal) }}</span></li>
            </ul>
          </div>
          <div>
            <h4>Kunjungan terakhir</h4>
            <ul class="daftar-ringkas">
              <li v-for="k in ringkasan.data.value.kunjungan_terakhir" :key="k.kunjungan_id">
                {{ formatTanggal(k.tanggal) }} · {{ k.poliklinik.nama }}<span v-if="k.dokter"> · {{ k.dokter.nama }}</span>
                <span class="ket">{{ k.diagnosis_utama ? `${k.diagnosis_utama.kode} ${k.diagnosis_utama.tampilan}` : 'Tanpa diagnosis utama' }}</span>
              </li>
            </ul>
          </div>
        </div>
        <p class="ket">Hasil laboratorium, obat rutin, dan risiko jatuh menyusul setelah modulnya tersedia.</p>
      </section>

      <PanelAlergi :pasien-id="baris.pasien.id" :bisa-catat="boleh('rawat_jalan.asesmen_perawat.input')" :bisa-batal="boleh('rawat_jalan.asesmen_perawat.input')" />

      <section aria-labelledby="judul-diagnosis">
        <h3 id="judul-diagnosis">Diagnosis kunjungan ini (ICD-10)</h3>
        <p v-if="diagnosis.isPending.value" class="ket" role="status">Memuat diagnosis…</p>
        <Pesan v-else-if="diagnosis.isError.value" jenis="error">Diagnosis tidak dapat dimuat: {{ pesanPemeriksaan(diagnosis.error.value) }}</Pesan>
        <p v-else-if="!diagnosis.data.value?.length" class="ket">Belum ada diagnosis. Diagnosis utama wajib sebelum kunjungan diselesaikan.</p>
        <ul v-else class="daftar">
          <li v-for="d in diagnosis.data.value" :key="d.id">
            <div>
              <Lencana :jenis="d.jenis === 'utama' ? 'ok' : 'netral'">{{ LABEL_JENIS_DIAGNOSIS[d.jenis] }}</Lencana>
              <code>{{ d.kode }}</code> {{ d.tampilan }}
              <span class="ket">Dicatat {{ d.dicatat_oleh?.nama ?? 'sistem' }}</span>
            </div>
            <div v-if="d.dapat.ubah" class="aksi">
              <Tombol :sibuk="ubah.isPending.value && ubah.variables.value?.d.id === d.id" :aria-label="`Jadikan ${d.jenis === 'utama' ? 'sekunder' : 'utama'} ${d.kode}`" @click="ubah.mutate({ d, isian: { jenis: d.jenis === 'utama' ? 'sekunder' : 'utama' } })">
                Jadikan {{ d.jenis === 'utama' ? 'sekunder' : 'utama' }}
              </Tombol>
              <Tombol :aria-label="`Batalkan diagnosis ${d.kode}`" @click="mulaiBatal(d)">Batalkan</Tombol>
            </div>
          </li>
        </ul>
        <Pesan v-if="ubah.isError.value && !batalGagal" jenis="error">{{ pesanPemeriksaan(ubah.error.value) }}</Pesan>

        <form v-if="sasaran" class="form" @submit.prevent="ubah.mutate({ d: sasaran, isian: { status: 'batal', alasan_batal: alasan.trim() } })">
          <KolomIsian v-slot="k" :label="`Alasan membatalkan diagnosis ${sasaran.kode}`" wajib petunjuk="Minimal 5 karakter. Diagnosis tidak dihapus; alasan dan pelakunya tersimpan." :error="batalGagal ? pesanPemeriksaan(ubah.error.value) : undefined">
            <textarea v-bind="k" v-model="alasan" class="isian" rows="2" maxlength="500"></textarea>
          </KolomIsian>
          <div class="aksi">
            <Tombol @click="sasaran = null">Tidak jadi</Tombol>
            <Tombol type="submit" varian="bahaya" :disabled="alasan.trim().length < 5 || undefined" :sibuk="ubah.isPending.value">Batalkan diagnosis</Tombol>
          </div>
        </form>

        <div v-if="dilayani && bisaInput" class="tambah">
          <fieldset class="jenis">
            <legend>Jenis diagnosis baru</legend>
            <label v-for="(label, kunci) in LABEL_JENIS_DIAGNOSIS" :key="kunci"><input v-model="jenisBaru" type="radio" name="jenis-diagnosis" :value="kunci" /> {{ label }}</label>
          </fieldset>
          <PopoverPencarian v-model:open="cariBuka" v-model:kueri="kueri" label="Tambah diagnosis" uraian="paket ICD-10 RS" label-isian="Cari kode atau nama diagnosis ICD-10" placeholder="Kode atau nama diagnosis" :item="hasil" :status="statusCari" ikon="tambah" @pilih="(i) => catat.mutate(i.kode)" />
        </div>
        <Pesan v-if="galatCari" jenis="error">{{ galatCari }}</Pesan>
        <Pesan v-if="catat.isError.value" jenis="error">{{ pesanPemeriksaan(catat.error.value) }}</Pesan>
      </section>

      <div v-if="dilayani && bisaSelesai" class="selesai">
        <Pesan v-if="tutup.isError.value" jenis="error">{{ pesanPemeriksaan(tutup.error.value) }}</Pesan>
        <Tombol varian="utama" :sibuk="tutup.isPending.value" :aria-label="`Selesaikan kunjungan ${identitas}`" @click="tutup.mutate()">Selesaikan kunjungan</Tombol>
      </div>
    </div>
  </section>
</template>

<style scoped>
.panel .isi {
  display: grid;
  gap: var(--space-4);
  padding: var(--space-3) var(--space-4) var(--space-4);
}
.identitas {
  margin: 0;
}
h3,
h4 {
  margin: 0 0 var(--space-2);
}
.ket {
  display: block;
  font-size: 12px;
  color: var(--muted);
}
.dua-kolom {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: var(--space-3);
}
.daftar-ringkas {
  margin: 0;
  padding-left: var(--space-4);
}
.daftar {
  display: grid;
  gap: var(--space-2);
  margin: 0;
  padding: 0;
  list-style: none;
}
.daftar li {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-3);
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--border);
  border-radius: var(--radius-input);
}
.form {
  display: grid;
  gap: var(--space-3);
  margin-top: var(--space-3);
}
.aksi,
.selesai {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  align-items: center;
  gap: var(--space-2);
}
.tambah {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-3);
  margin-top: var(--space-3);
}
.jenis {
  display: flex;
  gap: var(--space-3);
  margin: 0;
  padding: 0;
  border: 0;
}
.jenis legend {
  float: left;
  margin-right: var(--space-2);
  font-weight: 600;
}
</style>
