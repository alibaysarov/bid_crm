import createClient from 'openapi-fetch';

import type { paths } from '../generated/api';

function getCookie(name: string): string | undefined {
    if (typeof document === 'undefined') {
        return undefined;
    }

    const cookie = document.cookie
        .split('; ')
        .find((value) => value.startsWith(`${name}=`))
        ?.slice(name.length + 1);

    return cookie === undefined ? undefined : decodeURIComponent(cookie);
}

export const apiClient = createClient<paths>({
    baseUrl: '/api',
    credentials: 'include',
    headers: {
        Accept: 'application/json',
    },
});

export async function ensureCsrfCookie(): Promise<void> {
    const { response } = await apiClient.GET('/auth/csrf-cookie');

    if (response.status !== 204) {
        throw new Error('Unable to initialize the secure session.');
    }
}

apiClient.use({
    onRequest({ request }) {
        const csrfToken = getCookie('XSRF-TOKEN');

        if (csrfToken !== undefined) {
            request.headers.set('X-XSRF-TOKEN', csrfToken);
        }

        return request;
    },
});
