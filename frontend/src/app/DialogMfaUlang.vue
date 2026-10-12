<script setup lang="ts">
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { verifikasiUlangMfa } from '@/modules/akses/api'
import type { MetodeMfa } from '@/modules/akses/alurMasuk'
import { ApiError } from '@/shared/api/klien'
import { formatSisaWaktu } from '@/shared/utils/waktu'
import { useProfilRs } from './profilRs'
import { jawabMfaUlang, mfaUlangTerbuka } from './mfaUlang'

/**
 * Dialog MFA ulang (tiket 1.5 fe 1, K11): aksi `butuh_mfa` lebih dari 15 menit sesudah verifikasi terakhir ditolak
 * server dengan 403 mfa_ulang_diperlukan. Pengguna memasukkan kode tanpa keluar dari layar; sesudah diterima, klien API
 * mengulang permintaan yang tadi ditolak. Menutup dialog = membatalkan aksi itu.
 */
const profil = useProfilRs()
const metode = ref<MetodeMfa>('totp')
const kode = ref('')
const errorKode = ref<string | null>(null)
const error = ref<string | null>(null)
/** Akun terkunci atau belum mendaftar MFA: kode tidak bisa dikirim lagi dari dialog ini. */
const buntu = ref(false)
const sibuk = ref(false)
const inputKode = useTemplateRef<HTMLInputElement>('inputKode')
const tombolBatal = useTemplateRef<HTMLButtonElement>('tombolBatal')
const cadangan = computed(() => metode.value === 'kode_cadangan')

const terbuka = computed({
  get: () => mfaUlangTerbuka.value,
  set: (b: boolean) => {
    if (!b) jawabMfaUlang(false)
  },
})

watch(mfaUlangTerbuka, async (b) => {
  if (!b) return
  metode.value = 'totp'
  kode.value = ''
  errorKode.value = null
  error.value = null
  buntu.value = false
  await nextTick()
  inputKode.value?.focus()
})

async function gantiMetode() {
  metode.value = cadangan.value ? 'totp' : 'kode_cadangan'
  kode.value = ''
  errorKode.value = null
  await nextTick()
  inputKode.value?.focus()
}

function validasi(): string | null {
  const isi = kode.value.trim()
  if (cadangan.value) return isi === '' ? 'Kode cadangan wajib diisi.' : null
  return /^\d{6}$/.test(isi.replace(/\s/g, '')) ? null : 'Masukkan 6 digit dari aplikasi autentikator.'
}

function pesanGagal(e: unknown): { pesan: string; buntu: boolean; kosongkan: boolean } {
  if (!(e instanceof ApiError)) return { pesan: 'Terjadi kesalahan. Coba lagi.', buntu: false, kosongkan: false }
  const sisa = e.cobaLagiDetik === null ? null : formatSisaWaktu(e.cobaLagiDetik)
  switch (e.kode) {
    case 'kode_mfa_salah':
      return { pesan: 'Kode salah. Periksa kode terbaru lalu coba lagi.', buntu: false, kosongkan: true }
    case 'akun_terkunci':
      return { pesan: `Akun terkunci sementara karena terlalu banyak percobaan gagal.${sisa ? ` Coba lagi dalam ${sisa}.` : ''}`, buntu: true, kosongkan: true }
    case 'mfa_belum_terdaftar':
      return { pesan: `Aksi ini memerlukan verifikasi dua langkah, tetapi akun Anda belum mendaftarkannya. Hubungi ${profil.value.unitAdmin}.`, buntu: true, kosongkan: false }
    case 'terlalu_banyak_permintaan':
      return { pesan: `Terlalu banyak percobaan.${sisa ? ` Coba lagi dalam ${sisa}.` : ' Coba lagi nanti.'}`, buntu: false, kosongkan: false }
    case 'validasi_gagal':
      return { pesan: e.rincian[0]?.pesan ?? e.message, buntu: false, kosongkan: false }
    default:
      return { pesan: e.message, buntu: false, kosongkan: false }
  }
}

async function kirim() {
  if (sibuk.value || buntu.value) return
  errorKode.value = validasi()
  if (errorKode.value) return inputKode.value?.focus()
  sibuk.value = true
  error.value = null
  try {
    const h = await verifikasiUlangMfa(metode.value, cadangan.value ? kode.value.trim() : kode.value.replace(/\s/g, ''))
    jawabMfaUlang(true, Date.parse(h.berlaku_sampai) - Date.parse(h.mfa_terverifikasi_pada))
  } catch (e) {
    const g = pesanGagal(e)
    error.value = g.pesan
    buntu.value = g.buntu
    if (g.kosongkan) kode.value = ''
    await nextTick()
    // Buntu (akun terkunci / belum mendaftar MFA): kolom nonaktif, satu-satunya jalan adalah membatalkan.
    if (g.buntu) tombolBatal.value?.focus()
    else inputKode.value?.focus()
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog
    v-model:open="terbuka"
    judul="Verifikasi ulang diperlukan"
    uraian="Aksi ini perlu verifikasi dua langkah yang baru. Masukkan kode untuk melanjutkan; Anda tetap di layar ini."
    :sibuk="sibuk"
    peringatan
  >
    <form id="form-mfa-ulang" novalidate @submit.prevent="kirim">
      <KolomIsian
        v-slot="a"
        :label="cadangan ? 'Kode cadangan' : 'Kode dari aplikasi autentikator'"
        :error="errorKode ?? undefined"
        :petunjuk="cadangan ? 'Satu kode cadangan hanya bisa dipakai sekali.' : '6 digit, berganti tiap 30 detik.'"
        wajib
      >
        <input
          v-bind="a"
          ref="inputKode"
          v-model="kode"
          class="isian kode"
          :inputmode="cadangan ? 'text' : 'numeric'"
          autocomplete="one-time-code"
          :maxlength="cadangan ? 20 : 7"
          :disabled="buntu"
        />
      </KolomIsian>
      <button type="button" class="tombol-tautan ganti" :disabled="buntu || sibuk" @click="gantiMetode">
        {{ cadangan ? 'Pakai kode dari aplikasi autentikator' : 'Pakai kode cadangan' }}
      </button>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
    </form>
    <template #aksi>
      <button ref="tombolBatal" type="button" class="tombol" :disabled="sibuk" @click="terbuka = false">Batalkan aksi</button>
      <Tombol type="submit" form="form-mfa-ulang" varian="utama" :sibuk="sibuk" :disabled="buntu">{{ sibuk ? 'Memeriksa…' : 'Verifikasi' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.kode {
  font-family: var(--font-mono);
  letter-spacing: 0.08em;
}
.ganti {
  margin: 0 0 var(--space-3);
}
</style>
