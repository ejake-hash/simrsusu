<script setup lang="ts">
import { useForm } from 'vee-validate'
import { computed, ref, watch } from 'vue'
import { z } from 'zod'
import Dialog from '@/design-system/components/Dialog.vue'
import Sakelar from '@/design-system/components/Sakelar.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { skemaZod } from '@/design-system/form/skemaZod'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { ApiError } from '@/shared/api/klien'
import { buatPeran, ubahPeran, type PeranBerversi } from '../../apiPengaturan'
import { errorKolom, pesanError } from '../../pengaturan'
import type { PeranRinci } from '../../tipePengaturan'

/**
 * Buat peran (kosong atau salinan) dan ubah info peran (nama, uraian, wajib MFA, status). Dasar perubahan wajib.
 * Salinan tidak membawa izin sensitif (pemberiannya butuh persetujuan, tiket 1.2d).
 */
const props = defineProps<{ mode: 'buat' | 'salin' | 'ubah'; peran: PeranRinci | null; etag: string | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: PeranBerversi] }>()

const sibuk = ref(false)
const error = ref<string | null>(null)

// Satu skema untuk tiga mode: kode hanya diisi saat membuat, status hanya saat mengubah.
const skema = z
  .object({
    kode: z.string().trim().optional(),
    nama: z.string().trim().min(3, 'Nama minimal 3 karakter.').max(100),
    uraian: z.string().trim().max(500).optional(),
    wajib_mfa: z.boolean(),
    status: z.enum(['aktif', 'nonaktif']).optional(),
    dasar_perubahan: z.string().trim().min(3, 'Isi dasar perubahan, minimal 3 karakter.').max(200),
  })
  .refine((v) => props.mode === 'ubah' || /^[a-z][a-z0-9_]{1,49}$/.test(v.kode ?? ''), {
    path: ['kode'],
    message: 'Huruf kecil, angka, atau garis bawah; diawali huruf; 2–50 karakter.',
  })

const { defineField, handleSubmit, errors, resetForm, setErrors } = useForm({ validationSchema: skemaZod(skema) })
const [kode] = defineField('kode')
const [namaF] = defineField('nama')
const [uraianF] = defineField('uraian')
const [wajibMfa] = defineField<'wajib_mfa', boolean>('wajib_mfa')
const [status] = defineField('status')
const [dasarF] = defineField('dasar_perubahan')

watch(buka, (b) => {
  if (!b) return
  error.value = null
  const p = props.peran
  if (props.mode === 'ubah' && p) {
    resetForm({ values: { nama: p.nama, uraian: p.uraian ?? '', wajib_mfa: p.wajib_mfa, status: p.status, dasar_perubahan: '' } })
  } else if (props.mode === 'salin' && p) {
    resetForm({ values: { kode: `${p.kode}_salinan`.slice(0, 50), nama: `${p.nama} (salinan)`.slice(0, 100), uraian: p.uraian ?? '', wajib_mfa: p.wajib_mfa, dasar_perubahan: '' } })
  } else {
    resetForm({ values: { kode: '', nama: '', uraian: '', wajib_mfa: false, dasar_perubahan: '' } })
  }
})

const judul = computed(() => ({ buat: 'Buat peran', salin: `Salin peran ${props.peran?.nama ?? ''}`, ubah: `Ubah info peran ${props.peran?.nama ?? ''}` })[props.mode])
const mfaTerkunci = computed(() => props.mode === 'ubah' && (props.peran?.wajib_mfa_terkunci ?? false))
const statusTerkunci = computed(() => props.mode === 'ubah' && (props.peran?.bawaan_sistem ?? false))

const kirim = handleSubmit(async (v) => {
  sibuk.value = true
  error.value = null
  try {
    const uraianNilai = v.uraian ? v.uraian : null
    const hasil =
      props.mode === 'ubah'
        ? await ubahPeran(props.peran!.id, props.etag!, {
            nama: v.nama,
            uraian: uraianNilai,
            ...(mfaTerkunci.value ? {} : { wajib_mfa: v.wajib_mfa }),
            ...(statusTerkunci.value || !v.status ? {} : { status: v.status }),
            dasar_perubahan: v.dasar_perubahan,
          })
        : await buatPeran({
            kode: v.kode ?? '',
            nama: v.nama,
            uraian: uraianNilai,
            wajib_mfa: v.wajib_mfa,
            salin_dari_id: props.mode === 'salin' ? props.peran!.id : null,
            dasar_perubahan: v.dasar_perubahan,
          })
    buka.value = false
    emit('selesai', hasil)
  } catch (e) {
    const kolom = errorKolom(e, ['kode', 'nama', 'uraian', 'status', 'dasar_perubahan'])
    if (e instanceof ApiError && e.kode === 'kode_sudah_dipakai') kolom.kode = e.message
    if (Object.keys(kolom).length) setErrors(kolom)
    else error.value = e instanceof ApiError && e.status === 412 ? 'Peran ini diubah orang lain sejak dibuka. Tutup dialog dan muat ulang peran.' : pesanError(e)
  } finally {
    sibuk.value = false
  }
})
</script>

<template>
  <Dialog v-model:open="buka" :judul="judul" :sibuk="sibuk">
    <form id="form-info-peran" novalidate @submit.prevent="kirim">
      <p v-if="mode === 'salin'" class="catatan catatan-info" style="margin: 0 0 var(--space-4)">
        <Ikon nama="info" :ukuran="16" />
        <span>Izin disalin dari peran ini <b>kecuali izin sensitif</b>. Peran baru belum ditugaskan ke akun mana pun.</span>
      </p>
      <KolomIsian v-if="mode !== 'ubah'" v-slot="a" label="Kode peran" :error="errors.kode" petunjuk="Tidak bisa diubah setelah dibuat, mis. perawat_bangsal." wajib>
        <input v-bind="a" v-model="kode" class="isian kode" autocomplete="off" spellcheck="false" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Nama peran" :error="errors.nama" wajib>
        <input v-bind="a" v-model="namaF" class="isian" autocomplete="off" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Uraian (opsional)" :error="errors.uraian">
        <textarea v-bind="a" v-model="uraianF" class="isian" rows="2" />
      </KolomIsian>
      <div class="kolom-sakelar">
        <Sakelar
          v-model="wajibMfa"
          label="Wajib MFA untuk pemegang peran ini"
          :terkunci="mfaTerkunci"
          :alasan-terkunci="mfaTerkunci ? 'Tidak bisa dimatikan: peran superadmin atau memuat izin sensitif.' : undefined"
        />
      </div>
      <KolomIsian v-if="mode === 'ubah'" v-slot="a" label="Status" :error="errors.status" :petunjuk="statusTerkunci ? 'Peran bawaan sistem tidak bisa dinonaktifkan.' : 'Peran yang masih ditugaskan tidak bisa dinonaktifkan.'">
        <select v-bind="a" v-model="status" class="isian" :disabled="statusTerkunci">
          <option value="aktif">Aktif</option>
          <option value="nonaktif">Nonaktif</option>
        </select>
      </KolomIsian>
      <KolomIsian v-slot="a" label="Dasar perubahan" :error="errors.dasar_perubahan" petunjuk="Nomor SK, memo, atau persetujuan." wajib>
        <input v-bind="a" v-model="dasarF" class="isian" autocomplete="off" />
      </KolomIsian>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <button type="submit" form="form-info-peran" class="tombol tombol-utama" :disabled="sibuk">
        {{ sibuk ? 'Menyimpan…' : mode === 'ubah' ? 'Simpan' : 'Buat peran' }}
      </button>
    </template>
  </Dialog>
</template>

<style scoped>
.kode {
  font-family: var(--font-mono);
}
.kolom-sakelar {
  margin-bottom: var(--space-4);
}
</style>
