<script setup lang="ts">
import { ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { ApiError } from '@/shared/api/klien'
import { buatUsulan, kirimUlangUsulan } from '../api'
import type { ButirUsulan, IsianButir, UsulanDataInduk } from '../tipe'
import EditorButir from './EditorButir.vue'

/**
 * Ajukan usulan baru (jenis data ruang & TT) atau perbaiki lalu kirim ulang usulan yang dikembalikan (`usulan` diisi;
 * isi menggantikan isi sebelumnya, putaran +1). Satu usulan = 1–50 perubahan dengan satu tanggal berlaku (≥ hari ini,
 * zona RS), alasan, dan dasar. Galat per butir dari server (`perubahan.<i>[.<isian>]`) tampil di butirnya; 409
 * `objek_sedang_diusulkan` menyebut usulan yang menghalangi.
 */
const props = withDefaults(defineProps<{ usulan?: UsulanDataInduk | null }>(), { usulan: null })
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: UsulanDataInduk] }>()

interface Draf {
  kunci: number
  awal: ButirUsulan | null
}
let urut = 0
const butir = ref<Draf[]>([])
const editor = ref<InstanceType<typeof EditorButir>[]>([])
const berlaku = ref('')
const alasan = ref('')
const dasar = ref('')
const kesalahan = ref<Record<string, string>>({})
const galatButir = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const sibuk = ref(false)

const hariIni = () => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
watch(buka, (b) => {
  if (!b) return
  const u = props.usulan
  butir.value = u ? u.perubahan.map((x) => ({ kunci: ++urut, awal: x })) : [{ kunci: ++urut, awal: null }]
  berlaku.value = u && u.berlaku_dari >= hariIni() ? u.berlaku_dari : ''
  alasan.value = u?.alasan ?? ''
  dasar.value = u?.dasar ?? ''
  kesalahan.value = {}
  galatButir.value = {}
  error.value = null
})

function tambah() {
  if (butir.value.length < 50) butir.value.push({ kunci: ++urut, awal: null })
}
function hapus(i: number) {
  butir.value.splice(i, 1)
}

async function kirim() {
  const e: Record<string, string> = {}
  if (!berlaku.value) e.berlaku_dari = 'Pilih tanggal berlaku.'
  else if (berlaku.value < hariIni()) e.berlaku_dari = 'Tidak boleh sebelum hari ini.'
  if (alasan.value.trim().length < 5) e.alasan = 'Isi alasan, minimal 5 karakter.'
  if (dasar.value.trim().length < 3) e.dasar = 'Isi dasar, minimal 3 karakter.'
  kesalahan.value = e
  const gb: Record<string, string> = {}
  const perubahan: IsianButir[] = []
  if (!butir.value.length) error.value = 'Tambahkan minimal satu perubahan.'
  butir.value.forEach((_, i) => {
    const h = editor.value[i]?.bentuk()
    if (typeof h === 'string') gb[`perubahan.${i}`] = h
    else if (h) perubahan.push(h)
  })
  galatButir.value = gb
  if (Object.keys(e).length || Object.keys(gb).length || !butir.value.length) return
  sibuk.value = true
  error.value = null
  try {
    const isi = { perubahan, berlaku_dari: berlaku.value, alasan: alasan.value.trim(), dasar: dasar.value.trim() }
    const hasil = props.usulan ? await kirimUlangUsulan(props.usulan.id, isi) : await buatUsulan({ jenis_data: 'ruang_tt', ...isi })
    buka.value = false
    emit('selesai', hasil)
  } catch (err) {
    if (err instanceof ApiError && err.rincian.length) {
      const kolom: Record<string, string> = {}
      const butirSalah: Record<string, string> = {}
      for (const r of err.rincian) {
        const k = String(r.kolom ?? '')
        const pesan = String(r.pesan ?? r.kode ?? 'Tidak valid.') + (r.usulan && typeof r.usulan === 'object' ? ` (${(r.usulan as { nomor: string }).nomor})` : '')
        if (k.startsWith('perubahan.')) butirSalah[k] = pesan
        else if (k) kolom[k] = pesan
      }
      kesalahan.value = kolom
      galatButir.value = butirSalah
      error.value = err.message
    } else error.value = pesanError(err)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="usulan ? `Perbaiki dan kirim ulang ${usulan.nomor}` : 'Ajukan usulan perubahan'" :sibuk="sibuk" lebar="lebar">
    <form id="form-usulan" novalidate @submit.prevent="kirim">
      <p class="ket">Ruang &amp; tempat tidur. Setiap perubahan diperiksa dengan aturan data induk yang sama dan sekali lagi saat diterapkan.</p>
      <EditorButir
        v-for="(b, i) in butir"
        :key="b.kunci"
        ref="editor"
        :nomor="i"
        :awal="b.awal"
        :galat="galatButir"
        @hapus="hapus(i)"
      />
      <button type="button" class="tombol" :disabled="butir.length >= 50" @click="tambah"><Ikon nama="tambah" :ukuran="16" />Tambah perubahan</button>
      <div class="kisi">
        <KolomIsian v-slot="a" label="Berlaku mulai" :error="kesalahan.berlaku_dari" petunjuk="Diterapkan pukul 00.00 waktu RS." wajib>
          <input v-bind="a" v-model="berlaku" type="date" class="isian" :min="hariIni()" />
        </KolomIsian>
        <KolomIsian v-slot="a" label="Dasar" :error="kesalahan.dasar" petunjuk="Nomor SK, memo, atau surat." wajib>
          <input v-bind="a" v-model="dasar" class="isian" autocomplete="off" maxlength="200" />
        </KolomIsian>
      </div>
      <KolomIsian v-slot="a" label="Alasan" :error="kesalahan.alasan" wajib>
        <textarea v-bind="a" v-model="alasan" class="isian" rows="2" maxlength="500" />
      </KolomIsian>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-usulan" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Mengirim…' : usulan ? 'Kirim ulang' : 'Ajukan usulan' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.ket {
  margin: 0 0 var(--space-3);
  color: var(--muted);
  font-size: 12.5px;
}
.kisi {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  align-items: start;
  gap: 0 var(--space-3);
  margin-top: var(--space-3);
}
</style>
