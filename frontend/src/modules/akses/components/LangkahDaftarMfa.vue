<script setup lang="ts">
import { computed, nextTick, onMounted, ref, useTemplateRef } from 'vue'
import { profilRsDariEnv } from '@/app/profilRs'
import { useSesiStore } from '@/app/sesi'
import Pesan from '@/design-system/components/Pesan.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { konfirmasiDaftarMfa, mintaRahasiaMfa, type RahasiaMfa } from '../api'
import type { Langkah, LangkahTampil } from '../alurMasuk'
import { tindakanMfaError } from '../mfaError'

/**
 * Langkah perlu_daftar_mfa (PRD 10.3.2): pindai QR → konfirmasi kode → simpan kode cadangan.
 * Rahasia dan kode cadangan hanya ada di memori komponen ini; tidak pernah ke storage.
 * Setelah konfirmasi, sesi di server sudah masuk; tombol terakhir hanya memastikan kode disimpan.
 */
const props = defineProps<{
  langkah: LangkahTampil
  lanjut: (langkah: Langkah) => Promise<void>
}>()
const emit = defineEmits<{ kembali: [] }>()

const sesi = useSesiStore()
const { unitAdmin } = profilRsDariEnv()
const tahap = ref<'memuat' | 'pindai' | 'kode_cadangan'>('memuat')
const rahasia = ref<RahasiaMfa | null>(null)
const kode = ref('')
const errorKode = ref<string | null>(null)
const error = ref<string | null>(null)
const proses = ref(false)
const kodeCadangan = ref<string[]>([])
const sudahDisimpan = ref(false)
const tersalin = ref(false)
const masuk = ref<Langkah | null>(null)
const inputKode = useTemplateRef<HTMLInputElement>('inputKode')
const judulCadangan = useTemplateRef<HTMLHeadingElement>('judulCadangan')

const challengeId = computed(() => (props.langkah.status === 'perlu_daftar_mfa' ? props.langkah.challenge_id : ''))
// <img> data URI, bukan v-html: skrip di dalam SVG tidak dijalankan di <img>.
const gambarQr = computed(() => (rahasia.value ? `data:image/svg+xml;charset=utf-8,${encodeURIComponent(rahasia.value.qr_svg)}` : ''))
/** Kunci untuk diketik manual bila QR tidak bisa dipindai, dikelompokkan per 4 karakter. */
const kunciManual = computed(() => {
  const kunci = rahasia.value ? new URL(rahasia.value.uri_otpauth).searchParams.get('secret') ?? '' : ''
  return kunci.match(/.{1,4}/g)?.join(' ') ?? ''
})

onMounted(muatRahasia)

async function muatRahasia(): Promise<void> {
  tahap.value = 'memuat'
  error.value = null
  try {
    rahasia.value = await mintaRahasiaMfa(challengeId.value)
    tahap.value = 'pindai'
    await nextTick()
    inputKode.value?.focus()
  } catch (e) {
    tanganiError(e)
  }
}

async function konfirmasi(): Promise<void> {
  if (proses.value) return

  const isi = kode.value.replace(/\s/g, '')
  errorKode.value = /^\d{6}$/.test(isi) ? null : 'Masukkan 6 digit dari aplikasi autentikator.'
  if (errorKode.value) return inputKode.value?.focus()

  proses.value = true
  error.value = null
  try {
    const hasil = await konfirmasiDaftarMfa(challengeId.value, isi)
    masuk.value = hasil.langkah
    kodeCadangan.value = hasil.kodeCadangan
    rahasia.value = null
    tahap.value = 'kode_cadangan'
    await nextTick()
    judulCadangan.value?.focus()
  } catch (e) {
    tanganiError(e)
  } finally {
    proses.value = false
  }
}

function tanganiError(e: unknown): void {
  const tindakan = tindakanMfaError(e, unitAdmin)
  if (tindakan.jenis === 'kembali') {
    sesi.pesanBerakhir = tindakan.pesan
    emit('kembali')
    return
  }
  error.value = tindakan.pesan
  if (tindakan.kosongkanKode) kode.value = ''
  inputKode.value?.focus()
}

async function salin(): Promise<void> {
  try {
    await navigator.clipboard.writeText(kodeCadangan.value.join('\n'))
    tersalin.value = true
  } catch {
    tersalin.value = false
  }
}

async function selesai(): Promise<void> {
  if (!sudahDisimpan.value || !masuk.value || proses.value) return
  proses.value = true
  try {
    await props.lanjut(masuk.value)
  } finally {
    proses.value = false
  }
}
</script>

<template>
  <section v-if="tahap !== 'kode_cadangan'" aria-labelledby="judul-daftar-mfa">
    <button type="button" class="kembali" @click="emit('kembali')">
      <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true">
        <path d="M10 3 5 8l5 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
      Kembali
    </button>
    <p class="label-atas">Verifikasi dua langkah</p>
    <h1 id="judul-daftar-mfa">Aktifkan verifikasi dua langkah</h1>
    <p class="lead">
      Akun ini wajib memakai aplikasi autentikator di ponsel. Pindai kode QR di bawah dengan aplikasi tersebut, lalu masukkan
      kode 6 digit yang muncul.
    </p>

    <div role="alert" class="wadah-error">
      <Pesan v-if="error" jenis="error" class="pesan-atas" data-testid="error-mfa">{{ error }}</Pesan>
    </div>

    <p v-if="tahap === 'memuat' && !error" class="memuat" role="status">Menyiapkan kode QR…</p>
    <Tombol v-if="tahap === 'memuat' && error" @click="muatRahasia">Coba lagi</Tombol>

    <template v-if="tahap === 'pindai' && rahasia">
      <div class="qr">
        <img :src="gambarQr" width="200" height="200" alt="Kode QR untuk aplikasi autentikator" />
      </div>
      <details class="manual">
        <summary>Tidak bisa memindai? Masukkan kunci secara manual</summary>
        <p class="petunjuk">Pilih "masukkan kunci" di aplikasi autentikator, jenis berbasis waktu.</p>
        <code class="kunci" data-testid="kunci-manual">{{ kunciManual }}</code>
      </details>

      <form novalidate @submit.prevent="konfirmasi">
        <KolomIsian id="kode-daftar-mfa" v-slot="a" label="Kode verifikasi" :error="errorKode ?? undefined">
          <input
            v-bind="a"
            ref="inputKode"
            v-model="kode"
            class="isian isian-besar isian-kode"
            name="kode"
            type="text"
            inputmode="numeric"
            autocomplete="one-time-code"
            maxlength="7"
            autocapitalize="none"
            autocorrect="off"
            spellcheck="false"
          />
        </KolomIsian>

        <Tombol type="submit" varian="utama" ukuran="besar" penuh class="tombol-kirim" :sibuk="proses" :disabled="proses">
          {{ proses ? 'Memeriksa…' : 'Aktifkan' }}
        </Tombol>
      </form>
    </template>
  </section>

  <section v-else aria-labelledby="judul-kode-cadangan">
    <p class="label-atas">Verifikasi dua langkah aktif</p>
    <h1 id="judul-kode-cadangan" ref="judulCadangan" tabindex="-1">Simpan kode cadangan</h1>
    <p class="lead">
      Pakai salah satu kode ini bila ponsel Anda tidak tersedia. Setiap kode hanya berlaku sekali, dan daftar ini
      <strong>tidak akan ditampilkan lagi</strong>. Simpan di tempat aman, terpisah dari ponsel.
    </p>

    <ol class="daftar-kode" data-testid="kode-cadangan">
      <li v-for="k in kodeCadangan" :key="k"><code>{{ k }}</code></li>
    </ol>

    <div class="aksi-salin">
      <Tombol @click="salin">Salin semua kode</Tombol>
      <span v-if="tersalin" role="status" class="petunjuk">Kode tersalin.</span>
    </div>

    <label class="centang">
      <input v-model="sudahDisimpan" type="checkbox" />
      <span>Saya sudah menyimpan kode cadangan ini</span>
    </label>

    <Tombol varian="utama" ukuran="besar" penuh class="tombol-kirim" :sibuk="proses" :disabled="!sudahDisimpan || proses" @click="selesai">
      Lanjut ke aplikasi
    </Tombol>
  </section>
</template>

<style scoped src="./langkah-mfa.css"></style>
<style scoped>
.memuat {
  margin: 0 0 var(--space-4);
  color: var(--muted);
}
.qr {
  display: grid;
  place-items: center;
  margin: 0 0 var(--space-4);
  padding: var(--space-4);
  border: 1px solid var(--border);
  border-radius: var(--radius-card);
  /* SVG QR dari server sudah berlatar putih sendiri, jadi tetap terbaca kamera. */
  background: var(--surface);
}
.qr img {
  display: block;
  max-width: 100%;
  height: auto;
}
.manual {
  margin: 0 0 var(--space-4);
  font-size: 13px;
}
.manual summary {
  color: var(--brand-600);
  cursor: pointer;
}
.kunci {
  display: block;
  margin-top: var(--space-2);
  padding: var(--space-2) var(--space-3);
  border-radius: var(--radius-input);
  background: var(--bg);
  font-family: var(--font-mono);
  letter-spacing: 0.05em;
  overflow-wrap: anywhere;
  user-select: all;
}
.daftar-kode {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--space-2) var(--space-4);
  margin: 0 0 var(--space-4);
  padding: var(--space-4) var(--space-4) var(--space-4) calc(var(--space-4) + 1.5em);
  border: 1px solid var(--border);
  border-radius: var(--radius-card);
  background: var(--surface);
}
.daftar-kode code {
  font-family: var(--font-mono);
  font-variant-numeric: tabular-nums;
}
.aksi-salin {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  margin: 0 0 var(--space-4);
}
.centang {
  display: flex;
  margin-bottom: 6px;
  align-items: flex-start;
  gap: var(--space-2);
  font-size: 13px;
  font-weight: var(--font-weight-regular);
  cursor: pointer;
}
.centang input {
  width: 18px;
  height: 18px;
  margin: 1px 0 0;
  accent-color: var(--brand-600);
}
</style>
