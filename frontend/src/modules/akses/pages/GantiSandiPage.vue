<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, useTemplateRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useProfilRs } from '@/app/profilRs'
import { useSesiStore } from '@/app/sesi'
import Pesan from '@/design-system/components/Pesan.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import IsianKataSandi from '@/design-system/form/IsianSandi.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { ApiError } from '@/shared/api/klien'
import { formatSisaWaktu } from '@/shared/utils/waktu'
import { tujuanSetelahMasuk } from '../alurMasuk'
import { gantiSandi } from '../api'

/**
 * Ganti sandi (tiket 1.8d fe 2, kontrak POST /saya/sandi). Dipaksa saat `wajib_ganti_sandi` (akun baru dari pegawai,
 * masuk pertama dengan sandi awal) dan bisa dibuka dari menu akun. Di luar AppShell: selama sandi awal belum diganti,
 * endpoint lain ditolak server. Aturan sandi baru diperiksa server (panjang minimal dari konfigurasi, sandi bocor,
 * tidak memuat nama pengguna/nama RS/nama produk); pesannya ditampilkan apa adanya dari `rincian`. Klien hanya
 * memeriksa isian kosong dan pengulangan agar angka panjang minimal tidak dikodekan ganda.
 */
const sesi = useSesiStore()
const profil = useProfilRs()
const route = useRoute()
const router = useRouter()

const dipaksa = computed(() => sesi.pengguna?.wajib_ganti_sandi === true)
const isian = reactive({ lama: '', baru: '', ulang: '' })
const kesalahan = ref<{ lama?: string; baru?: string; ulang?: string }>({})
const error = ref<{ pesan: string; sampai: number | null } | null>(null)
const proses = ref(false)
const inputLama = useTemplateRef<InstanceType<typeof IsianKataSandi>>('inputLama')
const inputBaru = useTemplateRef<InstanceType<typeof IsianKataSandi>>('inputBaru')

// 429 sesudah sandi lama salah berulang: hitung mundur dari Retry-After.
const sekarang = ref(Date.now())
let detak: ReturnType<typeof setInterval> | undefined
const sisa = computed(() => (error.value?.sampai ? formatSisaWaktu(Math.ceil((error.value.sampai - sekarang.value) / 1000)) : null))
const menunggu = computed(() => sisa.value !== null)
onMounted(() => inputLama.value?.fokus())
onBeforeUnmount(() => clearInterval(detak))

async function kirim() {
  if (proses.value || menunggu.value) return
  const e: typeof kesalahan.value = {}
  if (!isian.lama) e.lama = dipaksa.value ? 'Isi sandi dari admin.' : 'Isi sandi lama.'
  if (!isian.baru) e.baru = 'Isi sandi baru.'
  else if (isian.ulang !== isian.baru) e.ulang = 'Pengulangan tidak sama dengan sandi baru.'
  kesalahan.value = e
  if (e.lama) return inputLama.value?.fokus()
  if (e.baru || e.ulang) return inputBaru.value?.fokus()

  proses.value = true
  error.value = null
  try {
    sesi.pengguna = await gantiSandi({ sandi_lama: isian.lama, sandi_baru: isian.baru })
    isian.lama = isian.baru = isian.ulang = ''
    await router.replace(tujuanSetelahMasuk(route.query.kembali))
  } catch (err) {
    tampilkanError(err)
  } finally {
    proses.value = false
  }
}

function tampilkanError(err: unknown) {
  if (!(err instanceof ApiError)) {
    error.value = { pesan: 'Respons server tidak dikenali. Coba lagi.', sampai: null }
    return
  }
  if (err.kode === 'sandi_lama_salah') {
    kesalahan.value = { lama: dipaksa.value ? 'Sandi awal salah.' : 'Sandi lama salah.' }
    isian.lama = ''
    inputLama.value?.fokus()
    return
  }
  if (err.kode === 'validasi_gagal' && err.rincian.length) {
    const pesan = (k: string) => err.rincian.filter((r) => r.kolom === k).map((r) => r.pesan).join(' ') || undefined
    kesalahan.value = { lama: pesan('sandi_lama'), baru: pesan('sandi_baru') }
    const lain = err.rincian.filter((r) => r.kolom !== 'sandi_lama' && r.kolom !== 'sandi_baru').map((r) => r.pesan)
    if (lain.length) error.value = { pesan: lain.join(' '), sampai: null }
    if (kesalahan.value.baru) inputBaru.value?.fokus()
    return
  }
  const sampai = err.status === 429 && err.cobaLagiDetik !== null ? Date.now() + err.cobaLagiDetik * 1000 : null
  error.value = { pesan: err.status === 429 ? 'Terlalu banyak percobaan sandi lama yang salah.' : err.message, sampai }
  if (sampai) {
    clearInterval(detak)
    detak = setInterval(() => {
      sekarang.value = Date.now()
      if (sekarang.value >= sampai) clearInterval(detak)
    }, 1000)
  }
}

async function keluar() {
  await sesi.keluar()
  await router.replace({ name: 'masuk' })
}
</script>

<template>
  <section aria-labelledby="judul-ganti-sandi">
    <p class="label-atas">{{ sesi.pengguna?.nama_pengguna }}</p>
    <h1 id="judul-ganti-sandi">{{ dipaksa ? 'Buat sandi Anda sendiri' : 'Ganti sandi' }}</h1>
    <p class="lead">
      {{
        dipaksa
          ? 'Anda masuk dengan sandi dari admin (sandi awal akun baru atau sandi sementara sesudah reset). Ganti dulu dengan sandi yang hanya Anda ketahui sebelum memakai SIMRS.'
          : 'Sesudah diganti, sesi akun ini di perangkat lain diakhiri; sesi ini tetap masuk.'
      }}
    </p>

    <div role="alert" class="wadah-error">
      <Pesan v-if="error" jenis="error" class="pesan-atas">
        {{ error.pesan }}
        <span v-if="sisa" class="baris-tunggu" aria-live="off">Coba lagi dalam {{ sisa }}.</span>
      </Pesan>
    </div>

    <form novalidate @submit.prevent="kirim">
      <KolomIsian id="sandi-lama" v-slot="a" :label="dipaksa ? 'Sandi dari admin' : 'Sandi lama'" :error="kesalahan.lama">
        <IsianKataSandi v-bind="a" ref="inputLama" v-model="isian.lama" name="sandi_lama" autocomplete="current-password" />
      </KolomIsian>
      <KolomIsian
        id="sandi-baru"
        v-slot="a"
        label="Sandi baru"
        :error="kesalahan.baru"
        petunjuk="Panjang dan tidak mudah ditebak; tidak memuat nama pengguna, nama rumah sakit, atau nama aplikasi."
      >
        <IsianKataSandi v-bind="a" ref="inputBaru" v-model="isian.baru" name="sandi_baru" autocomplete="new-password" />
      </KolomIsian>
      <KolomIsian id="sandi-ulang" v-slot="a" label="Ulangi sandi baru" :error="kesalahan.ulang">
        <IsianKataSandi v-bind="a" v-model="isian.ulang" name="sandi_ulang" autocomplete="new-password" />
      </KolomIsian>

      <Tombol type="submit" varian="utama" ukuran="besar" penuh class="tombol-kirim" :sibuk="proses" :disabled="proses || menunggu">
        {{ proses ? 'Menyimpan…' : 'Simpan sandi baru' }}
      </Tombol>
    </form>

    <p class="bantuan">
      <button v-if="dipaksa" type="button" class="tombol tombol-tautan" @click="keluar">Keluar</button>
      <RouterLink v-else :to="tujuanSetelahMasuk(route.query.kembali)">Batal</RouterLink>
      <span v-if="dipaksa"> · Sandi awal hilang? Hubungi {{ profil.unitAdmin }}.</span>
    </p>
  </section>
</template>

<style scoped>
.label-atas {
  margin: 0 0 10px;
  color: var(--brand-600);
  font-size: 12px;
  font-weight: var(--font-weight-semibold);
  letter-spacing: 0.04em;
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
.baris-tunggu {
  display: block;
}
.tombol-kirim {
  margin-top: var(--space-6);
}
.bantuan {
  margin: var(--space-4) 0 0;
  color: var(--muted);
  font-size: 13px;
  text-align: center;
}
</style>
