/**
 * The CSRF token Laravel keeps in the `XSRF-TOKEN` cookie, decoded for use as an
 * `X-XSRF-TOKEN` header. Empty when the cookie is missing, in which case the
 * server answers 419 and the caller treats it like any other failed request.
 */
export function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}
