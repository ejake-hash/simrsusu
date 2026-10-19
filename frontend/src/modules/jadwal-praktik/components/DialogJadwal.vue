<script setup lang="ts">
import { ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import type { Berversi } from '@/modules/organisasi/api'
import PemilihLokasi from '@/modules/organisasi/components/PemilihLokasi.vue'
import PemilihPegawai from '@/modules/sdm/components/PemilihPegawai.vue'
import { ApiError } from '@/shared/api/klien'
import { buatJadwal, ubahJadwal } from '../api'
import { NAMA_HARI, hariIniLokal } from '../jadwal'
import type { JadwalPraktik, UbahJadwal } from '../tipe'

/**
 * Tambah atau ubah jadwal praktik (POST/PATCH /jadwal-praktik). Dokter dan poli tidak diubah sesudah dibuat (kontrak:
 * nonaktifkan lalu buat jadwal baru). Mode `nonaktif` hanya meminta dasar lalu mengirim status.
 */
const props = defineProps<{ jadwal: Berversi<JadwalPraktik> | null; poli: { id: string; kode: string; nama: string }[]; ubahStatus?: 'aktif' | 'nonaktif' | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: []; muatUlang: [] }>()

const dokterId = ref<string | null>(null)
const poliId = ref('')
const ruangId = ref<string | null>(null)
const labelRuang = ref<string | null>(null)
const hari = ref(1)
const jamMulai = ref('08:00')
const jamSelesai = ref('12:00')
const kuotaJkn = ref(0)
const kuotaNon = ref(0)
const dari = ref('')
const sampai = ref('')
const dasar = ref('')
const kesalahan = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const versiBerubah = ref(false)
const sibuk = ref(false)

watch(buka, (b) => {
  if (!b) return
  const j = props.jadwal?.data
  dokterId.value = j?.pegawai.id ?? null
  poliId.value = j?.poliklinik.id ?? ''
  ruangId.value = j?.ruang?.id ?? null
  labelRuang.value = j?.ruang ? `${j.ruang.kode_lengkap} · ${j.ruang.nama}` : null
  hari.value = j?.hari ?? 1
  jamMulai.value = j?.jam_mulai ?? '08:00'
  jamSelesai.value = j?.jam_selesai ?? '12:00'
  kuotaJkn.value = j?.kuota_jkn ?? 0
  kuotaNon.value = j?.kuota_non_jkn ?? 0
  dari.value = j?.berlaku_dari ?? hariIniLokal()
  sampai.value = j?.berlaku_sampai ?? ''
  dasar.value = ''
  kesalahan.value = {}
  error.value = null
  versiBerubah.value = false
})

const KOLOM = ['pegawai_id', 'poli_id', 'ruang_id', 'hari', 'jam_mulai', 'jam_selesai', 'kuota_jkn', 'kuota_non_jkn', 'berlaku_dari', 'berlaku_sampai', 'dasar_perubahan']
async function kirim() {
  const e: Record<string, string> = {}
  const status = props.ubahStatus
  if (!status) {
    if (!props.jadwal && !dokterId.value) e.pegawai_id = 'Pilih dokter.'
    if (!props.jadwal && !poliId.value) e.poli_id = 'Pilih poliklinik.'
    if (jamSelesai.value <= jamMulai.value) e.jam_selesai = 'Jam selesai harus sesudah jam mulai.'
    if (!dari.value) e.berlaku_dari = 'Isi tanggal mulai berlaku.'
    if (sampai.value && sampai.value < dari.value) e.berlaku_sampai = 'Tidak boleh sebelum tanggal mulai.'
    for (const [k, n] of [['kuota_jkn', kuotaJkn.value], ['kuota_non_jkn', kuotaNon.value]] as const) {
      if (!Number.isInteger(n) || n < 0 || n > 1000) e[k] = 'Bilangan bulat 0–1000.'
    }
  }
  if (dasar.value.trim().length < 3) e.dasar_perubahan = 'Isi dasar perubahan, minimal 3 karakter.'
  kesalahan.value = e
  if (Object.keys(e).length) return
  sibuk.value = true
  error.value = null
  const berlakuSampai = sampai.value || null
  try {
    if (props.jadwal) {
      const j = props.jadwal.data
      const ubah: UbahJadwal = { dasar_perubahan: dasar.value.trim() }
      if (status) ubah.status = status
      else {
        if (ruangId.value !== (j.ruang?.id ?? null)) ubah.ruang_id = ruangId.value
        if (hari.value !== j.hari) ubah.hari = hari.value
        if (jamMulai.value !== j.jam_mulai) ubah.jam_mulai = jamMulai.value
        if (jamSelesai.value !== j.jam_selesai) ubah.jam_selesai = jamSelesai.value
        if (kuotaJkn.value !== j.kuota_jkn) ubah.kuota_jkn = kuotaJkn.value
        if (kuotaNon.value !== j.kuota_non_jkn) ubah.kuota_non_jkn = kuotaNon.value
        if (dari.value !== j.berlaku_dari) ubah.berlaku_dari = dari.value
        if (berlakuSampai !== j.berlaku_sampai) ubah.berlaku_sampai = berlakuSampai
      }
      if (Object.keys(ubah).length < 2) {
        error.value = 'Belum ada perubahan untuk disimpan.'
        return
      }
      await ubahJadwal(j.id, props.jadwal.etag, ubah)
    } else {
      await buatJadwal({
        pegawai_id: dokterId.value!, poli_id: poliId.value, ruang_id: ruangId.value, hari: hari.value, jam_mulai: jamMulai.value, jam_selesai: jamSelesai.value,
        kuota_jkn: kuotaJkn.value, kuota_non_jkn: kuotaNon.value, berlaku_dari: dari.value, berlaku_sampai: berlakuSampai, dasar_perubahan: dasar.value.trim(),
      })
    }
    buka.value = false
    emit('selesai')
  } catch (err) {
    const kolom = errorKolom(err, KOLOM)
    if (Object.keys(kolom).length) kesalahan.value = kolom
    else if (err instanceof ApiError && err.status === 412) {
      versiBerubah.value = true
      error.value = 'Jadwal ini diubah orang lain sejak dibuka. Muat ulang, lalu ulangi.'
    } else error.value = pesanError(err)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog
    v-model:open="buka"
    :judul="ubahStatus ? (ubahStatus === 'nonaktif' ? 'Nonaktifkan jadwal praktik' : 'Aktifkan jadwal praktik') : jadwal ? 'Ubah jadwal praktik' : 'Tambah jadwal praktik'"
    :uraian="ubahStatus === 'nonaktif' ? 'Jadwal tidak dihapus; slot di tanggal mendatang tidak lagi tersedia.' : undefined"
    :sibuk="sibuk"
    lebar="lebar"
  >
    <form id="form-jadwal" novalidate @submit.prevent="kirim">
      <template v-if="!ubahStatus">
        <KolomIsian v-slot="a" label="Dokter" :error="kesalahan.pegawai_id" petunjuk="Harus punya SIP aktif pada tanggal mulai berlaku." wajib>
          <PemilihPegawai v-if="!jadwal" v-bind="a" v-model="dokterId" />
          <input v-else v-bind="a" class="isian" :value="jadwal.data.pegawai.nama" readonly />
        </KolomIsian>
        <KolomIsian v-slot="a" label="Poliklinik" :error="kesalahan.poli_id" wajib>
          <select v-bind="a" v-model="poliId" class="isian" :disabled="!!jadwal">
            <option value="" disabled>Pilih poliklinik</option>
            <option v-for="p in poli" :key="p.id" :value="p.id">{{ p.kode }} · {{ p.nama }}</option>
          </select>
        </KolomIsian>
        <KolomIsian v-slot="a" label="Ruang (opsional)" :error="kesalahan.ruang_id" petunjuk="Kosong = ruang poliklinik.">
          <PemilihLokasi v-bind="a" v-model="ruangId" :jenis="['ruang']" :label-terpilih="labelRuang" opsi-kosong="Ruang poliklinik" />
        </KolomIsian>
        <div class="tiga-kolom">
          <KolomIsian v-slot="a" label="Hari" :error="kesalahan.hari" wajib>
            <select v-bind="a" v-model.number="hari" class="isian">
              <option v-for="(n, i) in NAMA_HARI" :key="n" :value="i + 1">{{ n }}</option>
            </select>
          </KolomIsian>
          <KolomIsian v-slot="a" label="Jam mulai" :error="kesalahan.jam_mulai" wajib><input v-bind="a" v-model="jamMulai" type="time" class="isian" /></KolomIsian>
          <KolomIsian v-slot="a" label="Jam selesai" :error="kesalahan.jam_selesai" wajib><input v-bind="a" v-model="jamSelesai" type="time" class="isian" /></KolomIsian>
        </div>
        <div class="dua-kolom">
          <KolomIsian v-slot="a" label="Kuota JKN" :error="kesalahan.kuota_jkn" wajib><input v-bind="a" v-model.number="kuotaJkn" type="number" min="0" max="1000" class="isian" /></KolomIsian>
          <KolomIsian v-slot="a" label="Kuota non-JKN" :error="kesalahan.kuota_non_jkn" wajib><input v-bind="a" v-model.number="kuotaNon" type="number" min="0" max="1000" class="isian" /></KolomIsian>
        </div>
        <div class="dua-kolom">
          <KolomIsian v-slot="a" label="Berlaku dari" :error="kesalahan.berlaku_dari" wajib><input v-bind="a" v-model="dari" type="date" class="isian" /></KolomIsian>
          <KolomIsian v-slot="a" label="Berlaku sampai (opsional)" :error="kesalahan.berlaku_sampai"><input v-bind="a" v-model="sampai" type="date" class="isian" /></KolomIsian>
        </div>
      </template>
      <KolomIsian v-slot="a" label="Dasar perubahan" :error="kesalahan.dasar_perubahan" petunjuk="Nomor SK, surat tugas, atau memo." wajib>
        <input v-bind="a" v-model="dasar" class="isian" autocomplete="off" />
      </KolomIsian>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
      <button v-if="versiBerubah" type="button" class="tombol" @click="((buka = false), emit('muatUlang'))">Muat ulang</button>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-jadwal" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : ubahStatus ? (ubahStatus === 'nonaktif' ? 'Nonaktifkan' : 'Aktifkan') : jadwal ? 'Simpan' : 'Tambah jadwal' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.dua-kolom {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--space-3);
}
.tiga-kolom {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: var(--space-3);
}
@media (max-width: 599.98px) {
  .dua-kolom,
  .tiga-kolom {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
