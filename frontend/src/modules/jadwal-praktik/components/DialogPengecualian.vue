<script setup lang="ts">
import { ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import PemilihPegawai from '@/modules/sdm/components/PemilihPegawai.vue'
import { ApiError } from '@/shared/api/klien'
import { buatPengecualian, ubahPengecualian } from '../api'
import { hariIniLokal } from '../jadwal'
import type { JadwalPengecualian, JenisPengecualian } from '../tipe'

/**
 * Catat cuti dokter atau libur RS (POST /jadwal-pengecualian), atau batalkan pengecualian yang ada (`batal`, PATCH
 * status = batal). Pengecualian menutup slot di tanggal itu; kuotanya tidak bisa dipakai.
 */
const props = defineProps<{ batal: JadwalPengecualian | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: []; muatUlang: [] }>()

const jenis = ref<JenisPengecualian>('cuti')
const dokterId = ref<string | null>(null)
const dari = ref('')
const sampai = ref('')
const alasan = ref('')
const kesalahan = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const versiBerubah = ref(false)
const sibuk = ref(false)

watch(buka, (b) => {
  if (!b) return
  jenis.value = 'cuti'
  dokterId.value = null
  dari.value = hariIniLokal()
  sampai.value = hariIniLokal()
  alasan.value = ''
  kesalahan.value = {}
  error.value = null
  versiBerubah.value = false
})

async function kirim() {
  const e: Record<string, string> = {}
  if (!props.batal) {
    if (jenis.value === 'cuti' && !dokterId.value) e.pegawai_id = 'Pilih dokter yang cuti.'
    if (!dari.value) e.dari_tanggal = 'Isi tanggal mulai.'
    if (!sampai.value || sampai.value < dari.value) e.sampai_tanggal = 'Tanggal selesai tidak boleh sebelum tanggal mulai.'
  }
  if (alasan.value.trim().length < 3) e[props.batal ? 'alasan_batal' : 'alasan'] = 'Isi alasan, minimal 3 karakter.'
  kesalahan.value = e
  if (Object.keys(e).length) return
  sibuk.value = true
  error.value = null
  try {
    if (props.batal) await ubahPengecualian(props.batal.id, `"${props.batal.versi}"`, { status: 'batal', alasan_batal: alasan.value.trim() })
    else await buatPengecualian({ jenis: jenis.value, pegawai_id: jenis.value === 'cuti' ? dokterId.value : null, dari_tanggal: dari.value, sampai_tanggal: sampai.value, alasan: alasan.value.trim() })
    buka.value = false
    emit('selesai')
  } catch (err) {
    const kolom = errorKolom(err, ['pegawai_id', 'dari_tanggal', 'sampai_tanggal', 'alasan', 'alasan_batal'])
    if (Object.keys(kolom).length) kesalahan.value = kolom
    else if (err instanceof ApiError && err.status === 412) {
      versiBerubah.value = true
      error.value = 'Pengecualian ini diubah orang lain sejak dibuka. Muat ulang, lalu ulangi.'
    } else error.value = pesanError(err)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog
    v-model:open="buka"
    :judul="batal ? 'Batalkan pengecualian' : 'Catat cuti atau libur'"
    :uraian="batal ? `${batal.jenis === 'cuti' ? `Cuti ${batal.pegawai?.nama ?? ''}` : 'Libur RS'}, ${batal.dari_tanggal} sampai ${batal.sampai_tanggal}. Slot di rentang itu kembali tersedia.` : 'Slot jadwal di rentang tanggal ini tidak bisa dipakai.'"
    :sibuk="sibuk"
  >
    <form id="form-pengecualian" novalidate @submit.prevent="kirim">
      <template v-if="!batal">
        <KolomIsian v-slot="a" label="Jenis" wajib>
          <select v-bind="a" v-model="jenis" class="isian">
            <option value="cuti">Cuti dokter</option>
            <option value="libur">Libur RS (semua dokter)</option>
          </select>
        </KolomIsian>
        <KolomIsian v-if="jenis === 'cuti'" v-slot="a" label="Dokter" :error="kesalahan.pegawai_id" wajib>
          <PemilihPegawai v-bind="a" v-model="dokterId" />
        </KolomIsian>
        <div class="dua-kolom">
          <KolomIsian v-slot="a" label="Dari tanggal" :error="kesalahan.dari_tanggal" wajib><input v-bind="a" v-model="dari" type="date" class="isian" /></KolomIsian>
          <KolomIsian v-slot="a" label="Sampai tanggal" :error="kesalahan.sampai_tanggal" wajib><input v-bind="a" v-model="sampai" type="date" class="isian" /></KolomIsian>
        </div>
      </template>
      <KolomIsian v-slot="a" :label="batal ? 'Alasan pembatalan' : 'Alasan'" :error="kesalahan.alasan ?? kesalahan.alasan_batal" petunjuk="Nomor surat cuti, SK libur, atau memo." wajib>
        <input v-bind="a" v-model="alasan" class="isian" autocomplete="off" />
      </KolomIsian>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
      <button v-if="versiBerubah" type="button" class="tombol" @click="((buka = false), emit('muatUlang'))">Muat ulang</button>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Kembali</button>
      <Tombol type="submit" form="form-pengecualian" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : batal ? 'Batalkan pengecualian' : 'Catat' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.dua-kolom {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--space-3);
}
@media (max-width: 599.98px) {
  .dua-kolom {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
