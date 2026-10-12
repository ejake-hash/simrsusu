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
import { ubahJenisTugas } from '../api'
import type { JenisTugas, UbahJenisTugas } from '../tipe'

/**
 * Ubah jenis tugas (FR-53-04): nama, batas/tenggat, waktu pengingat–teguran–eskalasi, unit teguran dan eskalasi,
 * kritis, aktif. Waktu diisi dalam jam agar mudah dibaca, dikirim dalam menit. Berlaku untuk tugas yang dibuat
 * sesudahnya; tugas terbuka memakai aturan saat dibuat. PATCH hanya selisih; dasar perubahan wajib.
 */
const props = defineProps<{ jenis: Berversi<JenisTugas> | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: Berversi<JenisTugas>]; muatUlang: [] }>()

const nama = ref('')
const batasJam = ref<string | number>('')
const pengingatJam = ref<string | number>('')
const teguranJam = ref<string | number>('')
const eskalasiJam = ref<string | number>('')
const unitTeguran = ref<string | null>(null)
const labelTeguran = ref<string | null>(null)
const unitEskalasi = ref<string | null>(null)
const labelEskalasi = ref<string | null>(null)
const kritis = ref(false)
const aktif = ref(true)
const dasar = ref('')
const kesalahan = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const versiBerubah = ref(false)
const sibuk = ref(false)

const jam = (menit: number) => String(Math.round((menit / 60) * 100) / 100)
const objek = computed(() => props.jenis?.data.tenggat_dari === 'objek')

watch(buka, (b) => {
  if (!b || !props.jenis) return
  const j = props.jenis.data
  nama.value = j.nama
  batasJam.value = jam(j.batas_menit)
  pengingatJam.value = jam(-j.eskalasi.pengingat_menit)
  teguranJam.value = jam(j.eskalasi.teguran_menit)
  eskalasiJam.value = jam(j.eskalasi.eskalasi_menit)
  unitTeguran.value = j.unit_teguran?.id ?? null
  labelTeguran.value = j.unit_teguran ? `${j.unit_teguran.kode} · ${j.unit_teguran.nama}` : null
  unitEskalasi.value = j.unit_eskalasi?.id ?? null
  labelEskalasi.value = j.unit_eskalasi ? `${j.unit_eskalasi.kode} · ${j.unit_eskalasi.nama}` : null
  kritis.value = j.kritis
  aktif.value = j.aktif
  dasar.value = ''
  kesalahan.value = {}
  error.value = null
  versiBerubah.value = false
})

/** Jam (boleh desimal, koma atau titik) → menit bulat; null bila tidak sah. */
function menit(nilai: string | number): number | null {
  const n = Number(String(nilai).trim().replace(',', '.'))
  return String(nilai).trim() === '' || !Number.isFinite(n) ? null : Math.round(n * 60)
}

async function kirim() {
  if (!props.jenis) return
  const j = props.jenis.data
  const e: Record<string, string> = {}
  const batas = menit(batasJam.value)
  const pengingat = menit(pengingatJam.value)
  const teguran = menit(teguranJam.value)
  const eskalasi = menit(eskalasiJam.value)
  if (nama.value.trim().length < 3) e.nama = 'Nama minimal 3 karakter.'
  if (batas === null || batas < 1 || batas > 525600) e.batas_menit = 'Antara 1 menit dan 1 tahun (8.760 jam).'
  if (pengingat === null || pengingat < 1) e['eskalasi.pengingat_menit'] = 'Pengingat harus sebelum tenggat (lebih dari 0 jam).'
  if (teguran === null || teguran < 0) e['eskalasi.teguran_menit'] = 'Isi 0 jam atau lebih sesudah tenggat.'
  if (eskalasi === null || eskalasi < 0) e['eskalasi.eskalasi_menit'] = 'Isi 0 jam atau lebih sesudah tenggat.'
  else if (teguran !== null && eskalasi < teguran) e['eskalasi.eskalasi_menit'] = 'Eskalasi tidak boleh lebih awal dari teguran.'
  if (dasar.value.trim().length < 3) e.dasar_perubahan = 'Isi dasar perubahan, minimal 3 karakter.'
  kesalahan.value = e
  if (Object.keys(e).length) return

  const ubah: UbahJenisTugas = { dasar_perubahan: dasar.value.trim() }
  if (nama.value.trim() !== j.nama) ubah.nama = nama.value.trim()
  if (batas !== j.batas_menit) ubah.batas_menit = batas!
  const aturan = { pengingat_menit: -pengingat!, teguran_menit: teguran!, eskalasi_menit: eskalasi! }
  if (JSON.stringify(aturan) !== JSON.stringify(j.eskalasi)) ubah.eskalasi = aturan
  if (unitTeguran.value !== (j.unit_teguran?.id ?? null)) ubah.unit_teguran_id = unitTeguran.value
  if (unitEskalasi.value !== (j.unit_eskalasi?.id ?? null)) ubah.unit_eskalasi_id = unitEskalasi.value
  if (kritis.value !== j.kritis) ubah.kritis = kritis.value
  if (aktif.value !== j.aktif) ubah.aktif = aktif.value
  if (Object.keys(ubah).length < 2) {
    error.value = 'Belum ada perubahan untuk disimpan.'
    return
  }
  sibuk.value = true
  error.value = null
  try {
    const hasil = await ubahJenisTugas(j.id, props.jenis.etag, ubah)
    buka.value = false
    emit('selesai', hasil)
  } catch (err) {
    const kolom = errorKolom(err, ['nama', 'batas_menit', 'eskalasi.pengingat_menit', 'eskalasi.teguran_menit', 'eskalasi.eskalasi_menit', 'eskalasi', 'unit_teguran_id', 'unit_eskalasi_id', 'kritis', 'aktif', 'dasar_perubahan'])
    if (Object.keys(kolom).length) {
      kesalahan.value = kolom
      if (kolom.eskalasi) error.value = kolom.eskalasi
    } else if (err instanceof ApiError && err.status === 412) {
      versiBerubah.value = true
      error.value = 'Jenis tugas ini diubah orang lain sejak dibuka. Muat ulang, lalu ulangi.'
    } else error.value = pesanError(err)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="jenis ? `Ubah ${jenis.data.kode}` : 'Ubah jenis tugas'" :sibuk="sibuk" lebar="lebar">
    <form id="form-jenis-tugas" novalidate @submit.prevent="kirim">
      <KolomIsian v-slot="a" label="Nama" :error="kesalahan.nama" wajib>
        <input v-bind="a" v-model="nama" class="isian" autocomplete="off" maxlength="150" />
      </KolomIsian>
      <KolomIsian
        v-slot="a"
        :label="objek ? 'Tugas dibuat sebelum tanggal objek (jam)' : 'Tenggat sesudah tugas dibuat (jam)'"
        :error="kesalahan.batas_menit"
        :petunjuk="objek ? 'Mis. 720 jam = 30 hari sebelum izin praktik berakhir; tenggat = tanggal berakhirnya.' : 'Mis. 48 jam = 2 hari.'"
        wajib
      >
        <input v-bind="a" v-model="batasJam" class="isian" inputmode="decimal" autocomplete="off" />
      </KolomIsian>
      <fieldset class="eskalasi">
        <legend>Eskalasi (jam, terhadap tenggat)</legend>
        <div class="tiga-kolom">
          <KolomIsian v-slot="a" label="Pengingat sebelum" :error="kesalahan['eskalasi.pengingat_menit']" petunjuk="Tingkat 1, ke pelaksana." wajib>
            <input v-bind="a" v-model="pengingatJam" class="isian" inputmode="decimal" autocomplete="off" />
          </KolomIsian>
          <KolomIsian v-slot="a" label="Teguran sesudah" :error="kesalahan['eskalasi.teguran_menit']" petunjuk="Tingkat 2, ke kepala unit." wajib>
            <input v-bind="a" v-model="teguranJam" class="isian" inputmode="decimal" autocomplete="off" />
          </KolomIsian>
          <KolomIsian v-slot="a" label="Eskalasi sesudah" :error="kesalahan['eskalasi.eskalasi_menit']" petunjuk="Tingkat 3, ke kepala bidang + dasbor Direktur." wajib>
            <input v-bind="a" v-model="eskalasiJam" class="isian" inputmode="decimal" autocomplete="off" />
          </KolomIsian>
        </div>
      </fieldset>
      <div class="dua-kolom">
        <KolomIsian v-slot="a" label="Unit teguran (opsional)" :error="kesalahan.unit_teguran_id" petunjuk="Untuk tugas tanpa unit (mis. pelaksana peran); kosong = hanya unit tugas.">
          <PemilihUnit v-bind="a" v-model="unitTeguran" :label-terpilih="labelTeguran" opsi-kosong="Hanya unit tugas" />
        </KolomIsian>
        <KolomIsian v-slot="a" label="Unit eskalasi (opsional)" :error="kesalahan.unit_eskalasi_id" petunjuk="Kosong = unit induk dari unit teguran.">
          <PemilihUnit v-bind="a" v-model="unitEskalasi" :label-terpilih="labelEskalasi" opsi-kosong="Unit induk unit teguran" />
        </KolomIsian>
      </div>
      <label class="centang">
        <input v-model="kritis" type="checkbox" />
        <span>Kritis: notifikasinya tidak bisa dimatikan pengguna</span>
      </label>
      <label class="centang">
        <input v-model="aktif" type="checkbox" />
        <span>Aktif: kejadian baru membuat tugas jenis ini (tugas terbuka tetap berjalan)</span>
      </label>
      <KolomIsian v-slot="a" label="Dasar perubahan" :error="kesalahan.dasar_perubahan" petunjuk="Nomor SK atau kebijakan RS." wajib>
        <input v-bind="a" v-model="dasar" class="isian" autocomplete="off" />
      </KolomIsian>
      <p class="ket">Berlaku untuk tugas yang dibuat sesudah disimpan; tugas terbuka memakai aturan saat dibuat.</p>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
      <button v-if="versiBerubah" type="button" class="tombol muat-ulang" @click="((buka = false), emit('muatUlang'))">Muat ulang</button>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-jenis-tugas" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : 'Simpan' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.eskalasi {
  margin: 0 0 var(--space-3);
  padding: 0;
  border: 0;
}
legend {
  padding: 0;
  margin-bottom: var(--space-2);
  font-size: 13px;
  font-weight: var(--font-weight-semibold);
}
.tiga-kolom,
.dua-kolom {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
  align-items: start;
  gap: 0 var(--space-3);
}
.centang {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  min-height: 32px;
  margin-bottom: var(--space-2);
  font-size: 13px;
  cursor: pointer;
}
@media (min-width: 768px) and (max-width: 1279.98px) {
  .centang {
    min-height: 44px;
  }
}
.ket {
  margin: 0 0 var(--space-2);
  color: var(--muted);
  font-size: 12.5px;
}
.muat-ulang {
  margin-top: var(--space-2);
}
</style>
