<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import type { Berversi } from '@/modules/organisasi/api'
import PemilihUnit from '@/modules/organisasi/components/PemilihUnit.vue'
import { ApiError } from '@/shared/api/klien'
import { boleh } from '@/shared/composables/usePermission'
import { buatPegawai, ubahPegawai } from '../api'
import { PIL_PROFESI } from '../sdm'
import type { PegawaiRinci, Profesi, UbahPegawai } from '../tipe'

/**
 * Tambah atau ubah data pegawai (tanpa pencatatan keluar; itu di DialogKeluar). NIK hanya bisa diisi pemegang izin
 * bagian `sdm.pegawai.identitas.lihat`: tanpa itu kolomnya tidak dirender dan tidak dikirim. Saat ubah, NIK kosong =
 * tidak diubah (NIK lama tidak pernah dimuat ke formulir). PATCH hanya mengirim kolom yang berubah.
 */
const props = defineProps<{ pegawai: Berversi<PegawaiRinci> | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: Berversi<PegawaiRinci>]; muatUlang: [] }>()

const nama = ref('')
const nip = ref('')
const nik = ref('')
const profesi = ref<Profesi | ''>('')
const jabatan = ref('')
const jenisKepegawaian = ref('')
const unitId = ref<string | null>(null)
const labelUnit = ref<string | null>(null)
const tmt = ref('')
const dasar = ref('')
const kesalahan = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const versiBerubah = ref(false)
const sibuk = ref(false)

const bolehNik = computed(() => (props.pegawai ? props.pegawai.data.nik_dapat_dilihat : boleh('sdm.pegawai.identitas.lihat')))

watch(buka, (b) => {
  if (!b) return
  const p = props.pegawai?.data
  nama.value = p?.nama ?? ''
  nip.value = p?.nip ?? ''
  nik.value = ''
  profesi.value = p?.profesi ?? ''
  jabatan.value = p?.jabatan ?? ''
  jenisKepegawaian.value = p?.jenis_kepegawaian ?? ''
  unitId.value = p?.unit?.id ?? null
  labelUnit.value = p?.unit ? `${p.unit.kode} · ${p.unit.nama}` : null
  tmt.value = p?.tmt ?? ''
  dasar.value = ''
  kesalahan.value = {}
  error.value = null
  versiBerubah.value = false
})

const teks = (v: string) => v.trim() || null

async function kirim() {
  const e: Record<string, string> = {}
  if (nama.value.trim().length < 2) e.nama = 'Nama minimal 2 karakter.'
  if (bolehNik.value && nik.value.trim() && !/^[0-9]{16}$/.test(nik.value.trim())) e.nik = 'NIK 16 digit angka.'
  if (dasar.value.trim().length < 3) e.dasar_perubahan = 'Isi dasar perubahan, minimal 3 karakter.'
  kesalahan.value = e
  if (Object.keys(e).length) return
  sibuk.value = true
  error.value = null
  try {
    let hasil: Berversi<PegawaiRinci>
    if (props.pegawai) {
      const p = props.pegawai.data
      const ubah: UbahPegawai = { dasar_perubahan: dasar.value.trim() }
      if (nama.value.trim() !== p.nama) ubah.nama = nama.value.trim()
      if (teks(nip.value) !== p.nip) ubah.nip = teks(nip.value)
      if (bolehNik.value && nik.value.trim()) ubah.nik = nik.value.trim()
      if ((profesi.value || null) !== p.profesi) ubah.profesi = profesi.value || null
      if (teks(jabatan.value) !== p.jabatan) ubah.jabatan = teks(jabatan.value)
      if (teks(jenisKepegawaian.value) !== p.jenis_kepegawaian) ubah.jenis_kepegawaian = teks(jenisKepegawaian.value)
      if (unitId.value !== (p.unit?.id ?? null)) ubah.unit_organisasi_id = unitId.value
      if ((tmt.value || null) !== p.tmt) ubah.tmt = tmt.value || null
      if (Object.keys(ubah).length < 2) {
        error.value = 'Belum ada perubahan untuk disimpan.'
        return
      }
      hasil = await ubahPegawai(p.id, props.pegawai.etag, ubah)
    } else {
      hasil = await buatPegawai({
        nama: nama.value.trim(),
        nip: teks(nip.value),
        ...(bolehNik.value && nik.value.trim() ? { nik: nik.value.trim() } : {}),
        profesi: profesi.value || null,
        jabatan: teks(jabatan.value),
        jenis_kepegawaian: teks(jenisKepegawaian.value),
        unit_organisasi_id: unitId.value,
        tmt: tmt.value || null,
        dasar_perubahan: dasar.value.trim(),
      })
    }
    nik.value = ''
    buka.value = false
    emit('selesai', hasil)
  } catch (err) {
    const kolom = errorKolom(err, ['nama', 'nip', 'nik', 'profesi', 'jabatan', 'jenis_kepegawaian', 'unit_organisasi_id', 'tmt', 'dasar_perubahan'])
    if (Object.keys(kolom).length) {
      kesalahan.value = kolom
      // NIK tidak dirender tanpa izin identitas: pesannya tetap tampil.
      if (kolom.nik && !bolehNik.value) error.value = kolom.nik
    } else if (err instanceof ApiError && err.status === 412) {
      versiBerubah.value = true
      error.value = 'Data pegawai ini diubah orang lain sejak dibuka. Muat ulang, lalu ulangi.'
    } else error.value = pesanError(err)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="pegawai ? `Ubah data ${pegawai.data.nama}` : 'Tambah pegawai'" :sibuk="sibuk" lebar="lebar">
    <form id="form-pegawai" novalidate @submit.prevent="kirim">
      <KolomIsian v-slot="a" label="Nama lengkap" :error="kesalahan.nama" wajib>
        <input v-bind="a" v-model="nama" class="isian" autocomplete="off" maxlength="150" />
      </KolomIsian>
      <div class="dua-kolom">
        <KolomIsian v-slot="a" label="NIP (opsional)" :error="kesalahan.nip">
          <input v-bind="a" v-model="nip" class="isian kode" autocomplete="off" spellcheck="false" maxlength="30" />
        </KolomIsian>
        <KolomIsian
          v-if="bolehNik"
          v-slot="a"
          label="NIK (opsional)"
          :error="kesalahan.nik"
          :petunjuk="pegawai ? `Kosong = tidak diubah${pegawai.data.nik_samaran ? ` (tercatat ${pegawai.data.nik_samaran})` : ''}.` : '16 digit; disimpan terenkripsi.'"
        >
          <input v-bind="a" v-model="nik" class="isian kode" autocomplete="off" spellcheck="false" inputmode="numeric" maxlength="16" />
        </KolomIsian>
      </div>
      <div class="dua-kolom">
        <KolomIsian v-slot="a" label="Profesi" :error="kesalahan.profesi" petunjuk="Menentukan syarat izin praktik.">
          <select v-bind="a" v-model="profesi" class="isian">
            <option value="">Belum diisi</option>
            <option v-for="[nilai, label] in PIL_PROFESI" :key="nilai" :value="nilai">{{ label }}</option>
          </select>
        </KolomIsian>
        <KolomIsian v-slot="a" label="Jabatan (opsional)" :error="kesalahan.jabatan">
          <input v-bind="a" v-model="jabatan" class="isian" autocomplete="off" maxlength="150" />
        </KolomIsian>
      </div>
      <div class="dua-kolom">
        <KolomIsian v-slot="a" label="Jenis kepegawaian (opsional)" :error="kesalahan.jenis_kepegawaian" petunjuk="Mis. PNS, PPPK, pegawai BLU, kontrak.">
          <input v-bind="a" v-model="jenisKepegawaian" class="isian" autocomplete="off" maxlength="50" />
        </KolomIsian>
        <KolomIsian v-slot="a" label="TMT (opsional)" :error="kesalahan.tmt" petunjuk="Terhitung mulai tanggal.">
          <input v-bind="a" v-model="tmt" type="date" class="isian" />
        </KolomIsian>
      </div>
      <KolomIsian v-slot="a" label="Unit kerja (opsional)" :error="kesalahan.unit_organisasi_id">
        <PemilihUnit v-bind="a" v-model="unitId" :label-terpilih="labelUnit" opsi-kosong="Belum ditetapkan" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Dasar perubahan" :error="kesalahan.dasar_perubahan" petunjuk="Nomor SK, memo, atau surat tugas." wajib>
        <input v-bind="a" v-model="dasar" class="isian" autocomplete="off" />
      </KolomIsian>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
      <button v-if="versiBerubah" type="button" class="tombol muat-ulang" @click="((buka = false), emit('muatUlang'))">Muat ulang</button>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-pegawai" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : pegawai ? 'Simpan' : 'Tambah pegawai' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.dua-kolom {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  align-items: start;
  gap: 0 var(--space-3);
}
.kode {
  font-family: var(--font-mono);
}
.muat-ulang {
  margin-top: var(--space-2);
}
</style>
