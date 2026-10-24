<script setup lang="ts">
import { ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import { ApiError } from '@/shared/api/klien'
import { verifikasiGabung } from '../api'
import { formatNoRm } from '../pasien'
import type { UsulanGabung } from '../tipe'

/**
 * Verifikasi Rekam Medis atas usulan gabung RM: setujui (kunjungan dipindah, RM lama ditandai digabung) atau tolak
 * (catatan wajib). Pengusul tidak bisa memverifikasi; 403/409 tampil sebagai pesan dan 409 menawarkan muat ulang.
 */
const props = defineProps<{ usulan: UsulanGabung }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: UsulanGabung]; muatUlang: [] }>()

const keputusan = ref<'setuju' | 'tolak'>('setuju')
const catatan = ref('')
const kesalahan = ref<string | undefined>()
const error = ref<string | null>(null)
const basi = ref(false)
const sibuk = ref(false)

watch(buka, (b) => {
  if (!b) return
  keputusan.value = 'setuju'
  catatan.value = ''
  kesalahan.value = undefined
  error.value = null
  basi.value = false
})

async function kirim() {
  const t = catatan.value.trim()
  if (keputusan.value === 'tolak' && t.length < 5) {
    kesalahan.value = 'Tulis catatan penolakan, minimal 5 karakter.'
    return
  }
  kesalahan.value = undefined
  sibuk.value = true
  error.value = null
  try {
    const hasil = await verifikasiGabung(props.usulan.id, keputusan.value, t || null)
    buka.value = false
    emit('selesai', hasil)
  } catch (err) {
    const kolom = errorKolom(err, ['catatan'])
    if (kolom.catatan) kesalahan.value = kolom.catatan
    else {
      error.value = pesanError(err)
      basi.value = err instanceof ApiError && err.status === 409 && err.kode === 'tahap_tidak_sesuai'
    }
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="`Verifikasi gabung ${formatNoRm(usulan.sumber.no_rm)} ke ${formatNoRm(usulan.tujuan.no_rm)}?`" :sibuk="sibuk">
    <form id="form-verifikasi-gabung" novalidate @submit.prevent="kirim">
      <dl class="ringkas">
        <dt>RM digabung</dt>
        <dd>{{ formatNoRm(usulan.sumber.no_rm) }} · {{ usulan.sumber.nama }}</dd>
        <dt>RM dipertahankan</dt>
        <dd>{{ formatNoRm(usulan.tujuan.no_rm) }} · {{ usulan.tujuan.nama }}</dd>
        <dt>Alasan</dt>
        <dd>{{ usulan.alasan }}</dd>
      </dl>
      <fieldset class="pilihan">
        <legend>Keputusan</legend>
        <label><input v-model="keputusan" type="radio" value="setuju" name="keputusan" /> Setujui gabung</label>
        <label><input v-model="keputusan" type="radio" value="tolak" name="keputusan" /> Tolak</label>
      </fieldset>
      <p class="catatan" :class="keputusan === 'setuju' ? 'catatan-peringatan' : 'catatan-info'">
        <Ikon :nama="keputusan === 'setuju' ? 'peringatan' : 'info'" :ukuran="16" />
        <span v-if="keputusan === 'setuju'">Kunjungan RM lama langsung pindah ke RM tujuan. RM lama ditandai "digabung ke" dan tidak dihapus. Pemisahan kembali belum tersedia.</span>
        <span v-else>Usulan ditutup tanpa mengubah data pasien. Pengusul membaca catatan Anda.</span>
      </p>
      <KolomIsian v-slot="a" :label="keputusan === 'tolak' ? 'Catatan penolakan' : 'Catatan (opsional)'" :error="kesalahan" :wajib="keputusan === 'tolak'">
        <textarea v-bind="a" v-model="catatan" class="isian" rows="3" maxlength="300" />
      </KolomIsian>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
      <button v-if="basi" type="button" class="tombol" @click="((buka = false), emit('muatUlang'))">Muat ulang usulan</button>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-verifikasi-gabung" :varian="keputusan === 'tolak' ? 'bahaya' : 'utama'" :sibuk="sibuk">
        {{ sibuk ? 'Memproses…' : keputusan === 'setuju' ? 'Setujui gabung' : 'Tolak usulan' }}
      </Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.catatan {
  margin: 0 0 var(--space-3);
}
.ringkas {
  display: grid;
  grid-template-columns: max-content 1fr;
  gap: var(--space-1) var(--space-3);
  margin: 0 0 var(--space-3);
}
.ringkas dt {
  color: var(--muted);
}
.ringkas dd {
  margin: 0;
}
.pilihan {
  display: flex;
  gap: var(--space-4);
  margin: 0 0 var(--space-3);
  padding: 0;
  border: 0;
}
.pilihan legend {
  margin-bottom: var(--space-1);
  font-weight: var(--font-weight-semibold);
}
</style>
