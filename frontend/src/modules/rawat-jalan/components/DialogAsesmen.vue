<script setup lang="ts">
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Pesan from '@/design-system/components/Pesan.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { formatNoRm, formatTanggal } from '@/modules/pendaftaran/pasien'
import { ApiError } from '@/shared/api/klien'
import { boleh } from '@/shared/composables/usePermission'
import { ambilAsesmenKunjungan, mulaiAsesmen, selesaikanAsesmen, ubahAsesmen } from '../api'
import { KUNCI_WORKLIST, pesanKlinis, sisaPerbaikan } from '../rawatJalan'
import { adalahTerbatas, type AsesmenAwal, type BarisWorklist, type IsianAsesmenAwal, type RisikoJatuh, type Terbatas } from '../tipe'
import PanelAlergi from './PanelAlergi.vue'

/**
 * Asesmen awal keperawatan satu kunjungan (FR-07-02, FR-07-21): draf → selesai, lalu perbaikan ≤ 2 × 24 jam dengan alasan
 * (FR-26-06). Tanda vital angka mentah; IMT dihitung server. Tidak ada penanda ambang atau warna untuk nilai (ambang klinis
 * menunggu data induk RS). Selesai memindahkan pasien ke tahap dokter.
 */
const props = defineProps<{ baris: BarisWorklist }>()
const buka = defineModel<boolean>('open', { required: true })
const qc = useQueryClient()

const bisaInput = computed(() => boleh('rawat_jalan.asesmen_perawat.input'))
const bisaPerbaiki = computed(() => boleh('rawat_jalan.asesmen_perawat.perbaiki'))

const sekarang = ref(Date.now())
const jam = setInterval(() => (sekarang.value = Date.now()), 30_000)
onBeforeUnmount(() => clearInterval(jam))

type Hasil = { data: AsesmenAwal | Terbatas | null; etag: string | null }
const kueri = useQuery<Hasil>({
  queryKey: computed(() => KUNCI_WORKLIST.asesmen(props.baris.kunjungan_id)),
  queryFn: async () => {
    try {
      return await ambilAsesmenKunjungan(props.baris.kunjungan_id)
    } catch (e) {
      if (e instanceof ApiError && e.status === 404) return { data: null, etag: null }
      throw e
    }
  },
  enabled: computed(() => buka.value),
  retry: false,
})
const asesmen = computed(() => (kueri.data.value?.data && !adalahTerbatas(kueri.data.value.data) ? kueri.data.value.data : null))
const terbatas = computed(() => Boolean(kueri.data.value?.data && adalahTerbatas(kueri.data.value.data)))
const etag = computed(() => kueri.data.value?.etag ?? null)

const status = computed<'baru' | 'draf' | 'selesai'>(() => (asesmen.value ? asesmen.value.status : 'baru'))
const sisa = computed(() => sisaPerbaikan(asesmen.value?.batas_perbaikan_pada ?? null, sekarang.value))
const terkunci = computed(() => status.value === 'selesai' && sisa.value === null)
const bisaUbah = computed(() => !terbatas.value && !terkunci.value && (status.value === 'baru' ? bisaInput.value : bisaPerbaiki.value))

const f = reactive({
  keluhan: '', rps: '', rpd: '', catatan: '',
  sistolik: '', diastolik: '', nadi: '', napas: '', suhu: '', spo2: '', berat: '', tinggi: '',
  nyeri: '', lokasiNyeri: '', jatuh: '' as RisikoJatuh | '', gizi: '' as '' | 'ya' | 'tidak', catatanSkrining: '',
  alasan: '',
})
const str = (v: unknown) => (v == null ? '' : String(v))
watch(asesmen, (a) => {
  if (!a) return
  Object.assign(f, {
    keluhan: str(a.keluhan_utama), rps: str(a.riwayat_penyakit_sekarang), rpd: str(a.riwayat_penyakit_dahulu), catatan: str(a.catatan),
    sistolik: str(a.tanda_vital.sistolik), diastolik: str(a.tanda_vital.diastolik), nadi: str(a.tanda_vital.nadi), napas: str(a.tanda_vital.napas),
    suhu: str(a.tanda_vital.suhu), spo2: str(a.tanda_vital.spo2), berat: str(a.tanda_vital.berat_badan), tinggi: str(a.tanda_vital.tinggi_badan),
    nyeri: str(a.skrining.skala_nyeri), lokasiNyeri: str(a.skrining.lokasi_nyeri), jatuh: a.skrining.risiko_jatuh ?? '',
    gizi: a.skrining.gizi_berisiko == null ? '' : a.skrining.gizi_berisiko ? 'ya' : 'tidak', catatanSkrining: str(a.skrining.catatan), alasan: '',
  })
}, { immediate: true })

const teks = (v: string) => v.trim() || null
const angka = (v: string) => (v.trim() === '' ? null : Number(v.replace(',', '.')))
function isian(): IsianAsesmenAwal {
  return {
    keluhan_utama: teks(f.keluhan), riwayat_penyakit_sekarang: teks(f.rps), riwayat_penyakit_dahulu: teks(f.rpd), catatan: teks(f.catatan),
    tanda_vital: {
      sistolik: angka(f.sistolik), diastolik: angka(f.diastolik), nadi: angka(f.nadi), napas: angka(f.napas), suhu: angka(f.suhu),
      spo2: angka(f.spo2), berat_badan: angka(f.berat), tinggi_badan: angka(f.tinggi),
    },
    skrining: { skala_nyeri: angka(f.nyeri), lokasi_nyeri: teks(f.lokasiNyeri), risiko_jatuh: f.jatuh || null, gizi_berisiko: f.gizi === '' ? null : f.gizi === 'ya', catatan: teks(f.catatanSkrining) },
  }
}

/** Simpan isian (buat draf bila belum ada, selain itu ubah), lalu bila diminta selesaikan. */
const kerjakan = useMutation({
  mutationFn: async (selesai: boolean) => {
    let a = asesmen.value
    let tag = etag.value
    if (!a) ({ data: a, etag: tag } = await mulaiAsesmen(props.baris.kunjungan_id, isian()))
    else ({ data: a, etag: tag } = await ubahAsesmen(a.id, tag!, { ...isian(), ...(a.status === 'selesai' ? { alasan_perubahan: f.alasan.trim() } : {}) }))
    if (selesai) ({ data: a, etag: tag } = await selesaikanAsesmen(a.id, tag!))
    return { selesai, data: a, etag: tag }
  },
  onSuccess: (h) => {
    qc.setQueryData<Hasil>(KUNCI_WORKLIST.asesmen(props.baris.kunjungan_id), { data: h.data, etag: h.etag })
    tampilkanToast(h.selesai ? 'Asesmen selesai. Pasien menunggu dokter.' : 'Asesmen disimpan.', 'ok')
    void qc.invalidateQueries({ queryKey: KUNCI_WORKLIST.semua })
    if (h.selesai) buka.value = false
  },
  // Asesmen bisa berubah di tab lain atau oleh petugas lain: muat ulang agar ETag dan isian mengikuti server.
  onError: () => void qc.invalidateQueries({ queryKey: KUNCI_WORKLIST.asesmen(props.baris.kunjungan_id) }),
})
const galat = computed(() => {
  if (!kerjakan.isError.value) return null
  const e = kerjakan.error.value
  const rincian = e instanceof ApiError && e.status === 422 ? e.rincian.map((r) => (r.pesan && r.kolom ? `${r.kolom}: ${r.pesan}` : r.pesan)).filter((x): x is string => Boolean(x)) : []
  return { teks: pesanKlinis(e), rincian }
})
const perluAlasan = computed(() => status.value === 'selesai' && f.alasan.trim().length < 5)

const muatGalat = computed(() => (kueri.isError.value ? pesanError(kueri.error.value) : null))
const uraian = computed(() => `${props.baris.pasien.nama} · No. RM ${formatNoRm(props.baris.pasien.no_rm)} · lahir ${formatTanggal(props.baris.pasien.tanggal_lahir)} · nomor ${props.baris.nomor_tampil}`)
</script>

<template>
  <Dialog v-model:open="buka" judul="Asesmen awal keperawatan" :uraian="uraian" lebar="lebar" :sibuk="kerjakan.isPending.value" tutup-lewat-tombol>
    <p v-if="kueri.isPending.value" class="ket" role="status">Memuat asesmen…</p>
    <Pesan v-else-if="muatGalat" jenis="error">Asesmen tidak dapat dimuat: {{ muatGalat }}</Pesan>
    <Pesan v-else-if="terbatas" jenis="info">Terbatas. Asesmen ini berlabel sensitif dan hanya terbuka bagi penulisnya atau petugas yang merawat pasien.</Pesan>
    <Pesan v-else-if="status === 'baru' && !bisaInput" jenis="info">Asesmen belum dimulai. Akun Anda tidak punya izin mengisi asesmen.</Pesan>
    <div v-else class="isi">
      <Pesan v-if="status === 'selesai' && sisa" jenis="info">
        Asesmen sudah selesai. Perbaikan masih bisa dalam {{ sisa }}; tiap perbaikan butuh alasan dan isi lama tersimpan sebagai versi{{ asesmen!.jumlah_versi ? ` (sudah ${asesmen!.jumlah_versi} kali diperbaiki)` : '' }}.
      </Pesan>
      <Pesan v-else-if="terkunci" jenis="peringatan">Batas perbaikan 2 × 24 jam sudah lewat. Asesmen tidak bisa diubah; koreksi dicatat lewat adendum pada CPPT.</Pesan>
      <Pesan v-else-if="status === 'baru'" jenis="info">Isian boleh dikosongkan saat draf. Untuk menyelesaikan, keluhan utama serta tekanan darah, nadi, napas, dan suhu wajib terisi.</Pesan>

      <form id="form-asesmen" class="isi-form" @submit.prevent>
        <fieldset :disabled="!bisaUbah || undefined">
          <legend>Anamnesis</legend>
          <KolomIsian v-slot="k" label="Keluhan utama" wajib petunjuk="Wajib untuk menyelesaikan."><textarea v-bind="k" v-model="f.keluhan" class="isian" rows="2" maxlength="2000"></textarea></KolomIsian>
          <div class="grid-2">
            <KolomIsian v-slot="k" label="Riwayat penyakit sekarang"><textarea v-bind="k" v-model="f.rps" class="isian" rows="3" maxlength="4000"></textarea></KolomIsian>
            <KolomIsian v-slot="k" label="Riwayat penyakit dahulu"><textarea v-bind="k" v-model="f.rpd" class="isian" rows="3" maxlength="4000"></textarea></KolomIsian>
          </div>
        </fieldset>

        <fieldset :disabled="!bisaUbah || undefined">
          <legend>Tanda vital</legend>
          <div class="grid-4">
            <KolomIsian v-slot="k" label="Sistolik (mmHg)" wajib><input v-bind="k" v-model="f.sistolik" class="isian" type="number" inputmode="numeric" min="30" max="300" step="1" /></KolomIsian>
            <KolomIsian v-slot="k" label="Diastolik (mmHg)" wajib><input v-bind="k" v-model="f.diastolik" class="isian" type="number" inputmode="numeric" min="10" max="200" step="1" /></KolomIsian>
            <KolomIsian v-slot="k" label="Nadi (kali/menit)" wajib><input v-bind="k" v-model="f.nadi" class="isian" type="number" inputmode="numeric" min="10" max="300" step="1" /></KolomIsian>
            <KolomIsian v-slot="k" label="Napas (kali/menit)" wajib><input v-bind="k" v-model="f.napas" class="isian" type="number" inputmode="numeric" min="2" max="100" step="1" /></KolomIsian>
            <KolomIsian v-slot="k" label="Suhu (°C)" wajib><input v-bind="k" v-model="f.suhu" class="isian" type="number" inputmode="decimal" min="25" max="45" step="0.1" /></KolomIsian>
            <KolomIsian v-slot="k" label="SpO2 (%)"><input v-bind="k" v-model="f.spo2" class="isian" type="number" inputmode="numeric" min="30" max="100" step="1" /></KolomIsian>
            <KolomIsian v-slot="k" label="Berat badan (kg)"><input v-bind="k" v-model="f.berat" class="isian" type="number" inputmode="decimal" min="0.3" max="400" step="0.1" /></KolomIsian>
            <KolomIsian v-slot="k" label="Tinggi badan (cm)"><input v-bind="k" v-model="f.tinggi" class="isian" type="number" inputmode="decimal" min="20" max="250" step="0.1" /></KolomIsian>
          </div>
          <p class="ket">IMT: <b>{{ asesmen?.imt != null ? asesmen.imt.toLocaleString('id-ID') : '—' }}</b> kg/m². Dihitung server dari berat dan tinggi badan yang tersimpan; nilainya baru berubah setelah disimpan.</p>
        </fieldset>

        <fieldset :disabled="!bisaUbah || undefined">
          <legend>Skrining</legend>
          <div class="grid-4">
            <KolomIsian v-slot="k" label="Skala nyeri (0–10)"><input v-bind="k" v-model="f.nyeri" class="isian" type="number" inputmode="numeric" min="0" max="10" step="1" /></KolomIsian>
            <KolomIsian v-slot="k" label="Lokasi nyeri"><input v-bind="k" v-model="f.lokasiNyeri" class="isian" maxlength="200" autocomplete="off" /></KolomIsian>
            <KolomIsian v-slot="k" label="Risiko jatuh">
              <select v-bind="k" v-model="f.jatuh" class="isian">
                <option value="">Belum dinilai</option>
                <option value="rendah">Rendah</option>
                <option value="sedang">Sedang</option>
                <option value="tinggi">Tinggi</option>
              </select>
            </KolomIsian>
            <KolomIsian v-slot="k" label="Gizi berisiko">
              <select v-bind="k" v-model="f.gizi" class="isian">
                <option value="">Belum dinilai</option>
                <option value="ya">Ya</option>
                <option value="tidak">Tidak</option>
              </select>
            </KolomIsian>
          </div>
          <KolomIsian v-slot="k" label="Catatan skrining" petunjuk="Kategori diisi perawat; skor otomatis menunggu templat dari data induk RS."><textarea v-bind="k" v-model="f.catatanSkrining" class="isian" rows="2" maxlength="1000"></textarea></KolomIsian>
        </fieldset>

        <fieldset :disabled="!bisaUbah || undefined">
          <legend>Catatan</legend>
          <KolomIsian v-slot="k" label="Catatan perawat"><textarea v-bind="k" v-model="f.catatan" class="isian" rows="2" maxlength="4000"></textarea></KolomIsian>
          <KolomIsian v-if="status === 'selesai'" v-slot="k" label="Alasan perubahan" wajib petunjuk="Minimal 5 karakter. Tercatat bersama versi lama.">
            <textarea v-bind="k" v-model="f.alasan" class="isian" rows="2" maxlength="500"></textarea>
          </KolomIsian>
        </fieldset>
      </form>

      <div role="alert">
        <Pesan v-if="galat" jenis="error">
          {{ galat.teks }}
          <ul v-if="galat.rincian.length" class="rincian"><li v-for="r in galat.rincian" :key="r">{{ r }}</li></ul>
        </Pesan>
      </div>

      <PanelAlergi :pasien-id="baris.pasien.id" :bisa-catat="bisaInput" :bisa-batal="bisaPerbaiki" />
    </div>

    <template #aksi>
      <Tombol :disabled="kerjakan.isPending.value || undefined" @click="buka = false">Tutup</Tombol>
      <template v-if="bisaUbah && !muatGalat">
        <Tombol v-if="status !== 'selesai'" :sibuk="kerjakan.isPending.value" @click="kerjakan.mutate(false)">{{ status === 'baru' ? 'Simpan sebagai draf' : 'Simpan draf' }}</Tombol>
        <Tombol v-if="status !== 'selesai'" varian="utama" :sibuk="kerjakan.isPending.value" @click="kerjakan.mutate(true)">Selesaikan asesmen</Tombol>
        <Tombol v-else varian="utama" :disabled="perluAlasan || undefined" :sibuk="kerjakan.isPending.value" @click="kerjakan.mutate(false)">Simpan perbaikan</Tombol>
      </template>
    </template>
  </Dialog>
</template>

<style scoped>
.isi,
.isi-form {
  display: grid;
  gap: var(--space-4);
}
fieldset {
  display: grid;
  gap: var(--space-3);
  margin: 0;
  padding: 0;
  border: 0;
  min-width: 0;
}
legend {
  padding: 0;
  margin-bottom: var(--space-2);
  font-weight: 600;
}
.grid-2 {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: var(--space-3);
}
.grid-4 {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: var(--space-3);
}
.ket {
  margin: 0;
  font-size: 12px;
  color: var(--muted);
}
.rincian {
  margin: var(--space-1) 0 0;
  padding-left: var(--space-4);
}
</style>
