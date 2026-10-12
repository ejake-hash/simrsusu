<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { ApiError } from '@/shared/api/klien'
import { simpanKredensial } from '../api'
import { LABEL_KOLOM, LABEL_LINGKUNGAN } from '../integrasi'
import type { KredensialLingkungan, StatusSistemIntegrasi } from '../tipe'

/**
 * Kredensial satu sistem untuk satu lingkungan (PUT /integrasi/sistem/{kode}/kredensial, kontrak v0.13.0). Tulis saja:
 * isian rahasia selalu kosong (nilai tidak pernah dikirim server; kosong = tidak diubah), isian bukan rahasia diisi
 * nilai saat ini. Hanya isian yang diubah yang dikirim. Nilai yang diketik tidak disimpan di luar dialog.
 */
const props = defineProps<{ sistem: StatusSistemIntegrasi; kredensial: KredensialLingkungan }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [status: StatusSistemIntegrasi] }>()

const nilai = ref<Record<string, string>>({})
const aktifkan = ref(false)
const kesalahan = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const sibuk = ref(false)

// immediate: dialog dipasang (v-if) bersamaan dengan dibuka.
watch(
  buka,
  (b) => {
    if (b) reset()
    else nilai.value = {}
  },
  { immediate: true },
)
function reset() {
  nilai.value = Object.fromEntries(props.kredensial.kolom.map((k) => [k.nama, k.rahasia ? '' : (k.nilai ?? '')]))
  aktifkan.value = false
  kesalahan.value = {}
  error.value = null
}

const petunjuk = (k: KredensialLingkungan['kolom'][number]) =>
  !k.rahasia ? undefined : k.terisi ? `Terisi${k.akhir ? `, berakhiran ····${k.akhir}` : ''}. Kosongkan bila tidak diubah.` : 'Belum diisi.'
const berubah = computed(() =>
  Object.fromEntries(
    props.kredensial.kolom.filter((k) => (k.rahasia ? nilai.value[k.nama] !== '' : nilai.value[k.nama] !== (k.nilai ?? ''))).map((k) => [k.nama, nilai.value[k.nama] ?? '']),
  ),
)

async function kirim() {
  const e: Record<string, string> = {}
  for (const [nama, v] of Object.entries(berubah.value)) {
    if (!v.trim()) e[nama] = 'Isi nilainya; kolom ini tidak bisa dikosongkan.'
    else if (v !== v.trim()) e[nama] = 'Hapus spasi di awal atau akhir.'
    else if (v.length > 500) e[nama] = 'Paling banyak 500 karakter.'
  }
  kesalahan.value = e
  if (Object.keys(e).length) return
  if (!Object.keys(berubah.value).length && !aktifkan.value) {
    error.value = 'Belum ada yang diubah.'
    return
  }
  sibuk.value = true
  error.value = null
  try {
    const hasil = await simpanKredensial(props.sistem.kode, { lingkungan: props.kredensial.lingkungan, kolom: berubah.value, aktifkan: aktifkan.value || undefined })
    buka.value = false
    emit('selesai', hasil)
  } catch (err) {
    if (err instanceof ApiError && err.rincian.length && ['validasi_gagal', 'kredensial_belum_lengkap'].includes(err.kode)) {
      kesalahan.value = Object.fromEntries(err.rincian.map((r) => [String(r.kolom ?? '').replace(/^kolom\./, ''), String(r.pesan ?? err.message)]))
      error.value = err.message
    } else error.value = pesanError(err)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="`Kredensial ${sistem.nama} · ${LABEL_LINGKUNGAN[kredensial.lingkungan]}`" uraian="Nilai rahasia tidak pernah ditampilkan lagi sesudah disimpan." :sibuk="sibuk">
    <form id="form-kredensial" novalidate autocomplete="off" @submit.prevent="kirim">
      <KolomIsian v-for="k in kredensial.kolom" :key="k.nama" v-slot="a" :label="LABEL_KOLOM[k.nama] ?? k.nama" :error="kesalahan[k.nama]" :petunjuk="petunjuk(k)" :wajib="k.wajib && !k.terisi">
        <input
          v-bind="a"
          v-model="nilai[k.nama]"
          :type="k.rahasia ? 'password' : 'text'"
          class="isian"
          maxlength="500"
          :autocomplete="k.rahasia ? 'new-password' : 'off'"
          data-1p-ignore
          data-lpignore="true"
          spellcheck="false"
        />
      </KolomIsian>
      <label v-if="!kredensial.aktif" class="centang">
        <input v-model="aktifkan" type="checkbox" />
        Jadikan {{ LABEL_LINGKUNGAN[kredensial.lingkungan].toLowerCase() }} lingkungan aktif
        <span class="ket">Kiriman baru dikirim ke lingkungan aktif; kiriman lingkungan lain yang belum terkirim menjadi tertahan.</span>
      </label>
      <p v-if="kredensial.lingkungan === 'produksi'" class="catatan catatan-peringatan">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>Kredensial produksi mengirim data pasien asli ke sistem luar.</span>
      </p>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-kredensial" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : 'Simpan' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.centang {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 2px var(--space-2);
  align-items: start;
  margin: var(--space-2) 0 var(--space-3);
  font-size: 13.5px;
}
.centang .ket {
  grid-column: 2;
  color: var(--muted);
  font-size: 12px;
}
</style>
