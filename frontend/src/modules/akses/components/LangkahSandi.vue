<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, useTemplateRef } from 'vue'
import { useProfilRs } from '@/app/profilRs'
import { useSesiStore } from '@/app/sesi'
import { ApiError } from '@/shared/api/klien'
import { formatSisaWaktu } from '@/shared/utils/waktu'
import Pesan from '@/design-system/components/Pesan.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import IsianKataSandi from '@/design-system/form/IsianSandi.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { kirimSandi } from '../api'
import type { Langkah, LangkahTampil } from '../alurMasuk'
import { validasiIsianSandi, type FieldError, type IsianSandi } from '../validasi'

// Antarmuka sama untuk semua komponen langkah (MasukPage): langkah aktif, lanjut(), emit kembali.
const props = defineProps<{
  langkah: LangkahTampil
  lanjut: (langkah: Langkah) => Promise<void>
}>()
defineEmits<{ kembali: [] }>()

const sesi = useSesiStore()
const profil = useProfilRs()
const isian = reactive<IsianSandi>({ nama_pengguna: '', sandi: '' })
const fieldError = ref<FieldError>({})
/** tunggu = error kunci/rate limit: punya baris "Coba lagi …" sendiri dan menahan tombol Masuk. */
const error = ref<{ pesan: string; tunggu: boolean; cobaLagiSampai: number | null } | null>(null)
const proses = ref(false)
const inputNama = useTemplateRef<HTMLInputElement>('inputNama')
const inputSandi = useTemplateRef<InstanceType<typeof IsianKataSandi>>('inputSandi')

// Kalimat milik frontend: pesan server untuk kode ini sudah memuat "Coba lagi …", sedangkan sisa
// waktunya ditulis sekali di baris sendiri.
const PESAN_TUNGGU: Record<string, string> = {
  akun_terkunci: 'Akun terkunci sementara karena terlalu banyak percobaan gagal.',
  terlalu_banyak_permintaan: 'Terlalu banyak percobaan masuk.',
}

// Error yang tidak bisa diatasi dengan mengetik ulang: kalimat frontend menyebut langkah berikutnya.
const pesanTindakLanjut = (kode: string, unitAdmin: string): string | undefined =>
  ({
    mfa_belum_terdaftar:
      'Verifikasi dua langkah akun ini belum aktif, dan hanya bisa diaktifkan dari jaringan rumah sakit. ' +
      `Masuk sekali dari komputer di rumah sakit, atau minta ${unitAdmin} mereset verifikasi Anda.`,
    konfigurasi_belum_lengkap: `SIMRS belum selesai disiapkan sehingga belum bisa dipakai untuk masuk. Hubungi ${unitAdmin}.`,
  })[kode]

// Hitung mundur dari Retry-After server; tombol Masuk nonaktif sampai habis.
const sekarang = ref(Date.now())
let detak: ReturnType<typeof setInterval> | undefined
const sisaWaktu = computed(() => {
  const sampai = error.value?.cobaLagiSampai
  return sampai == null ? null : formatSisaWaktu(Math.ceil((sampai - sekarang.value) / 1000))
})
const menunggu = computed(() => sisaWaktu.value !== null)
const barisTunggu = computed(() => {
  if (!error.value?.tunggu) return null
  if (error.value.cobaLagiSampai === null) return 'Coba lagi nanti.'
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

  fieldError.value = validasiIsianSandi(isian)
  if (fieldError.value.nama_pengguna) return inputNama.value?.focus()
  if (fieldError.value.sandi) return inputSandi.value?.fokus()

  proses.value = true
  error.value = null
  try {
    await props.lanjut(await kirimSandi({ ...isian }))
  } catch (e) {
    tampilkanError(e)
  } finally {
    proses.value = false
  }
}

function tampilkanError(e: unknown): void {
  if (!(e instanceof ApiError)) {
    error.value = {
      pesan: `Respons server tidak dikenali. Coba lagi atau hubungi ${profil.value.unitAdmin}.`,
      tunggu: false,
      cobaLagiSampai: null,
    }
    return
  }

  if (e.kode === 'validasi_gagal') {
    for (const r of e.rincian) {
      if (r.kolom === 'nama_pengguna' || r.kolom === 'sandi') fieldError.value[r.kolom] = r.pesan
    }
  }

  const tunggu = e.kode in PESAN_TUNGGU
  const cobaLagiSampai = tunggu && e.cobaLagiDetik !== null ? Date.now() + e.cobaLagiDetik * 1000 : null
  error.value = { pesan: PESAN_TUNGGU[e.kode] ?? pesanTindakLanjut(e.kode, profil.value.unitAdmin) ?? e.message, tunggu, cobaLagiSampai }
  if (cobaLagiSampai !== null) mulaiHitungMundur(cobaLagiSampai)

  if (e.kode === 'kredensial_salah') {
    isian.sandi = ''
    inputSandi.value?.fokus()
  }
}
</script>

<template>
  <section aria-labelledby="judul-masuk">
    <p class="label-atas">SIMRS · Rumah Sakit Pendidikan</p>
    <h1 id="judul-masuk">Masuk ke akun Anda</h1>
    <p class="lead">Gunakan nama pengguna yang diberikan {{ profil.unitAdmin }}.</p>

    <Pesan v-if="sesi.pesanBerakhir && !error" jenis="info" class="pesan-atas" role="status">{{ sesi.pesanBerakhir }}</Pesan>

    <!-- Wadah alert selalu ada agar isi baru dibacakan; hitung mundur di dalamnya tidak dibacakan ulang. -->
    <div role="alert" class="wadah-error">
      <Pesan v-if="error" jenis="error" class="pesan-atas" data-testid="error-masuk">
        {{ error.pesan }}
        <span v-if="barisTunggu" id="baris-tunggu" class="baris-tunggu" aria-live="off">{{ barisTunggu }}</span>
      </Pesan>
    </div>

    <form novalidate @submit.prevent="kirim">
      <KolomIsian id="nama-pengguna" v-slot="a" label="Nama pengguna" :error="fieldError.nama_pengguna">
        <input
          v-bind="a"
          ref="inputNama"
          v-model="isian.nama_pengguna"
          class="isian isian-besar"
          name="nama_pengguna"
          type="text"
          autocomplete="username"
          autocapitalize="none"
          autocorrect="off"
          spellcheck="false"
        />
      </KolomIsian>

      <KolomIsian id="sandi" v-slot="a" label="Kata sandi" :error="fieldError.sandi">
        <IsianKataSandi v-bind="a" ref="inputSandi" v-model="isian.sandi" name="sandi" />
      </KolomIsian>

      <Tombol
        type="submit"
        varian="utama"
        ukuran="besar"
        penuh
        class="tombol-masuk"
        :sibuk="proses"
        :disabled="proses || menunggu"
        :aria-describedby="menunggu ? 'baris-tunggu' : undefined"
      >
        {{ proses ? 'Memproses…' : 'Masuk' }}
      </Tombol>
    </form>

    <p class="bantuan">
      Lupa sandi atau akun terkunci? Hubungi {{ profil.unitAdmin }}<template v-if="profil.kontakAdmin">
        di <span class="kontak">{{ profil.kontakAdmin }}</span></template>.
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
.pesan-atas {
  margin: 0 0 18px;
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
.tombol-masuk {
  margin-top: var(--space-6);
}
</style>
