<script setup lang="ts">
import { ref } from 'vue'
import Tombol from '@/design-system/components/Tombol.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { ApiError } from '@/shared/api/klien'
import { unggahBerkasProfilRs, type Berversi } from '../api'
import type { JenisBerkas, ProfilRsApi } from '../tipe'

/**
 * Berkas paket identitas (logo, motif, foto) dengan PUT multipart + If-Match (kontrak v0.3.0). Tipe dan ukuran
 * diperiksa server dari isi berkas; batas di sini hanya petunjuk. Sesudah unggah, profil baru dipasang ke seluruh
 * layar (logo halaman masuk berganti tanpa build ulang).
 */
/** `jenis`: berkas yang ditampilkan (bawaan semua); foto ada di tab Halaman masuk (tiket 1.15). */
const props = withDefaults(defineProps<{ profil: Berversi<ProfilRsApi>; bolehUbah: boolean; jenis?: JenisBerkas[] }>(), { jenis: () => ['logo', 'motif', 'foto'] })
const emit = defineEmits<{ diunggah: [hasil: Berversi<ProfilRsApi>]; versiBerubah: [] }>()

const DAFTAR: { jenis: JenisBerkas; label: string; uraian: string; terima: string; kunci: 'logo_url' | 'motif_url' | 'foto_url' }[] = [
  { jenis: 'logo', label: 'Logo', uraian: 'SVG atau PNG, maks. 200 KB. Tampil di halaman masuk dan kop.', terima: 'image/svg+xml,image/png', kunci: 'logo_url' },
  { jenis: 'motif', label: 'Motif grafis', uraian: 'SVG atau PNG, maks. 500 KB. Hiasan panel merek halaman masuk.', terima: 'image/svg+xml,image/png', kunci: 'motif_url' },
  { jenis: 'foto', label: 'Foto panel', uraian: 'WebP, JPEG, atau PNG, maks. 2 MB. Tampil terpotong mengikuti panel; hapus lokasi (GPS) dan metadata lain dari foto sebelum diunggah.', terima: 'image/webp,image/jpeg,image/png', kunci: 'foto_url' },
]

const isian = ref<Partial<Record<JenisBerkas, HTMLInputElement | null>>>({})
const sibuk = ref<JenisBerkas | null>(null)
const error = ref<Partial<Record<JenisBerkas, string>>>({})

async function unggah(jenis: JenisBerkas, e: Event) {
  const el = e.target as HTMLInputElement
  const berkas = el.files?.[0]
  el.value = ''
  if (!berkas) return
  sibuk.value = jenis
  error.value = { ...error.value, [jenis]: undefined }
  try {
    emit('diunggah', await unggahBerkasProfilRs(jenis, berkas, props.profil.etag))
  } catch (err) {
    if (err instanceof ApiError && err.status === 412) emit('versiBerubah')
    const rinci = err instanceof ApiError ? err.rincian.find((r) => r.kolom === 'berkas')?.pesan : undefined
    error.value = { ...error.value, [jenis]: [pesanError(err), rinci].filter(Boolean).join(' ') }
  } finally {
    sibuk.value = null
  }
}
</script>

<template>
  <ul class="berkas">
    <li v-for="b in DAFTAR.filter((x) => jenis.includes(x.jenis))" :key="b.jenis" class="baris">
      <div class="gambar" :class="b.jenis">
        <img v-if="profil.data.paket_identitas[b.kunci]" :src="profil.data.paket_identitas[b.kunci]!" :alt="`${b.label} saat ini`" />
        <span v-else class="kosong">Belum ada</span>
      </div>
      <div class="teks">
        <p class="label">{{ b.label }}</p>
        <p :id="`uraian-berkas-${b.jenis}`" class="uraian">{{ b.uraian }}</p>
        <p v-if="error[b.jenis]" class="catatan catatan-bahaya galat" role="alert">
          <Ikon nama="peringatan" :ukuran="16" />
          <span>{{ error[b.jenis] }}</span>
        </p>
      </div>
      <template v-if="bolehUbah">
        <input
          :ref="(el) => (isian[b.jenis] = el as HTMLInputElement | null)"
          type="file"
          class="sr-only"
          tabindex="-1"
          aria-hidden="true"
          :accept="b.terima"
          :data-berkas="b.jenis"
          @change="unggah(b.jenis, $event)"
        />
        <Tombol :sibuk="sibuk === b.jenis" :disabled="sibuk !== null && sibuk !== b.jenis" :aria-describedby="`uraian-berkas-${b.jenis}`" @click="isian[b.jenis]?.click()">
          {{ sibuk === b.jenis ? 'Mengunggah…' : profil.data.paket_identitas[b.kunci] ? `Ganti ${b.label.toLowerCase()}` : `Unggah ${b.label.toLowerCase()}` }}
        </Tombol>
      </template>
    </li>
  </ul>
</template>

<style scoped>
.berkas {
  display: grid;
  gap: var(--space-3);
  margin: 0;
  padding: 0;
  list-style: none;
}
.baris {
  display: grid;
  grid-template-columns: 72px minmax(0, 1fr) auto;
  align-items: center;
  gap: var(--space-3);
}
.gambar {
  display: grid;
  place-items: center;
  width: 72px;
  height: 56px;
  overflow: hidden;
  border: 1px solid var(--border);
  border-radius: var(--radius-input);
  background: var(--bg);
}
.gambar img {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
}
.gambar.foto img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.kosong {
  color: var(--subtle);
  font-size: 11px;
}
.teks p {
  margin: 0;
}
.label {
  font-weight: var(--font-weight-medium);
}
.uraian {
  color: var(--muted);
  font-size: 12px;
}
.galat {
  margin-top: var(--space-1) !important;
}
@media (max-width: 599.98px) {
  .baris {
    grid-template-columns: 72px minmax(0, 1fr);
  }
  .baris > :deep(.tombol) {
    grid-column: 1 / -1;
  }
}
</style>
