const DEFAULT_RESPONSE_ENCRYPTION_KEY_HEX = '4fa8b79e1cc2457f90d4a81e5327b6c98d13ef4076ab2c5119d8e4f3a6bc720d'

interface EncryptedApiEnvelope {
  a: string
}

const textDecoder = new TextDecoder()
const textEncoder = new TextEncoder()
let responseEncryptionKeyPromise: Promise<CryptoKey> | null = null

function isEncryptedApiEnvelope(value: unknown): value is EncryptedApiEnvelope {
  return (
    typeof value === 'object' &&
    value !== null &&
    'a' in value &&
    typeof (value as { a?: unknown }).a === 'string'
  )
}

function hexToBytes(hex: string): Uint8Array {
  if (hex.length % 2 !== 0) {
    throw new Error('Response encryption key must contain an even number of characters.')
  }

  const bytes = new Uint8Array(hex.length / 2)
  for (let index = 0; index < hex.length; index += 2) {
    bytes[index / 2] = parseInt(hex.slice(index, index + 2), 16)
  }

  return bytes
}

function base64ToBytes(base64: string): Uint8Array {
  const binary = window.atob(base64)
  const bytes = new Uint8Array(binary.length)

  for (let index = 0; index < binary.length; index += 1) {
    bytes[index] = binary.charCodeAt(index)
  }

  return bytes
}

function toArrayBuffer(bytes: Uint8Array): ArrayBuffer {
  const buffer = new ArrayBuffer(bytes.byteLength)
  new Uint8Array(buffer).set(bytes)
  return buffer
}

async function getResponseEncryptionKey(): Promise<CryptoKey> {
  if (!responseEncryptionKeyPromise) {
    const keyHex = import.meta.env.VITE_RESPONSE_ENCRYPTION_KEY_HEX ?? DEFAULT_RESPONSE_ENCRYPTION_KEY_HEX
    responseEncryptionKeyPromise = window.crypto.subtle.importKey(
      'raw',
      toArrayBuffer(hexToBytes(keyHex)),
      { name: 'AES-GCM' },
      false,
      ['encrypt', 'decrypt'],
    )
  }

  return responseEncryptionKeyPromise
}

export async function encryptApiPayload(payload: unknown): Promise<string> {
  const plaintext = typeof payload === 'string' ? payload : JSON.stringify(payload)
  const iv = window.crypto.getRandomValues(new Uint8Array(12))
  const encryptedBuffer = await window.crypto.subtle.encrypt(
    { name: 'AES-GCM', iv },
    await getResponseEncryptionKey(),
    textEncoder.encode(plaintext),
  )
  const encryptedBytes = new Uint8Array(encryptedBuffer)
  const combinedBytes = new Uint8Array(iv.byteLength + encryptedBytes.byteLength)
  combinedBytes.set(iv, 0)
  combinedBytes.set(encryptedBytes, iv.byteLength)

  let binary = ''
  for (let index = 0; index < combinedBytes.length; index += 1) {
    binary += String.fromCharCode(combinedBytes[index])
  }

  return window.btoa(binary)
}

function isBackendTransportUrl(url: URL): boolean {
  return url.origin === window.location.origin && (url.pathname.startsWith('/api') || url.pathname.startsWith('/backend'))
}

function shouldEncryptRequestBody(request: Request): boolean {
  const method = request.method.toUpperCase()
  if (method === 'GET' || method === 'HEAD' || method === 'OPTIONS') {
    return false
  }

  const contentType = request.headers.get('Content-Type') ?? ''
  if (!contentType.toLowerCase().includes('application/json')) {
    return false
  }

  return isBackendTransportUrl(new URL(request.url, window.location.origin))
}

export function installEncryptedFetchTransport(): void {
  const globalWithFlag = window as typeof window & { __encryptedFetchTransportInstalled?: boolean }
  if (globalWithFlag.__encryptedFetchTransportInstalled) {
    return
  }

  globalWithFlag.__encryptedFetchTransportInstalled = true
  const originalFetch = window.fetch.bind(window)

  window.fetch = async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const request = new Request(input, init)
    if (!shouldEncryptRequestBody(request)) {
      return originalFetch(request)
    }

    const rawBody = await request.clone().text()
    if (!rawBody.trim()) {
      return originalFetch(request)
    }

    const encryptedBody = JSON.stringify({ a: await encryptApiPayload(rawBody) })
    const headers = new Headers(request.headers)
    headers.set('Content-Type', 'application/json')

    return originalFetch(new Request(request, {
      headers,
      body: encryptedBody,
    }))
  }
}

async function decryptEncryptedPayload(encryptedPayload: string): Promise<string> {
  const encryptedBytes = base64ToBytes(encryptedPayload)
  const minimumLength = 12 + 16

  if (encryptedBytes.length <= minimumLength) {
    throw new Error('Encrypted response payload is incomplete.')
  }

  const iv = encryptedBytes.slice(0, 12)
  const ciphertextWithTag = encryptedBytes.slice(12)
  const decryptedBuffer = await window.crypto.subtle.decrypt(
    { name: 'AES-GCM', iv },
    await getResponseEncryptionKey(),
    toArrayBuffer(ciphertextWithTag),
  )

  return textDecoder.decode(decryptedBuffer)
}

export async function parseApiRawText<T>(rawText: string): Promise<T> {
  const trimmedText = rawText.trim()
  if (!trimmedText) {
    return {} as T
  }

  let parsed: unknown
  try {
    parsed = JSON.parse(trimmedText)
  } catch {
    if (trimmedText.includes('Failed to open stream: No such file or directory')) {
      throw new Error('PHP could not find the backend router. Start the PHP server from the `bookstore-app` folder with `php -S 127.0.0.1:8001 router.php`.')
    }

    const loweredText = trimmedText.toLowerCase()
    if (loweredText.startsWith('<!doctype') || loweredText.startsWith('<html')) {
      throw new Error('Backend returned HTML instead of JSON. Check if the PHP server is running and the Vite proxy is pointing to the correct backend URL.')
    }

    throw new Error(`Unexpected server response: ${trimmedText.slice(0, 160)}`)
  }

  if (!isEncryptedApiEnvelope(parsed)) {
    return parsed as T
  }

  try {
    const decryptedJson = await decryptEncryptedPayload(parsed.a)
    return JSON.parse(decryptedJson) as T
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unknown decryption error'
    throw new Error(`Failed to decrypt API response: ${message}`)
  }
}

export async function parseApiResponse<T>(response: Response): Promise<T> {
  return parseApiRawText<T>(await response.text())
}
