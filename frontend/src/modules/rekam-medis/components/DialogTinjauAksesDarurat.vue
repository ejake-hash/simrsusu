<script setup lang="ts">
import { ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Pesan from '@/design-system/components/Pesan.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { errorKolom } from '@/modules/akses/pengaturan'
import { formatNoRm } from '@/modules/pendaftaran/pasien'
import { type AksesDarurat, type HasilTinjauan, tinjauAksesDarurat } from '@/shared/api/aksesDarurat'
import { LABEL_KATEGORI_ALASAN, pesanTinjauAksesDarurat } from '../aksesDarurat'

/** Tinjauan pasca-akses darurat (UC-33 langkah 3): hasil dan catatan wajib; sesudah disimpan tidak bisa diubah. */
const props = defineProps<{ akses: AksesDarurat | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [] }>()

const hasil = ref<HasilTinjauan>('wajar')
const catatan = ref('')
const kesalahan = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const sibuk = ref(false)

watch(buka, (b) => {
  if (!b) return
  hasil.value = 'wajar'
  catatan.value = ''
  kesalahan.value = {}
  error.value = null
})

async function kirim() {
  if (!props.akses) return
  if (catatan.value.trim().length < 5) {
    kesalahan.value = { catatan: 'Tulis catatan tinjauan, minimal 5 karakter.' }
    return
  }
  kesalahan.value = {}
  sibuk.value = true
  error.value = null
  try {
    await tinjauAksesDarurat(props.akses.id, { hasil: hasil.value, catatan: catatan.value.trim() })
    buka.value = false
    emit('selesai')
  } catch (err) {
    const kolom = errorKolom(err, ['hasil', 'catatan'])
    if (Object.keys(kolom).length) kesalahan.value = kolom
    else error.value = pesanTinjauAksesDarurat(err)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" judul="Tinjau akses darurat" :uraian="akses ? `${akses.pasien.nama}, No. RM ${formatNoRm(akses.pasien.no_rm)}, dibuka ${akses.pengguna.nama}. Tinjauan tidak dapat diubah setelah disimpan.` : undefined" :sibuk="sibuk">
    <form id="form-tinjau-akses" novalidate class="form" @submit.prevent="kirim">
      <p v-if="akses" class="alasan"><b>{{ LABEL_KATEGORI_ALASAN[akses.kategori_alasan] }}.</b> {{ akses.alasan }}</p>
      <KolomIsian v-slot="a" label="Hasil tinjauan" wajib :error="kesalahan.hasil" petunjuk="Tidak wajar ditindaklanjuti di luar sistem ini.">
        <select v-bind="a" v-model="hasil" class="isian">
          <option value="wajar">Wajar</option>
          <option value="tidak_wajar">Tidak wajar</option>
        </select>
      </KolomIsian>
      <KolomIsian v-slot="a" label="Catatan tinjauan" wajib :error="kesalahan.catatan">
        <textarea v-bind="a" v-model="catatan" class="isian" rows="3" maxlength="1000" />
      </KolomIsian>
      <div role="alert"><Pesan v-if="error" jenis="error">{{ error }}</Pesan></div>
    </form>
    <template #aksi>
      <Tombol :disabled="sibuk || undefined" @click="buka = false">Batal</Tombol>
      <Tombol type="submit" form="form-tinjau-akses" varian="utama" :sibuk="sibuk">Simpan tinjauan</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.form {
  display: grid;
  gap: var(--space-3);
}
.alasan {
  margin: 0;
  font-size: 13px;
  overflow-wrap: anywhere;
}
</style>
