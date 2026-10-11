<script setup lang="ts">
import { type Component, ref, shallowRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSesiStore } from '@/app/sesi'
import { LANGKAH_AWAL, tujuanSetelahMasuk, type Langkah, type LangkahTampil } from '../alurMasuk'
import LangkahSandi from '../components/LangkahSandi.vue'
import LangkahDaftarMfa from '../components/LangkahDaftarMfa.vue'
import LangkahKodeMfa from '../components/LangkahKodeMfa.vue'

/** Halaman masuk sebagai mesin status PRD 10.3.2. Tiap status punya satu komponen langkah. */
const KOMPONEN_LANGKAH: Record<LangkahTampil['status'], Component> = {
  sandi: LangkahSandi,
  perlu_mfa: LangkahKodeMfa,
  perlu_daftar_mfa: LangkahDaftarMfa,
}

const sesi = useSesiStore()
const route = useRoute()
const router = useRouter()
const langkah = shallowRef<LangkahTampil>(LANGKAH_AWAL)
// Ganti kunci agar langkah yang sama (mis. kembali ke sandi) dipasang ulang dengan isian bersih.
const putaran = ref(0)

/** Dipanggil komponen langkah dengan hasil dari server; errornya ditampilkan oleh langkah itu. */
async function lanjut(berikut: Langkah): Promise<void> {
  if (berikut.status !== 'masuk') {
    langkah.value = berikut
    putaran.value++
    return
  }
  await sesi.muatSetelahMasuk()
  await router.replace(tujuanSetelahMasuk(route.query.kembali))
}

function kembali(): void {
  langkah.value = LANGKAH_AWAL
  putaran.value++
}
</script>

<template>
  <Transition name="langkah" mode="out-in">
    <component
      :is="KOMPONEN_LANGKAH[langkah.status]"
      :key="`${langkah.status}-${putaran}`"
      :langkah="langkah"
      :lanjut="lanjut"
      @kembali="kembali"
    />
  </Transition>
</template>

<style scoped>
/* Seperti prototipe: langkah baru muncul pelan dari bawah; tanpa animasi bila diminta sistem. */
.langkah-enter-active {
  transition:
    opacity 0.25s ease,
    transform 0.25s ease;
}
.langkah-enter-from {
  opacity: 0;
  transform: translateY(4px);
}
@media (prefers-reduced-motion: reduce) {
  .langkah-enter-active {
    transition: none;
  }
}
</style>
