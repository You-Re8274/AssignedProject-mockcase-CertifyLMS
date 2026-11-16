/**
 * CSRF + JSON 共通 fetch ラッパー。
 * Sanctum SPA Cookie / Fortify session 両対応。
 */

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

let sanctumCsrfCookieRequest = null;

async function ensureSanctumCsrfCookie() {
    if (!sanctumCsrfCookieRequest) {
        sanctumCsrfCookieRequest = fetch('/sanctum/csrf-cookie', {
            method: 'GET',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'include',
        }).then((response) => {
            if (!response.ok) {
                throw new Error(`Sanctum CSRF cookie request failed (HTTP ${response.status})`);
            }
        }).catch((error) => {
            sanctumCsrfCookieRequest = null;
            throw error;
        });
    }

    await sanctumCsrfCookieRequest;
}

function xsrfCookieToken() {
    const cookie = document.cookie
        .split('; ')
        .find((item) => item.startsWith('XSRF-TOKEN='));

    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
}

async function send(method, url, data = null, options = {}) {
    const {
        sanctum = false,
        headers: optionHeaders = {},
        credentials,
        ...fetchOptions
    } = options;
    const isUnsafeMethod = !['GET', 'HEAD', 'OPTIONS'].includes(method.toUpperCase());

    if (sanctum && isUnsafeMethod) {
        await ensureSanctumCsrfCookie();
    }

    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(sanctum ? {} : { 'X-CSRF-TOKEN': csrfToken() }),
        ...optionHeaders,
    };

    if (sanctum && isUnsafeMethod) {
        const token = xsrfCookieToken();
        if (!token) {
            throw new Error('Sanctum XSRF-TOKEN cookie is missing');
        }
        headers['X-XSRF-TOKEN'] = token;
    }

    let body;
    if (data instanceof FormData) {
        body = data;
    } else if (data != null) {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(data);
    }

    const response = await fetch(url, {
        ...fetchOptions,
        method,
        headers,
        body,
        credentials: credentials ?? (sanctum ? 'include' : 'same-origin'),
    });

    const contentType = response.headers.get('content-type') ?? '';
    const isJson = contentType.includes('application/json');
    const payload = isJson ? await response.json() : await response.text();

    if (!response.ok) {
        const error = new Error(`HTTP ${response.status}`);
        error.status = response.status;
        error.payload = payload;
        throw error;
    }

    return payload;
}

export const getJson = (url, options) => send('GET', url, null, options);
export const postJson = (url, data, options) => send('POST', url, data, options);
export const putJson = (url, data, options) => send('PUT', url, data, options);
export const patchJson = (url, data, options) => send('PATCH', url, data, options);
export const deleteJson = (url, options) => send('DELETE', url, null, options);
