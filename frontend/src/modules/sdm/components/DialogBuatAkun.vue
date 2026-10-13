<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import SandiSekali from '@/shared/components/SandiSekali.vue'
import { buatAkunPegawai } from '../api'
import type { PegawaiRinci } from '../tipe'

/**
 * Buat akun untuk pegawai (satu akun = satu pegawai, K5). Sandi awal dari server ditampilkan SEKALI di langkah kedua
 * dengan tombol salin; disimpan hanya di state komponen ini dan dikosongkan saat dialog ditutup (tidak ke store,
 * cache kueri, storage, atau log). Akun baru tanpa peran dan wajib ganti sandi saat masuk pertama.
 */
const props = defineProps<{ pegawai: PegawaiRinci | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [akunId: string] }>()

const POLA = /^[a-z][a-z0-9._-]{2,49}$/
const namaPengguna = ref('')
const email = ref('')
const dasar = ref('')
const kesalahan = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const sibuk = ref(false)
/** Langkah kedua: akun dibuat. `sandi` hanya hidup selama dialog terbuka. */
const hasil = ref<{ akunId: string; namaPengguna: string; sandi: string } | null>(null)

/** Saran dari nama pegawai: huruf kecil, kata dipisah titik (mis. "Contoh Bidan Baru" → contoh.bidan.baru). */
const saran = computed(() =>
  (props.pegawai?.nama ?? '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9\s]/g, ' ')
    .trim()
    .split(/\s+/)
    .slice(0, 3)
    .join('.')
    .slice(0, 50),
)

watch(buka, (b) => {
  // Buka atau tutup = lupakan sandi awal.
  hasil.value = null
  if (!b) return
  namaPengguna.value = POLA.test(saran.value) ? saran.value : ''
  email.value = ''
  dasar.value = ''
  kesalahan.value = {}
  error.value = null
})

async function kirim() {
  if (!props.pegawai) return
  const e: Record<string, string> = {}
  if (!POLA.test(namaPengguna.value.trim())) e.nama_pengguna = 'Huruf kecil, angka, titik, garis bawah, atau tanda hubung; 3–50 karakter, diawali huruf.'
  if (email.value.trim() && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email.value.trim())) e.email = 'Alamat email tidak valid.'
  if (dasar.value.trim().length < 3) e.dasar_pemberian = 'Isi dasar pemberian akun, minimal 3 karakter.'
  kesalahan.value = e
  if (Object.keys(e).length) return
  sibuk.value = true
  error.value = null
  try {
    const r = await buatAkunPegawai(props.pegawai.id, { nama_pengguna: namaPengguna.value.trim(), email: email.value.trim() || null, dasar_pemberian: dasar.value.trim() })
    hasil.value = { akunId: r.akun.id, namaPengguna: r.akun.nama_pengguna, sandi: r.sandi_awal }
    emit('selesai', r.akun.id)
  } catch (err) {
    const kolom = errorKolom(err, ['nama_pengguna', 'email', 'dasar_pemberian'])
    if (Object.keys(kolom).length) kesalahan.value = kolom
    else error.value = pesanError(err)
  } finally {
    sibuk.value = false
  }
}

</script>

<template>
  <Dialog v-model:open="buka" :judul="hasil ? 'Akun dibuat' : `Buat akun untuk ${pegawai?.nama ?? ''}`" :sibuk="sibuk" :tutup-lewat-tombol="!!hasil">
    <div v-if="hasil" class="hasil">
      <p class="catatan catatan-peringatan">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>Sandi awal hanya ditampilkan sekali. Serahkan langsung kepada pemilik akun; ia wajib menggantinya saat masuk pertama. Bila hilang, sandi bisa direset di Pengaturan → Akun & akses.</span>
      </p>
      <dl class="info">
        <div>
          <dt>Nama pengguna</dt>
          <dd><code>{{ hasil.namaPengguna }}</code></dd>
        </div>
        <div>
          <dt>Sandi awal</dt>
          <dd><SandiSekali :sandi="hasil.sandi" label="Sandi awal" /></dd>
        </div>
      </dl>
      <p class="ket">Akun belum punya peran. Tugaskan peran lewat Pengaturan → Akun & akses.</p>
    </div>
    <form v-else id="form-buat-akun" novalidate @submit.prevent="kirim">
      <KolomIsian v-slot="a" label="Nama pengguna" :error="kesalahan.nama_pengguna" petunjuk="Tidak bisa diubah sesudah dibuat." wajib>
        <input v-bind="a" v-model="namaPengguna" class="isian kode" autocomplete="off" spellcheck="false" maxlength="50" @input="namaPengguna = namaPengguna.toLowerCase()" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Email (opsional)" :error="kesalahan.email" petunjuk="Untuk notifikasi; tautan aktivasi lewat email menyusul.">
        <input v-bind="a" v-model="email" type="email" class="isian" autocomplete="off" maxlength="200" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Dasar pemberian" :error="kesalahan.dasar_pemberian" petunjuk="Nomor SK penempatan atau surat permintaan akun." wajib>
        <input v-bind="a" v-model="dasar" class="isian" autocomplete="off" />
      </KolomIsian>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
    </form>
    <template #aksi>
      <button v-if="hasil" type="button" class="tombol tombol-utama" @click="buka = false">Selesai</button>
      <template v-else>
        <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
        <Tombol type="submit" form="form-buat-akun" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Membuat…' : 'Buat akun' }}</Tombol>
      </template>
    </template>
  </Dialog>
</template>

<style scoped>
.kode,
code {
  font-family: var(--font-mono);
}
.catatan {
  margin: 0 0 var(--space-3);
}
.info {
  display: grid;
  gap: var(--space-2);
  margin: 0 0 var(--space-3);
}
.info div {
  display: grid;
  grid-template-columns: 130px minmax(0, 1fr);
  gap: var(--space-2);
}
.info dt {
  color: var(--muted);
  font-size: 12.5px;
}
.info dd {
  margin: 0;
  overflow-wrap: anywhere;
}
.ket {
  margin: var(--space-2) 0 0;
  color: var(--muted);
  font-size: 12.5px;
}
.ket:empty {
  display: none;
}
</style>
