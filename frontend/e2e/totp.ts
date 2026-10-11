import { createHmac } from 'node:crypto'

/** TOTP RFC 6238 (SHA-1, 30 detik, 6 digit) seperti aplikasi autentikator, untuk e2e saja. */
export function kodeTotp(rahasiaBase32: string, waktuMs = Date.now()): string {
  const alfabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'
  const bit = rahasiaBase32
    .replace(/[\s=]/g, '')
    .toUpperCase()
    .split('')
    .map((c) => {
      const nilai = alfabet.indexOf(c)
      if (nilai < 0) throw new Error('Rahasia Base32 tidak valid.')
      return nilai.toString(2).padStart(5, '0')
    })
    .join('')
  const kunci = Buffer.from((bit.match(/.{8}/g) ?? []).map((b) => parseInt(b, 2)))

  const langkah = Buffer.alloc(8)
  langkah.writeBigUInt64BE(BigInt(Math.floor(waktuMs / 1000 / 30)))
  const hmac = createHmac('sha1', kunci).update(langkah).digest()
  const offset = hmac[19] & 0x0f
  const angka = (hmac.readUInt32BE(offset) & 0x7fffffff) % 1_000_000
  return String(angka).padStart(6, '0')
}
