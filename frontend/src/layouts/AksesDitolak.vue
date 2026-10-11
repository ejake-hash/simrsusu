<script setup lang="ts">
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref, useId } from 'vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { buatPermintaan, daftarPermintaan } from '@/modules/akses/apiPersetujuan'
import { KUNCI, pesanError } from '@/modules/akses/pengaturan'
import type { PermintaanAkses } from '@/modules/akses/tipePersetujuan'
import { ApiError } from '@/shared/api/klien'
import { formatTanggalWaktu } from '@/shared/utils/waktu'

/**
 * Layar "Akses ditolak" untuk halaman yang ada tetapi belum diberikan ke akun ini (tiket 1.2d, PRD 9.3): satu klik
 * "Minta akses" + alasan → POST /permintaan-akses, diteruskan ke pengelola akses. Permintaan yang masih menunggu untuk
 * halaman yang sama ditampilkan, bukan formulir baru. Jalur yang tidak dikenal tetap "Halaman tidak ditemukan".
 */
const props = defineProps<{ judul: string; kodeSubMenu: string }>()
const qc = useQueryClient()
const idAlasan = `alasan-akses-${useId()}`

const kueri = useQuery({
  queryKey: computed(() => KUNCI.daftarPermintaan({ milik_saya: true, status: 'menunggu' })),
  queryFn: async () => (await daftarPermintaan({ milik_saya: true, status: 'menunggu' }, null, 200)).data,
  staleTime: 30_000,
})
const menunggu = computed<PermintaanAkses | null>(() => kueri.data.value?.find((p) => p.sasaran_kode === props.kodeSubMenu) ?? null)

const formulir = ref(false)
const alasan = ref('')
const errorAlasan = ref<string | undefined>()
const error = ref<string | null>(null)
const sibuk = ref(false)
const terkirim = ref<PermintaanAkses | null>(null)

async function kirim() {
  const a = alasan.value.trim()
  if (a.length < 5) {
    errorAlasan.value = 'Tulis untuk apa akses ini dibutuhkan, minimal 5 karakter.'
    return
  }
  errorAlasan.value = undefined
  sibuk.value = true
  error.value = null
  try {
    terkirim.value = await buatPermintaan({ sasaran_jenis: 'menu', sasaran_kode: props.kodeSubMenu, alasan: a })
    formulir.value = false
    void qc.invalidateQueries({ queryKey: KUNCI.permintaan })
  } catch (e) {
    if (e instanceof ApiError && e.kode === 'permintaan_ganda') void kueri.refetch()
    error.value = pesanError(e)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <section class="ditolak" aria-labelledby="judul-ditolak">
    <Ikon nama="gembok" :ukuran="28" />
    <h1 id="judul-ditolak">Akses ditolak</h1>
    <p>Akun Anda belum punya akses ke <b>{{ judul }}</b>. Akses diberikan pengelola akses sesuai tugas Anda.</p>

    <p v-if="terkirim" class="catatan catatan-info" role="status">
      <Ikon nama="centang" :ukuran="16" />
      <span>Permintaan {{ terkirim.nomor }} terkirim ke pengelola akses. Halaman ini terbuka setelah akses diberikan.</span>
    </p>
    <p v-else-if="menunggu" class="catatan catatan-info">
      <Ikon nama="jam" :ukuran="16" />
      <span>Permintaan Anda {{ menunggu.nomor }} ({{ formatTanggalWaktu(menunggu.dibuat_pada) }}) masih menunggu tindak lanjut pengelola akses.</span>
    </p>
    <form v-else-if="formulir" class="formulir" novalidate @submit.prevent="kirim">
      <KolomIsian :id="idAlasan" v-slot="a" label="Untuk apa akses ini dibutuhkan?" :error="errorAlasan" petunjuk="Mis. bertugas di unit itu mulai bulan ini." wajib>
        <textarea v-bind="a" v-model="alasan" class="isian" rows="3" maxlength="500" />
      </KolomIsian>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
      <div class="aksi">
        <button type="button" class="tombol" :disabled="sibuk" @click="formulir = false">Batal</button>
        <Tombol type="submit" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Mengirim…' : 'Kirim permintaan' }}</Tombol>
      </div>
    </form>
    <div v-else class="aksi">
      <RouterLink to="/" class="tombol">Kembali ke Beranda</RouterLink>
      <button type="button" class="tombol tombol-utama" @click="formulir = true">Minta akses</button>
    </div>
  </section>
</template>

<style scoped>
.ditolak {
  max-width: 560px;
  margin: var(--space-6) auto;
  padding: var(--space-6);
  border: 1px solid var(--border);
  border-radius: var(--radius-card);
  background: var(--surface);
  color: var(--muted);
}
.ditolak > svg {
  color: var(--warn);
}
h1 {
  margin: var(--space-2) 0;
  color: var(--text);
  font-size: 20px;
}
.ditolak > p {
  margin: 0 0 var(--space-4);
}
.formulir {
  text-align: left;
}
.aksi {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: var(--space-2);
}
</style>
