<script setup lang="ts">
import { useForm } from 'vee-validate'
import { computed, watch } from 'vue'
import { z } from 'zod'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { skemaZod } from '@/design-system/form/skemaZod'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { izinSensitif, labelIzin, type IndeksMatriks } from '../../matriks'

/**
 * Ringkasan sebelum menyimpan matriks (MD-2): yang ditambah, yang dicabut, akun terdampak, dan dasar perubahan
 * wajib. Error dari server (409/412/422) ditampilkan di dalam dialog agar suntingan tidak hilang.
 */
const props = defineProps<{
  namaPeran: string
  indeks: IndeksMatriks
  ditambah: string[]
  dicabut: string[]
  akunTerdampak: number
  sibuk: boolean
  error: string | null
}>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ simpan: [isian: { dasar_perubahan: string; alasan: string | null }] }>()

const skema = z.object({
  dasar_perubahan: z.string().trim().min(3, 'Isi dasar perubahan (nomor SK, memo, atau persetujuan), minimal 3 karakter.').max(200),
  alasan: z.string().trim().max(500).optional(),
})
const { defineField, handleSubmit, errors, resetForm, setErrors } = useForm({ validationSchema: skemaZod(skema) })
const [dasar] = defineField('dasar_perubahan')
const [alasan] = defineField('alasan')

watch(buka, (b) => {
  if (b) resetForm({ values: { dasar_perubahan: '', alasan: '' } })
})

const sensitifBaru = computed(() => props.ditambah.filter((k) => izinSensitif(props.indeks, k)))

const kirim = handleSubmit((v) => emit('simpan', { dasar_perubahan: v.dasar_perubahan, alasan: v.alasan ? v.alasan : null }))
defineExpose({ setErrors })
</script>

<template>
  <Dialog v-model:open="buka" :judul="`Simpan perubahan izin ${namaPeran}?`" uraian="Periksa ringkasan di bawah. Perubahan berlaku untuk semua akun yang memegang peran ini." :sibuk="sibuk" peringatan lebar="lebar">
    <form id="form-simpan-izin" novalidate @submit.prevent="kirim">
      <div class="ringkasan">
        <section>
          <h3>Ditambah ({{ ditambah.length }})</h3>
          <ul v-if="ditambah.length">
            <li v-for="k in ditambah" :key="k">
              <Ikon v-if="izinSensitif(indeks, k)" nama="gembok" :ukuran="14" />
              {{ labelIzin(indeks, k) }}<span v-if="izinSensitif(indeks, k)" class="pil pil-peringatan">sensitif</span>
            </li>
          </ul>
          <p v-else class="kosong">Tidak ada.</p>
        </section>
        <section>
          <h3>Dicabut ({{ dicabut.length }})</h3>
          <ul v-if="dicabut.length">
            <li v-for="k in dicabut" :key="k">{{ labelIzin(indeks, k) }}</li>
          </ul>
          <p v-else class="kosong">Tidak ada.</p>
        </section>
      </div>
      <p class="catatan catatan-netral">
        <Ikon nama="pengguna" :ukuran="16" />
        <span><b>{{ akunTerdampak }} akun</b> memegang peran ini. Menu dan tombol mereka berubah pada permintaan berikutnya.</span>
      </p>
      <p v-if="sensitifBaru.length" class="catatan catatan-peringatan">
        <Ikon nama="gembok" :ukuran="16" />
        <span>
          {{ sensitifBaru.length }} izin sensitif butuh persetujuan pemilik data. Sampai alur persetujuan tersedia, server menolak penyimpanan ini
          seluruhnya; hapus centang izin bergembok untuk menyimpan sisanya.
        </span>
      </p>

      <div class="kolom-form">
        <KolomIsian v-slot="a" label="Dasar perubahan" :error="errors.dasar_perubahan" petunjuk="Nomor SK, surat tugas, memo, atau persetujuan." wajib>
          <input v-bind="a" v-model="dasar" class="isian" autocomplete="off" />
        </KolomIsian>
        <KolomIsian v-slot="a" label="Alasan (opsional)" :error="errors.alasan">
          <textarea v-bind="a" v-model="alasan" class="isian" rows="2" />
        </KolomIsian>
      </div>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Kembali menyunting</button>
      <Tombol type="submit" form="form-simpan-izin" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : 'Simpan perubahan' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.ringkasan {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: var(--space-4);
}
h3 {
  margin: 0 0 6px;
  font-size: 13px;
}
ul {
  max-height: 180px;
  overflow-y: auto;
  margin: 0;
  padding-left: 18px;
  font-size: 13px;
}
li {
  margin-bottom: 3px;
}
li svg {
  vertical-align: -2px;
  color: var(--warn);
}
li .pil {
  margin-left: 6px;
}
.kosong {
  margin: 0;
  font-size: 13px;
  color: var(--muted);
}
.kolom-form {
  margin-top: var(--space-4);
}
</style>
