import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { useSesiStore } from '@/app/sesi'
import { uid } from '@/modules/akses/tiruan/dataPengaturan'
import { pasangTiruan } from '@/modules/akses/tiruan/pasangTiruan'
import { penggunaTiruan } from '@/modules/akses/tiruan/pengguna'
import { buatServerGabungan } from '@/modules/akses/tiruan/serverGabungan'
import { ID_SUPERADMIN } from '@/modules/akses/tiruan/serverTiruan'
import { klien } from '@/shared/api/klien'
import { aturIzin } from '@/shared/composables/usePermission'
import { buatPasien, cariPasien, cekDuplikatPasien, daftarGabung, usulkanGabung, verifikasiGabung } from './api'

/** Gabung RM ganda (tiket 2.9, kontrak v0.21.0) terhadap server tiruan: usul → verifikasi, pengusul ≠ pemverifikasi. */
const adapterAsli = klien.defaults.adapter
let gabungan: ReturnType<typeof buatServerGabungan>

beforeEach(() => {
  setActivePinia(createPinia())
  gabungan = buatServerGabungan()
  pasangTiruan(klien, gabungan)
  useSesiStore().pengguna = penggunaTiruan({ id: ID_SUPERADMIN, nama_pengguna: 'contoh.pendaftaran', mfa_aktif: true })
  aturIzin(['pendaftaran.pasien.lihat', 'pendaftaran.pasien.input', 'pendaftaran.master_pasien.lihat', 'pendaftaran.master_pasien.usul', 'pendaftaran.master_pasien.verifikasi'])
})
afterEach(() => {
  klien.defaults.adapter = adapterAsli
})

/** Petugas pendaftaran/Rekam Medis contoh (peran 102, semua aksi pendaftaran): uid 2 mengusulkan, uid 203 memverifikasi. */
const PENGUSUL = uid(2)
const PEMVERIFIKASI = uid(203)

async function duaRm() {
  gabungan.akses.sebagai(PENGUSUL)
  const a = await buatPasien({ nama: 'Contoh Ganda Satu', tanggal_lahir: '1991-04-04', jenis_kelamin: 'laki_laki' })
  const b = await buatPasien({ nama: 'Contoh Ganda Satu', tanggal_lahir: '1991-04-04', jenis_kelamin: 'laki_laki' })
  return { a: a.data, b: b.data }
}

describe('cek duplikat dan gabung RM', () => {
  it('cek duplikat hanya mengembalikan pasien berlaku dengan tanggal lahir sama dan nama mirip', async () => {
    const { a } = await duaRm()
    const ada = await cekDuplikatPasien('Contoh Ganda', '1991-04-04')
    expect(ada.map((p) => p.id)).toContain(a.id)
    expect(await cekDuplikatPasien('Contoh Ganda', '1992-01-01')).toHaveLength(0)
  })

  it('usul lalu verifikasi oleh akun lain: RM lama ditandai digabung dan bisa ditelusuri dari kedua arah', async () => {
    const { a, b } = await duaRm()
    const u = await usulkanGabung(a.id, b.id, 'Terdaftar dua kali')
    expect(u.tahap).toBe('diusulkan')
    expect(u.dapat_verifikasi).toBe(false)
    await expect(verifikasiGabung(u.id, 'setuju', null)).rejects.toMatchObject({ status: 403, kode: 'bukan_pemverifikasi' })

    gabungan.akses.sebagai(PEMVERIFIKASI)
    const hasil = await verifikasiGabung(u.id, 'setuju', 'Orang yang sama')
    expect(hasil.tahap).toBe('digabung')
    const lama = (await cariPasien(a.no_rm)).data[0]!
    expect(lama.digabung_ke?.no_rm).toBe(b.no_rm)
    const baru = (await cariPasien(b.no_rm)).data[0]!
    expect(baru.digabung_dari.map((d) => d.no_rm)).toEqual([a.no_rm])
    expect(await cekDuplikatPasien('Contoh Ganda Satu', '1991-04-04')).toHaveLength(1)
    await expect(verifikasiGabung(u.id, 'setuju', null)).rejects.toMatchObject({ status: 409, kode: 'tahap_tidak_sesuai' })
  })

  it('tolak wajib catatan; usulan terbuka dan RM yang sudah digabung menolak usulan baru', async () => {
    const { a, b } = await duaRm()
    const u = await usulkanGabung(a.id, b.id, 'Dugaan ganda')
    await expect(usulkanGabung(b.id, a.id, 'Dugaan ganda')).rejects.toMatchObject({ status: 409, kode: 'usulan_gabung_terbuka' })
    gabungan.akses.sebagai(PEMVERIFIKASI)
    await expect(verifikasiGabung(u.id, 'tolak', null)).rejects.toMatchObject({ status: 422 })
    const ditolak = await verifikasiGabung(u.id, 'tolak', 'Orang berbeda')
    expect(ditolak.tahap).toBe('ditolak')
    expect((await daftarGabung('ditolak')).data).toHaveLength(1)
    expect((await daftarGabung('diusulkan')).data).toHaveLength(0)
  })

  it('akun tanpa izin pendaftaran (perawat) → 403 untuk usul', async () => {
    const { a, b } = await duaRm()
    gabungan.akses.sebagai(uid(3))
    await expect(usulkanGabung(a.id, b.id, 'Dugaan ganda')).rejects.toMatchObject({ status: 403 })
  })
})
