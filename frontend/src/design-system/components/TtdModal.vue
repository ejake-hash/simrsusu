<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { ApiError } from '@/shared/api/klien'
import { formatSisaWaktu, formatTanggalWaktu } from '@/shared/utils/waktu'

/**
 * Modal tanda tangan elektronik T1 (PRD 7.57.2, aturan UX 5.1: TTE hanya pemilik akun; aksi massal tidak menelan
 * peringatan). Menampilkan ringkasan setiap objek (beserta peringatannya), identitas penanda tangan = akun yang
 * sedang masuk, kalimat persetujuan yang wajib dicentang, dan PIN (tidak diminta selama sesi tanda tangan berlaku;
 * bila server menjawab `pin_wajib`, kolom PIN muncul). Isi yang ditandatangani dihitung server, bukan dikirim klien.
 * Penanda tangan yang diminta ≠ akun yang masuk → mode "minta tanda tangan": tidak bisa menandatangani.
 * `tandatangani(pin)` dipanggil pemanggil dan melempar ApiError kontrak; modal yang menafsirkannya. PIN tidak
 * disimpan di luar state modal dan dikosongkan setiap selesai/ditutup. Esc dan fokus terjebak dari Dialog.
 */
export interface ObjekTtd {
  id: string
  judul: string
  ringkasan?: string | null
  /** Peringatan per objek; selalu ditampilkan, juga di mode massal. */
  peringatan?: string | null
}
const props = withDefaults(
  defineProps<{
    objek: ObjekTtd[]
    /** Akun yang sedang masuk. */
    pengguna: { id: string; nama: string }
    /** Penanda tangan yang diminta; kosong = akun yang masuk. */
    penanda?: { id: string; nama: string } | null
    sesiBerlakuSampai?: string | null
    tandatangani: (pin: string | null) => Promise<void>
    /** Mode minta tanda tangan: kirim ke antrean penanda tangan (fase 1: tanpa endpoint, opsional). */
    minta?: (() => Promise<void>) | null
  }>(),
  { penanda: null, sesiBerlakuSampai: null, minta: null },
)
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [] }>()

const lain = computed(() => !!props.penanda && props.penanda.id !== props.pengguna.id)
const massal = computed(() => props.objek.length > 1)
const sekarang = ref(Date.now())
const sesiAktif = computed(() => !!props.sesiBerlakuSampai && new Date(props.sesiBerlakuSampai).getTime() > sekarang.value)
const setuju = ref(false)
const pin = ref('')
const perluPin = ref(true)
const errorPin = ref<string | null>(null)
const errorSetuju = ref<string | null>(null)
const errorObjek = ref<Record<number, string>>({})
const error = ref<string | null>(null)
const belumAktif = ref(false)
const terkunciSampai = ref<number | null>(null)
const sibuk = ref(false)
const isianPin = ref<HTMLInputElement | null>(null)

let detak: ReturnType<typeof setInterval> | undefined
const sisaKunci = computed(() => (terkunciSampai.value ? formatSisaWaktu(Math.ceil((terkunciSampai.value - sekarang.value) / 1000)) : null))
const terkunci = computed(() => sisaKunci.value !== null)

watch(buka, (b) => {
  pin.value = ''
  if (!b) {
    clearInterval(detak)
    return
  }
  sekarang.value = Date.now()
  setuju.value = false
  perluPin.value = !sesiAktif.value
  errorPin.value = null
  errorSetuju.value = null
  errorObjek.value = {}
  error.value = null
  belumAktif.value = false
  terkunciSampai.value = null
  clearInterval(detak)
  detak = setInterval(() => (sekarang.value = Date.now()), 1000)
})
onBeforeUnmount(() => clearInterval(detak))

async function kirim() {
  if (sibuk.value || terkunci.value) return
  errorSetuju.value = setuju.value ? null : 'Centang pernyataan untuk menandatangani.'
  errorPin.value = perluPin.value && !/^[0-9]{6}$/.test(pin.value) ? 'Masukkan PIN 6 angka.' : null
  if (errorSetuju.value || errorPin.value) return
  sibuk.value = true
  error.value = null
  errorObjek.value = {}
  try {
    await props.tandatangani(perluPin.value ? pin.value : null)
    pin.value = ''
    buka.value = false
    emit('selesai')
  } catch (e) {
    pin.value = ''
    tafsirkan(e)
  } finally {
    sibuk.value = false
  }
}

function tafsirkan(e: unknown) {
  if (!(e instanceof ApiError)) {
    error.value = 'Respons server tidak dikenali. Coba lagi.'
    return
  }
  const r0 = e.rincian[0]
  switch (e.kode) {
    case 'pin_wajib':
      perluPin.value = true
      errorPin.value = 'Sesi tanda tangan sudah berakhir. Masukkan PIN.'
      void nextTick(() => isianPin.value?.focus())
      return
    case 'pin_salah': {
      const sisa = typeof r0?.sisa_percobaan === 'number' ? r0.sisa_percobaan : null
      errorPin.value = sisa !== null ? `PIN salah. Sisa ${sisa} percobaan sebelum tanda tangan terkunci.` : 'PIN salah.'
      void nextTick(() => isianPin.value?.focus())
      return
    }
    case 'ttd_terkunci':
      terkunciSampai.value = Date.now() + (e.cobaLagiDetik ?? 15 * 60) * 1000
      error.value = 'Tanda tangan terkunci karena PIN salah 5 kali. Pemilik akun dan atasan langsung sudah diberi tahu.'
      return
    case 'ttd_belum_aktif':
      belumAktif.value = true
      error.value = 'Tanda tangan elektronik Anda belum aktif.'
      return
    case 'objek_tidak_bisa_ditandatangani':
      for (const r of e.rincian) {
        const i = Number(/^objek\.(\d+)/.exec(String(r.kolom ?? ''))?.[1])
        if (Number.isInteger(i)) errorObjek.value = { ...errorObjek.value, [i]: String(r.pesan ?? 'Tidak bisa ditandatangani.') }
      }
      error.value = `${e.message} Perbaiki daftar lalu ulangi.`
      return
    default:
      error.value = e.message
  }
}

const sibukMinta = ref(false)
async function kirimMinta() {
  if (!props.minta) return
  sibukMinta.value = true
  error.value = null
  try {
    await props.minta()
    buka.value = false
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Permintaan tanda tangan gagal dikirim.'
  } finally {
    sibukMinta.value = false
  }
}
const judul = computed(() => (lain.value ? 'Minta tanda tangan' : 'Tanda tangan elektronik (T1)'))
</script>

<template>
  <Dialog v-model:open="buka" :judul="judul" :uraian="lain ? 'Dokumen ini harus ditandatangani pemiliknya sendiri.' : 'Tidak tersertifikasi · dibuka dengan PIN pribadi Anda'" :sibuk="sibuk || sibukMinta" lebar="lebar">
    <form id="form-ttd" novalidate @submit.prevent="lain ? kirimMinta() : kirim()">
      <section class="dokumen" :aria-label="massal ? `${objek.length} dokumen ditandatangani` : 'Dokumen ditandatangani'">
        <p v-if="massal" class="ket">{{ objek.length }} dokumen, masing-masing ditandatangani dan tercatat terpisah:</p>
        <ol class="daftar">
          <li v-for="(o, i) in objek" :key="o.id" :class="{ salah: errorObjek[i] }">
            <b>{{ o.judul }}</b>
            <span v-if="o.ringkasan" class="ringkasan">{{ o.ringkasan }}</span>
            <span v-if="o.peringatan" class="peringatan"><Ikon nama="peringatan" :ukuran="14" />{{ o.peringatan }}</span>
            <span v-if="errorObjek[i]" class="error-objek" role="alert"><Ikon nama="peringatan" :ukuran="14" />{{ errorObjek[i] }}</span>
          </li>
        </ol>
      </section>

      <template v-if="lain">
        <p class="pernyataan">
          Penanda tangan: <b>{{ penanda?.nama }}</b>. Anda masuk sebagai <b>{{ pengguna.nama }}</b>, jadi Anda tidak bisa menandatangani atas namanya.
        </p>
        <p v-if="!minta" class="ket">Minta {{ penanda?.nama }} masuk dengan akunnya sendiri untuk menandatangani.</p>
      </template>
      <template v-else>
        <p class="identitas">Penanda tangan: <b>{{ pengguna.nama }}</b> <span class="ket">(akun yang sedang masuk)</span></p>
        <label class="centang" :class="{ salah: errorSetuju }">
          <input v-model="setuju" type="checkbox" :aria-invalid="errorSetuju ? true : undefined" :aria-describedby="errorSetuju ? 'error-setuju-ttd' : undefined" />
          <span>Saya, <b>{{ pengguna.nama }}</b>, menyatakan isi {{ massal ? 'dokumen-dokumen' : '' }} di atas benar dan saya menandatanganinya.</span>
        </label>
        <p v-if="errorSetuju" id="error-setuju-ttd" class="kolom-error">{{ errorSetuju }}</p>

        <KolomIsian v-if="perluPin" v-slot="a" label="PIN tanda tangan" :error="errorPin ?? undefined" petunjuk="6 angka. PIN tidak pernah dikirim lewat alamat halaman atau disimpan aplikasi.">
          <input
            v-bind="a"
            ref="isianPin"
            v-model="pin"
            type="password"
            inputmode="numeric"
            autocomplete="off"
            maxlength="6"
            class="isian pin"
            :disabled="terkunci"
            @input="pin = pin.replace(/\D/g, '').slice(0, 6)"
          />
        </KolomIsian>
        <p v-else class="catatan catatan-info sesi" role="status">
          <Ikon nama="info" :ukuran="16" />
          <span>Sesi tanda tangan berlaku sampai {{ formatTanggalWaktu(sesiBerlakuSampai) }}; PIN tidak perlu dimasukkan lagi.</span>
        </p>
      </template>

      <div role="alert" class="wadah-error">
        <p v-if="error" class="catatan catatan-bahaya">
          <Ikon nama="peringatan" :ukuran="16" />
          <span>
            {{ error }}
            <template v-if="sisaKunci"> Coba lagi dalam {{ sisaKunci }}.</template>
            <RouterLink v-if="belumAktif" to="/tanda-tangan" @click="buka = false">Aktifkan tanda tangan elektronik</RouterLink>
          </span>
        </p>
      </div>
      <p class="hukum">PP 71/2019 Ps. 59 ayat 3 · kunci pribadi dibuka dengan PIN · isi dihitung dan dicap waktu oleh server</p>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk || sibukMinta" @click="buka = false">Batal</button>
      <Tombol v-if="lain && minta" type="submit" form="form-ttd" varian="utama" :sibuk="sibukMinta">Kirim permintaan tanda tangan</Tombol>
      <Tombol v-else-if="!lain" type="submit" form="form-ttd" varian="utama" :sibuk="sibuk" :disabled="terkunci">
        {{ sibuk ? 'Menandatangani…' : massal ? `Tanda tangani ${objek.length} dokumen` : 'Tanda tangani' }}
      </Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.dokumen {
  margin-bottom: var(--space-3);
  padding: var(--space-3);
  border: 1px solid var(--border);
  border-radius: var(--radius-input);
  background: var(--bg);
}
.daftar {
  display: grid;
  gap: var(--space-2);
  max-height: 260px;
  margin: 0;
  padding-left: var(--space-4);
  overflow-y: auto;
}
.daftar li {
  display: grid;
  gap: 2px;
}
.daftar li.salah b {
  color: var(--danger);
}
.ringkasan,
.ket {
  color: var(--muted);
  font-size: 12.5px;
}
.ket {
  margin: 0 0 var(--space-2);
}
.peringatan,
.error-objek {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 12.5px;
}
.peringatan {
  color: var(--warn);
}
.error-objek {
  color: var(--danger);
}
.identitas,
.pernyataan {
  margin: 0 0 var(--space-3);
}
.centang {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  margin-bottom: var(--space-2);
  font-size: 13.5px;
  cursor: pointer;
}
.centang input {
  margin-top: 3px;
}
@media (min-width: 768px) and (max-width: 1279.98px) {
  .centang {
    min-height: 44px;
  }
}
.pin {
  max-width: 180px;
  font-family: var(--font-mono);
  letter-spacing: 0.3em;
}
.sesi {
  margin: 0 0 var(--space-3);
}
.wadah-error .catatan {
  margin: 0 0 var(--space-2);
}
.hukum {
  margin: var(--space-2) 0 0;
  color: var(--muted);
  font-size: 11.5px;
}
</style>
