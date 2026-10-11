import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { beforeEach, describe, expect, it } from 'vitest'
import PermissionGate from '@/shared/components/PermissionGate.vue'
import { aturIzin, boleh, vCan } from './usePermission'

beforeEach(() => aturIzin([]))

describe('izin aksi (v-can, PermissionGate)', () => {
  it('tolak kecuali diberikan', () => {
    expect(boleh('data_induk.akun.lihat')).toBe(false)
    aturIzin(['data_induk.akun.lihat'])
    expect(boleh('data_induk.akun.lihat')).toBe(true)
    expect(boleh('data_induk.akun.ubah')).toBe(false)
  })

  it('v-can menyembunyikan elemen dan ikut berubah saat izin berubah', async () => {
    const w = mount({ template: `<button v-can="'data_induk.akun.ubah'">Ubah</button>`, directives: { can: vCan } })
    expect(w.get('button').attributes('hidden')).toBeDefined()

    aturIzin(['data_induk.akun.ubah'])
    await nextTick()
    expect(w.get('button').attributes('hidden')).toBeUndefined()

    aturIzin([])
    await nextTick()
    expect(w.get('button').attributes('hidden')).toBeDefined()
  })

  it('PermissionGate tidak merender isi tanpa izin; slot tolak opsional', async () => {
    const w = mount({
      components: { PermissionGate },
      template: `<PermissionGate izin="data_induk.peran.ubah"><button>Simpan</button><template #tolak><p>Hanya lihat</p></template></PermissionGate>`,
    })
    expect(w.find('button').exists()).toBe(false)
    expect(w.text()).toBe('Hanya lihat')

    aturIzin(['data_induk.peran.ubah'])
    await nextTick()
    expect(w.find('button').exists()).toBe(true)
  })
})
