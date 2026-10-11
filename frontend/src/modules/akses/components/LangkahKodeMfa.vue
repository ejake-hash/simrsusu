<script setup lang="ts">
import { computed, nextTick, onMounted, ref, useTemplateRef } from 'vue'
import { profilRsDariEnv } from '@/app/profilRs'
import { useSesiStore } from '@/app/sesi'
import Pesan from '@/design-system/components/Pesan.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { kirimKodeMfa } from '../api'
import type { Langkah, LangkahTampil, MetodeMfa } from '../alurMasuk'
import { tindakanMfaError } from '../mfaError'

// Langkah perlu_mfa (PRD 10.3.2): kode TOTP 6 digit, atau kode cadangan bila server menawarkannya.
const props = defineProps<{
  langkah: LangkahTampil
  lanjut: (langkah: Langkah) => Promise<void>
}>()
const emit = defineEmits<{ kembali: [] }>()

const sesi = useSesiStore()
const { unitAdmin } = profilRsDariEnv()
const metodeTersedia = computed<MetodeMfa[]>(() => (props.langkah.status === 'perlu_mfa' ? props.langkah.metode : ['totp']))
const metode = ref<MetodeMfa>('totp')
const kode = ref('')
const errorKode = ref<string | null>(null)
const error = ref<string | null>(null)
const proses = ref(false)
const inputKode = useTemplateRef<HTMLInputElement>('inputKode')

const cadangan = computed(() => metode.value === 'kode_cadangan')

onMounted(() => inputKode.value?.focus())

async function gantiMetode(): Promise<void> {
  metode.value = cadangan.value ? 'totp' : 'kode_cadangan'
  kode.value = ''
  errorKode.value = null
  error.value = null
  await nextTick()
  inputKode.value?.focus()
}

function validasi(): string | null {
  const isi = kode.value.trim()
  if (cadangan.value) return isi === '' ? 'Kode cadangan wajib diisi.' : null
  return /^\d{6}$/.test(isi.replace(/\s/g, '')) ? null : 'Masukkan 6 digit dari aplikasi autentikator.'
}

async function kirim(): Promise<void> {
  if (proses.value || props.langkah.status !== 'perlu_mfa') return

  errorKode.value = validasi()
  if (errorKode.value) return inputKode.value?.focus()

  proses.value = true
  error.value = null
  try {
    const isi = cadangan.value ? kode.value.trim() : kode.value.replace(/\s/g, '')
    await props.lanjut(await kirimKodeMfa(props.langkah.challenge_id, metode.value, isi))
  } catch (e) {
    const tindakan = tindakanMfaError(e, unitAdmin)
    if (tindakan.jenis === 'kembali') {
      sesi.pesanBerakhir = tindakan.pesan
      emit('kembali')
      return
    }
    error.value = tindakan.pesan
    if (tindakan.kosongkanKode) kode.value = ''
    inputKode.value?.focus()
  } finally {
    proses.value = false
  }
}
</script>

<template>
  <section aria-labelledby="judul-mfa">
    <button type="button" class="kembali" @click="emit('kembali')">
      <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true">
        <path d="M10 3 5 8l5 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
      Kembali
    </button>
    <p class="label-atas">Verifikasi dua langkah</p>
    <h1 id="judul-mfa">{{ cadangan ? 'Masukkan kode cadangan' : 'Masukkan kode 6 digit' }}</h1>
    <p class="lead">
      <template v-if="cadangan">Pakai salah satu kode cadangan yang Anda simpan saat mengaktifkan verifikasi dua langkah. Setiap kode hanya berlaku sekali.</template>
      <template v-else>Buka aplikasi autentikator di ponsel Anda, lalu masukkan kode yang tampil untuk akun ini.</template>
    </p>

    <div role="alert" class="wadah-error">
      <Pesan v-if="error" jenis="error" class="pesan-atas" data-testid="error-mfa">{{ error }}</Pesan>
    </div>

    <form novalidate @submit.prevent="kirim">
      <KolomIsian id="kode-mfa" v-slot="a" :label="cadangan ? 'Kode cadangan' : 'Kode verifikasi'" :error="errorKode ?? undefined">
        <input
          v-bind="a"
          ref="inputKode"
          v-model="kode"
          class="isian isian-besar isian-kode"
          name="kode"
          type="text"
          :inputmode="cadangan ? 'text' : 'numeric'"
          :autocomplete="cadangan ? 'off' : 'one-time-code'"
          :maxlength="cadangan ? 16 : 7"
          autocapitalize="none"
          autocorrect="off"
          spellcheck="false"
        />
      </KolomIsian>

      <p v-if="metodeTersedia.includes('kode_cadangan')" class="petunjuk">
        <template v-if="cadangan">Ponsel sudah tersedia?</template>
        <template v-else>Tidak punya akses ke aplikasi autentikator?</template>
        <Tombol varian="tautan" @click="gantiMetode">
          {{ cadangan ? 'Pakai kode dari aplikasi autentikator' : 'Gunakan kode cadangan' }}
        </Tombol>
      </p>

      <Tombol type="submit" varian="utama" ukuran="besar" penuh class="tombol-kirim" :sibuk="proses" :disabled="proses">
        {{ proses ? 'Memeriksa…' : 'Verifikasi' }}
      </Tombol>
    </form>
  </section>
</template>

<style scoped src="./langkah-mfa.css"></style>
