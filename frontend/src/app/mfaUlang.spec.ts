import { afterEach, describe, expect, it } from 'vitest'
import { jawabMfaUlang, lupakanMasaMfaUlang, mfaUlangTerbuka, mintaMfaUlang } from './mfaUlang'

afterEach(() => {
  if (mfaUlangTerbuka.value) jawabMfaUlang(false)
  lupakanMasaMfaUlang()
})

describe('mfaUlang', () => {
  it('permintaan bersamaan berbagi satu dialog dan satu jawaban', async () => {
    const a = mintaMfaUlang()
    const b = mintaMfaUlang()
    expect(a).toBe(b)
    expect(mfaUlangTerbuka.value).toBe(true)
    jawabMfaUlang(true)
    await expect(a).resolves.toBe('dialog')
    expect(mfaUlangTerbuka.value).toBe(false)
  })

  it('sesudah dibatalkan, permintaan berikutnya membuka dialog baru', async () => {
    const a = mintaMfaUlang()
    jawabMfaUlang(false)
    await expect(a).resolves.toBe(false)
    const b = mintaMfaUlang()
    expect(b).not.toBe(a)
    expect(mfaUlangTerbuka.value).toBe(true)
  })

  it('selama masa verifikasi (durasi relatif jam klien) penolakan susulan dijawab tanpa dialog', async () => {
    const a = mintaMfaUlang()
    jawabMfaUlang(true, 15 * 60_000)
    await expect(a).resolves.toBe('dialog')
    await expect(mintaMfaUlang()).resolves.toBe('masa')
    expect(mfaUlangTerbuka.value).toBe(false)
  })

  it('paksa dialog (pengulangan lewat masa tetap ditolak) dan keluar menghapus masa', async () => {
    jawabMfaUlang(true, 15 * 60_000)
    void mintaMfaUlang(true)
    expect(mfaUlangTerbuka.value).toBe(true)
    jawabMfaUlang(false)

    jawabMfaUlang(true, 15 * 60_000)
    lupakanMasaMfaUlang()
    void mintaMfaUlang()
    expect(mfaUlangTerbuka.value).toBe(true)
  })
})
