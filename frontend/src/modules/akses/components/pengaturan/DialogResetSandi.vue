<script setup lang="ts">
import { useForm } from 'vee-validate'
import { computed, ref, watch } from 'vue'
import { z } from 'zod'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { skemaZod } from '@/design-system/form/skemaZod'
import Ikon from '@/design-system/ikon/Ikon.vue'
import SandiSekali from '@/shared/components/SandiSekali.vue'
import { resetSandiAkun } from '../../apiPengaturan'
import { errorKolom, pesanError } from '../../pengaturan'
import type { AkunRingkas } from '../../tipePengaturan'

/**
 * Reset sandi akun oleh admin (tiket 1.14, kontrak v0.15.0): alasan + dasar wajib, lalu sandi sementara ditampilkan
 * SEKALI dengan tombol salin. Sandi hanya hidup di komponen ini selama dialog terbuka; ditutup = dibuang (tidak ke
 * cache kueri, penyimpanan peramban, pesan, atau log). Akun wajib mengganti sandi saat masuk berikutnya.
 */
const props = defineProps<{ akunId: string; namaAkun: string; namaPengguna: string }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [akun: AkunRingkas] }>()

const skema = skemaZod(
  z.object({
    alasan: z.string().trim().min(5, 'Tulis alasan, minimal 5 karakter.').max(500),
    dasar: z.string().trim().min(3, 'Isi dasar (mis. nomor tiket layanan), minimal 3 karakter.').max(200),
  }),
)
const { defineField, handleSubmit, errors, resetForm, setErrors } = useForm({ validationSchema: skema })
const [alasan] = defineField('alasan')
const [dasar] = defineField('dasar')

const sibuk = ref(false)
const error = ref<string | null>(null)
const sandi = ref<string | null>(null)
const hasilAkun = ref<AkunRingkas | null>(null)

watch(buka, (b) => {
  if (b) {
    error.value = null
    resetForm({ values: { alasan: '', dasar: '' } })
    return
  }
  // Ditutup dengan cara apa pun: sandi dibuang, daftar akun dimuat ulang bila reset sudah terjadi.
  sandi.value = null
  if (hasilAkun.value) emit('selesai', hasilAkun.value)
  hasilAkun.value = null
})

const judul = computed(() => (sandi.value ? `Sandi sementara ${props.namaAkun}` : `Reset sandi ${props.namaAkun}?`))

const kirim = handleSubmit(async (v) => {
  sibuk.value = true
  error.value = null
  try {
    const h = await resetSandiAkun(props.akunId, v.alasan, v.dasar)
    hasilAkun.value = h.akun
    sandi.value = h.sandi_sementara
  } catch (e) {
    const kolom = errorKolom(e, ['alasan', 'dasar'])
    if (Object.keys(kolom).length) setErrors(kolom)
    else error.value = pesanError(e)
  } finally {
    sibuk.value = false
  }
})
</script>

<template>
  <Dialog v-model:open="buka" :judul="judul" :sibuk="sibuk" peringatan :tutup-lewat-tombol="!!sandi">
    <form v-if="!sandi" id="form-reset-sandi" novalidate @submit.prevent="kirim">
      <p class="catatan catatan-peringatan akibat">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>
          Sistem membuat sandi sementara untuk {{ namaPengguna }}. Semua sesi akun ini diputus, dan pemiliknya wajib mengganti
          sandi saat masuk berikutnya. MFA tidak berubah. Pastikan identitas peminta sudah diverifikasi tatap muka.
        </span>
      </p>
      <KolomIsian v-slot="a" label="Alasan" :error="errors.alasan" wajib>
        <textarea v-bind="a" v-model="alasan" class="isian" rows="2" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Dasar" :error="errors.dasar" petunjuk="Mis. nomor tiket layanan atau formulir permintaan reset." wajib>
        <input v-bind="a" v-model="dasar" class="isian" autocomplete="off" />
      </KolomIsian>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
    </form>
    <div v-else class="hasil">
      <p class="catatan catatan-peringatan" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>Sandi ini tidak akan ditampilkan lagi. Serahkan langsung ke pemilik akun; jangan dikirim lewat pesan atau email.</span>
      </p>
      <p class="label-sandi">Sandi sementara untuk <code>{{ namaPengguna }}</code></p>
      <SandiSekali :sandi="sandi" label="Sandi sementara" />
    </div>
    <template #aksi>
      <template v-if="!sandi">
        <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
        <Tombol type="submit" form="form-reset-sandi" varian="bahaya" :sibuk="sibuk">{{ sibuk ? 'Memproses…' : 'Reset sandi' }}</Tombol>
      </template>
      <Tombol v-else varian="utama" @click="buka = false">Selesai</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.akibat {
  margin: 0 0 var(--space-4);
}
.hasil {
  display: grid;
  gap: var(--space-3);
}
.hasil .catatan {
  margin: 0;
}
.label-sandi {
  margin: 0;
  font-weight: var(--font-weight-semibold);
}
.label-sandi code {
  font-family: var(--font-mono);
}
</style>
