<script setup lang="ts">
import { useQuery } from '@tanstack/vue-query'
import { computed } from 'vue'
import BadgeTtd from '@/design-system/components/BadgeTtd.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { verifikasiTtd } from '../api'
import { KUNCI_TTD } from '../ttd'
import type { JenisObjekTtd, TandaTangan } from '../tipe'

/** Tanda tangan sebuah objek beserta hasil verifikasi server; "Verifikasi ulang" memanggil server lagi. */
const props = defineProps<{ jenis: JenisObjekTtd; objekId: string }>()
const emit = defineEmits<{ dimuat: [tandaTangan: TandaTangan[]] }>()
const kueri = useQuery({
  queryKey: computed(() => KUNCI_TTD.verifikasi(props.jenis, props.objekId)),
  queryFn: async () => {
    const data = await verifikasiTtd(props.jenis, props.objekId)
    emit('dimuat', data)
    return data
  },
})
</script>

<template>
  <BadgeTtd
    :tanda-tangan="kueri.data.value ?? []"
    :memuat="kueri.isFetching.value"
    :error="kueri.isError.value ? pesanError(kueri.error.value) : null"
    @verifikasi="kueri.refetch()"
  />
</template>
