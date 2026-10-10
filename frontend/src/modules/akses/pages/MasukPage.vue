<script setup lang="ts">
import { type Component, ref, shallowRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSesiStore } from '@/app/sesi'
import { LANGKAH_AWAL, tujuanSetelahMasuk, type Langkah, type LangkahTampil } from '../alurMasuk'
import LangkahSandi from '../components/LangkahSandi.vue'
import LangkahMfaBelumTersedia from '../components/LangkahMfaBelumTersedia.vue'

/**
 * Halaman masuk sebagai mesin status PRD 10.3.2. Tiap status punya satu komponen langkah;
 * tiket 1.1b cukup mengganti komponen perlu_mfa dan perlu_daftar_mfa di tabel ini.
 */
const KOMPONEN_LANGKAH: Record<LangkahTampil['status'], Component> = {
  sandi: LangkahSandi,
  perlu_mfa: LangkahMfaBelumTersedia,
  perlu_daftar_mfa: LangkahMfaBelumTersedia,
}

const sesi = useSesiStore()
const route = useRoute()
const router = useRouter()
const langkah = shallowRef<LangkahTampil>(LANGKAH_AWAL)
// Ganti kunci agar langkah yang sama (mis. kembali ke sandi) dipasang ulang dengan isian bersih.
const putaran = ref(0)

/** Dipanggil komponen langkah dengan hasil dari server; galatnya ditampilkan oleh langkah itu. */
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
  <component
    :is="KOMPONEN_LANGKAH[langkah.status]"
    :key="`${langkah.status}-${putaran}`"
    :langkah="langkah"
    :lanjut="lanjut"
    @kembali="kembali"
  />
</template>
