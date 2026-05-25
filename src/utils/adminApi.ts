import { parseApiResponse } from './responseCrypto'

export function getAdminAuthToken(): string {
  return localStorage.getItem('adminAuthToken') ?? '';
}

export async function adminApiRequest<T>(input: string, init: RequestInit = {}): Promise<T> {
  const token = getAdminAuthToken();
  const headers = new Headers(init.headers);

  if (init.body && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json');
  }

  if (token) {
    headers.set('Authorization', `Bearer ${token}`);
  }

  const response = await fetch(input, {
    ...init,
    headers,
  });

  const payload = await parseApiResponse<unknown>(response);

  if (!response.ok) {
    const message =
      typeof payload === 'object' &&
      payload !== null &&
      'message' in payload &&
      typeof (payload as { message?: unknown }).message === 'string'
        ? (payload as { message: string }).message
        : `Request failed with status ${response.status}`;

    throw new Error(message);
  }

  return payload as T;
}
