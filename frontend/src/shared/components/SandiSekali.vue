<script setup lang="ts">
import { ref, watch } from 'vue'
import Ikon from '@/design-system/ikon/Ikon.vue'

/**
 * Sandi yang ditampilkan SEKALI (sandi awal akun 1.8d, sandi sementara reset 1.14): disembunyikan dulu (layar bisa
 * terlihat orang lain), tombol tampilkan dan salin. Nilai hanya lewat prop dari dialog pemanggil, yang membuangnya saat
 * ditutup; komponen ini tidak menyimpannya di tempat lain.
 */
const props = defineProps<{ sandi: string; label: string }>()

const tampil = ref(false)
const tersalin = ref(false)
watch(
  () => props.sandi,
  () => {
    tampil.value = false
    tersalin.value = false
  },
)

async function salin() {
  try {
    await navigator.clipboard.writeText(props.sandi)
    tersalin.value = true
  } catch {
    // Clipboard ditolak peramban: pengguna menyalin manual dari teks yang ditampilkan.
    tampil.value = true
  }
}
</script>

<template>
  <div class="sandi-sekali">
    <code v-if="tampil" class="sandi" translate="no">{{ sandi }}</code>
    <span v-else class="sandi"><span aria-hidden="true">{{ '•'.repeat(sandi.length) }}</span><span class="sr-only">Disembunyikan</span></span>
    <div class="aksi-sandi">
      <button type="button" class="tombol" :aria-pressed="tampil" @click="tampil = !tampil">{{ tampil ? 'Sembunyikan' : 'Tampilkan' }}</button>
      <button type="button" class="tombol" @click="salin"><Ikon nama="salin" :ukuran="16" />{{ tersalin ? 'Tersalin' : 'Salin sandi' }}</button>
    </div>
    <p class="ket" role="status">{{ tersalin ? `${label} tersalin ke papan klip.` : '' }}</p>
  </div>
</template>

<style scoped>
.sandi-sekali {
  display: grid;
  gap: var(--space-2);
  min-width: 0;
}
.sandi {
  font-family: var(--font-mono);
  font-size: 15px;
  letter-spacing: 0.04em;
  overflow-wrap: anywhere;
}
.aksi-sandi {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}
.ket {
  margin: 0;
  color: var(--muted);
  font-size: 12.5px;
}
.ket:empty {
  display: none;
}
</style>
