const dateTime = new Intl.DateTimeFormat('id-ID', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'Asia/Jakarta',
});

/** "21 Nov 2026, 10.57 WIB" — every time in the UI is shown in WIB. */
export function formatDateTime(iso: string | null | undefined): string {
    if (!iso) {
        return '—';
    }

    return `${dateTime.format(new Date(iso))} WIB`;
}
