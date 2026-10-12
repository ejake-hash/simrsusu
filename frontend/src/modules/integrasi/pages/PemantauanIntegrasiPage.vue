<script setup lang="ts">
import { useQuery } from '@tanstack/vue-query'
import { computed } from 'vue'
import HalamanModul from '@/layouts/HalamanModul.vue'
import { daftarSistem } from '../api'
import DaftarKiriman from '../components/DaftarKiriman.vue'
import DaftarSistem from '../components/DaftarSistem.vue'
import { KUNCI_INTEGRASI, SISTEM_TUJUAN } from '../integrasi'

/**
 * Integrasi dan Laporan → SATUSEHAT / Bridging BPJS (tiket 1.9 fe 1, FR-50-08): kartu status sistem tujuan (tanpa
 * kredensial bagi pemantau) dan kotak keluar tujuan itu. Izin per tujuan dari server.
 */
const props = defineProps<{ tujuan: 'satusehat' | 'bpjs' }>()
const sistem = computed(() => SISTEM_TUJUAN[props.tujuan])
const status = useQuery({ queryKey: KUNCI_INTEGRASI.sistem, queryFn: daftarSistem, staleTime: 0 })
</script>

<template>
  <HalamanModul
    :izin="`integrasi.${tujuan}.lihat`"
    :judul="tujuan === 'satusehat' ? 'SATUSEHAT' : 'Bridging BPJS'"
    :uraian="tujuan === 'satusehat' ? 'Status pengiriman ke SATUSEHAT dan kiriman yang perlu ditindaklanjuti.' : 'Status VClaim dan Aplicares serta kiriman yang perlu ditindaklanjuti.'"
  >
    <DaftarSistem :hanya="sistem" />
    <DaftarKiriman :tujuan="sistem" :sistem="status.data.value ?? []" />
  </HalamanModul>
</template>
