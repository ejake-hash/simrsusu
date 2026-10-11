<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, useTemplateRef } from 'vue'
import { profilRsDariEnv } from '@/app/profilRs'
import { useSesiStore } from '@/app/sesi'
import { GalatApi } from '@/shared/api/klien'
import { formatSisaWaktu } from '@/shared/utils/waktu'
import { kirimSandi } from '../api'
import type { Langkah, LangkahTampil } from '../alurMasuk'
import { validasiIsianSandi, type GalatIsian, type IsianSandi } from '../validasi'

// Antarmuka sama untuk semua komponen langkah (MasukPage): langkah aktif, lanjut(), emit kembali.
const props = defineProps<{
  langkah: LangkahTampil
  lanjut: (langkah: Langkah) => Promise<void>
}>()
defineEmits<{ kembali: [] }>()

const sesi = useSesiStore()
const { kontakAdmin, unitAdmin } = profilRsDariEnv()
const isian = reactive<IsianSandi>({ nama_pengguna: '', sandi: '' })
const galatIsian = ref<GalatIsian>({})
/** tunggu = galat kunci/batas laju: punya baris "Coba lagi …" sendiri dan menahan tombol Masuk. */
const galat = ref<{ pesan: string; tunggu: boolean; cobaLagiSampai: number | null } | null>(null)
const proses = ref(false)
const tampilkanSandi = ref(false)
const capsLock = ref(false)
const inputNama = useTemplateRef<HTMLInputElement>('inputNama')
const inputSandi = useTemplateRef<HTMLInputElement>('inputSandi')

// Kalimat milik frontend: pesan server untuk kode ini sudah memuat "Coba lagi …", sedangkan sisa
// waktunya ditulis sekali di baris sendiri.
const PESAN_TUNGGU: Record<string, string> = {
  akun_terkunci: 'Akun terkunci sementara karena terlalu banyak percobaan gagal.',
  terlalu_banyak_permintaan: 'Terlalu banyak percobaan masuk.',
}

// Galat yang tidak bisa diatasi dengan mengetik ulang: kalimat frontend menyebut langkah berikutnya.
const PESAN_TINDAK_LANJUT: Record<string, string> = {
  mfa_belum_terdaftar:
    'Verifikasi dua langkah akun ini belum aktif, dan hanya bisa diaktifkan dari jaringan rumah sakit. ' +
    `Masuk sekali dari komputer di rumah sakit, atau minta ${unitAdmin} mereset verifikasi Anda.`,
  konfigurasi_belum_lengkap: `SIMRS belum selesai disiapkan sehingga belum bisa dipakai untuk masuk. Hubungi ${unitAdmin}.`,
}

// Hitung mundur dari Retry-After server; tombol Masuk nonaktif sampai habis.
const sekarang = ref(Date.now())
let detak: ReturnType<typeof setInterval> | undefined
const sisaWaktu = computed(() => {
  const sampai = galat.value?.cobaLagiSampai
  return sampai == null ? null : formatSisaWaktu(Math.ceil((sampai - sekarang.value) / 1000))
})
const menunggu = computed(() => sisaWaktu.value !== null)
const barisTunggu = computed(() => {
  if (!galat.value?.tunggu) return null
  if (galat.value.cobaLagiSampai === null) return 'Coba lagi nanti.'
  return sisaWaktu.value ? `Coba lagi dalam ${sisaWaktu.value}.` : 'Anda sudah bisa mencoba lagi.'
})

function mulaiHitungMundur(sampai: number): void {
  clearInterval(detak)
  sekarang.value = Date.now()
  detak = setInterval(() => {
    sekarang.value = Date.now()
    if (sekarang.value >= sampai) clearInterval(detak)
  }, 1000)
}

onMounted(() => inputNama.value?.focus())
onBeforeUnmount(() => clearInterval(detak))

async function kirim(): Promise<void> {
  if (proses.value || menunggu.value) return

  galatIsian.value = validasiIsianSandi(isian)
  if (galatIsian.value.nama_pengguna) return inputNama.value?.focus()
  if (galatIsian.value.sandi) return inputSandi.value?.focus()

  proses.value = true
  galat.value = null
  try {
    await props.lanjut(await kirimSandi({ ...isian }))
  } catch (e) {
    tampilkanGalat(e)
  } finally {
    proses.value = false
  }
}

function tampilkanGalat(e: unknown): void {
  if (!(e instanceof GalatApi)) {
    galat.value = {
      pesan: `Respons server tidak dikenali. Coba lagi atau hubungi ${unitAdmin}.`,
      tunggu: false,
      cobaLagiSampai: null,
    }
    return
  }

  if (e.kode === 'validasi_gagal') {
    for (const r of e.rincian) {
      if (r.kolom === 'nama_pengguna' || r.kolom === 'sandi') galatIsian.value[r.kolom] = r.pesan
    }
  }

  const tunggu = e.kode in PESAN_TUNGGU
  const cobaLagiSampai = tunggu && e.cobaLagiDetik !== null ? Date.now() + e.cobaLagiDetik * 1000 : null
  galat.value = { pesan: PESAN_TUNGGU[e.kode] ?? PESAN_TINDAK_LANJUT[e.kode] ?? e.message, tunggu, cobaLagiSampai }
  if (cobaLagiSampai !== null) mulaiHitungMundur(cobaLagiSampai)

  if (e.kode === 'kredensial_salah') {
    isian.sandi = ''
    inputSandi.value?.focus()
  }
}

function periksaCapsLock(e: KeyboardEvent): void {
  capsLock.value = e.getModifierState?.('CapsLock') ?? false
}

function ubahTampilanSandi(): void {
  tampilkanSandi.value = !tampilkanSandi.value
  inputSandi.value?.focus()
}
</script>

<template>
  <section aria-labelledby="judul-masuk">
    <p class="label-atas">SIMRS · Rumah Sakit Pendidikan</p>
    <h1 id="judul-masuk">Masuk ke akun Anda</h1>
    <p class="lead">Gunakan nama pengguna yang diberikan {{ unitAdmin }}.</p>

    <p v-if="sesi.pesanBerakhir && !galat" class="pesan pesan-info" role="status">
      <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
        <circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5" />
        <path d="M8 7.5v4M8 5h.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
      </svg>
      <span>{{ sesi.pesanBerakhir }}</span>
    </p>

    <!-- Wadah alert selalu ada agar isi baru dibacakan; hitung mundur di dalamnya tidak dibacakan ulang. -->
    <div role="alert" class="wadah-galat">
      <p v-if="galat" class="pesan pesan-galat" data-testid="galat-masuk">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
          <circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5" />
          <path d="M8 4.5v4M8 11h.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
        </svg>
        <span>
          {{ galat.pesan }}
          <span v-if="barisTunggu" id="baris-tunggu" class="baris-tunggu" aria-live="off">{{ barisTunggu }}</span>
        </span>
      </p>
    </div>

    <form novalidate @submit.prevent="kirim">
      <div class="field">
        <label for="nama-pengguna">Nama pengguna</label>
        <input
          id="nama-pengguna"
          ref="inputNama"
          v-model="isian.nama_pengguna"
          name="nama_pengguna"
          type="text"
          autocomplete="username"
          autocapitalize="none"
          autocorrect="off"
          spellcheck="false"
          :aria-invalid="galatIsian.nama_pengguna ? 'true' : undefined"
          :aria-describedby="galatIsian.nama_pengguna ? 'nama-pengguna-galat' : undefined"
        />
        <p v-if="galatIsian.nama_pengguna" id="nama-pengguna-galat" class="galat-field">{{ galatIsian.nama_pengguna }}</p>
      </div>

      <div class="field">
        <label for="sandi">Kata sandi</label>
        <div class="dengan-tombol">
          <input
            id="sandi"
            ref="inputSandi"
            v-model="isian.sandi"
            name="sandi"
            :type="tampilkanSandi ? 'text' : 'password'"
            autocomplete="current-password"
            autocapitalize="none"
            autocorrect="off"
            spellcheck="false"
            :aria-invalid="galatIsian.sandi ? 'true' : undefined"
            :aria-describedby="[galatIsian.sandi && 'sandi-galat', capsLock && 'sandi-caps'].filter(Boolean).join(' ') || undefined"
            @keydown="periksaCapsLock"
            @keyup="periksaCapsLock"
            @blur="capsLock = false"
          />
          <button
            type="button"
            class="tombol-lihat"
            aria-controls="sandi"
            aria-label="Tampilkan kata sandi"
            :aria-pressed="tampilkanSandi"
            @click="ubahTampilanSandi"
          >
            <svg v-if="!tampilkanSandi" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z" />
              <circle cx="12" cy="12" r="3" />
            </svg>
            <svg v-else width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M3 3l18 18" />
              <path d="M10.6 10.6a3 3 0 0 0 4.2 4.2" />
              <path d="M9.4 5.2A10 10 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4.1M6.1 6.6C3.6 8.3 2 12 2 12s3.5 7 10 7c1.6 0 3-.4 4.3-1" />
            </svg>
          </button>
        </div>
        <p v-if="galatIsian.sandi" id="sandi-galat" class="galat-field">{{ galatIsian.sandi }}</p>
        <div aria-live="polite">
          <p v-if="capsLock" id="sandi-caps" class="caps">
            <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true">
              <path d="M8 2 2 8h3v4h6V8h3L8 2z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round" />
            </svg>
            Caps Lock aktif
          </p>
        </div>
      </div>

      <button
        type="submit"
        class="tombol-utama"
        :class="{ menunggu }"
        :disabled="proses || menunggu"
        :aria-describedby="menunggu ? 'baris-tunggu' : undefined"
      >
        <span v-if="proses" class="spinner" aria-hidden="true"></span>
        <span>{{ proses ? 'Memproses…' : 'Masuk' }}</span>
      </button>
    </form>

    <p class="bantuan">
      Lupa sandi atau akun terkunci? Hubungi {{ unitAdmin }}<template v-if="kontakAdmin">
        di <span class="kontak">{{ kontakAdmin }}</span></template>.
    </p>
  </section>
</template>

<style scoped>
.label-atas {
  margin: 0 0 10px;
  color: var(--brand-600);
  font-size: 12px;
  font-weight: var(--font-weight-semibold);
  letter-spacing: 0.08em;
  text-transform: uppercase;
}
h1 {
  margin: 0 0 var(--space-2);
  font-size: 26px;
  font-weight: var(--font-weight-bold);
  line-height: 1.2;
  letter-spacing: -0.02em;
  text-wrap: balance;
}
.lead {
  margin: 0 0 28px;
  color: var(--muted);
}

.pesan {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  margin: 0 0 18px;
  padding: 10px 12px;
  border-radius: var(--radius-input);
  font-size: 13px;
}
.pesan svg {
  flex: none;
  margin-top: 2px;
}
.pesan-galat {
  background: var(--danger-bg);
  color: var(--danger);
}
.pesan-info {
  background: var(--info-bg);
  color: var(--info);
}

.field {
  margin-bottom: var(--space-4);
}
label {
  display: block;
  margin-bottom: 6px;
  font-size: 13px;
  font-weight: var(--font-weight-medium);
}
input {
  box-sizing: border-box;
  width: 100%;
  height: 44px;
  padding: 0 14px;
  border: 1px solid var(--border-input);
  border-radius: var(--radius-input);
  background: var(--surface);
  color: var(--text);
  font: inherit;
  /* 16px di layar sentuh mencegah zoom otomatis iOS saat fokus. */
  font-size: max(16px, var(--text-base));
}
@media (pointer: fine) {
  input {
    font-size: var(--text-base);
  }
}
input:hover {
  border-color: var(--subtle);
}
input:focus-visible {
  outline: none;
  border-color: var(--brand-600);
  box-shadow: var(--ring);
}
input[aria-invalid='true'] {
  border-color: var(--danger);
}
.dengan-tombol {
  position: relative;
}
.dengan-tombol input {
  padding-right: 48px;
}
.tombol-lihat {
  position: absolute;
  top: 2px;
  right: 2px;
  display: grid;
  place-items: center;
  width: 40px;
  height: 40px;
  border: 0;
  border-radius: 6px;
  background: transparent;
  color: var(--muted);
  cursor: pointer;
}
.tombol-lihat:hover {
  color: var(--text);
}
.tombol-lihat:focus-visible {
  outline: none;
  box-shadow: var(--ring);
}
.galat-field {
  margin: 6px 0 0;
  color: var(--danger);
  font-size: 12px;
}
.caps {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin: var(--space-2) 0 0;
  padding: 4px var(--space-2);
  border-radius: 6px;
  background: var(--warn-bg);
  color: var(--warn);
  font-size: 12px;
}

.bantuan {
  margin: var(--space-4) 0 0;
  color: var(--muted);
  font-size: 13px;
  text-align: center;
}

.baris-tunggu {
  display: block;
}
.kontak {
  white-space: nowrap;
}

.tombol-utama {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-2);
  width: 100%;
  height: 44px;
  margin-top: var(--space-6);
  border: 0;
  border-radius: var(--radius-input);
  background: var(--brand-600);
  color: var(--surface);
  font: inherit;
  font-weight: var(--font-weight-semibold);
  cursor: pointer;
  touch-action: manipulation;
}
.tombol-utama:hover {
  background: var(--brand-700);
}
.tombol-utama:focus-visible {
  outline: none;
  box-shadow: 0 0 0 2px var(--surface), 0 0 0 4px var(--brand-600);
}
.tombol-utama:disabled {
  background: var(--brand-700);
  cursor: progress;
}
/* Tertahan hitung mundur: tampak nonaktif, bukan sedang memproses. */
.tombol-utama.menunggu:disabled {
  border: 1px solid var(--border-input);
  background: var(--bg);
  color: var(--muted);
  cursor: not-allowed;
}
.spinner {
  width: 16px;
  height: 16px;
  border: 2px solid color-mix(in srgb, currentColor 45%, transparent);
  border-top-color: currentColor;
  border-radius: 50%;
  animation: putar 0.7s linear infinite;
}
@keyframes putar {
  to {
    transform: rotate(360deg);
  }
}
@media (prefers-reduced-motion: reduce) {
  .spinner {
    animation-duration: 2s;
  }
}
</style>
