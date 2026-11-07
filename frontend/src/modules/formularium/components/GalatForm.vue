<script setup lang="ts">
import { computed } from 'vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { ApiError } from '@/shared/api/klien'
import { harusLewatUsulan } from '../formularium'

/**
 * Galat formulir di dialog tulis. 412 = data diubah orang lain (tawarkan muat ulang); 409 `harus_lewat_usulan` = arahkan ke
 * Usulan perubahan. Galat kolom (422) ditangani dialog sendiri.
 */
const props = defineProps<{ galat: unknown }>()
const emit = defineEmits<{ muatUlang: [] }>()
const usulan = computed(() => harusLewatUsulan(props.galat))
const versiBerubah = computed(() => props.galat instanceof ApiError && props.galat.status === 412)
const pesan = computed(() => (versiBerubah.value ? 'Data ini diubah orang lain sejak dibuka. Muat ulang, lalu ulangi.' : typeof props.galat === 'string' ? props.galat : pesanError(props.galat)))
</script>

<template>
  <div v-if="galat">
    <p class="catatan catatan-bahaya" role="alert">
      <Ikon nama="peringatan" :ukuran="16" />
      <span>{{ pesan }}<template v-if="usulan"> Sesudah siap melayani, ajukan perubahan formularium lewat <RouterLink to="/master-data/usulan">Usulan perubahan</RouterLink>.</template></span>
    </p>
    <button v-if="versiBerubah" type="button" class="tombol" @click="emit('muatUlang')">Muat ulang</button>
  </div>
</template>
