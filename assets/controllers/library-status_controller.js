/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        hubUrl: String,
    };

    /** @type {EventSource|null} */
    #eventSource = null;

    connect() {
        const hubUrl = this.hubUrlValue;
        if (!hubUrl) {
            console.warn('[library-status] Missing hubUrl value; Mercure listener not started.');
            return;
        }

        const url = new URL(hubUrl, window.location.origin);
        if (!url.searchParams.has('topic')) {
            url.searchParams.append('topic', 'https://librarian-mcp.local/topics/libraries');
        }

        this.#eventSource = new EventSource(url.toString());

        this.#eventSource.addEventListener('open', () => {
            console.debug('[library-status] Mercure stream opened.');
        });

        this.#eventSource.addEventListener('error', (event) => {
            this.#handleStreamError(event);
        });

        this.#eventSource.addEventListener('message', (event) => {
            this.#handleStreamMessage(event);
        });
    }

    disconnect() {
        if (this.#eventSource) {
            this.#eventSource.close();
            this.#eventSource = null;
        }
    }

    /**
     * @param {Event} event
     */
    #handleStreamError(event) {
        if (!this.#eventSource) {
            return;
        }

        const state = this.#eventSource.readyState;

        if (state === EventSource.CONNECTING) {
            // Normal transient reconnect signal from EventSource.
            console.debug('[library-status] Mercure stream reconnecting.');
            return;
        }

        if (state === EventSource.CLOSED) {
            console.warn('[library-status] Mercure stream closed.');
            return;
        }

        console.error('[library-status] Mercure stream error.', event);
    }

    /**
     * @param {MessageEvent<string>} event
     */
    #handleStreamMessage(event) {
        try {
            const data = JSON.parse(event.data);

            if (!data || !('libraryId' in data) || !('status' in data)) {
                return;
            }

            this.#updateRow({
                libraryId: data.libraryId,
                status: data.status,
                lastError: data.lastError,
            });
        } catch (error) {
            console.error('[library-status] Failed to parse Mercure JSON payload:', error, event.data);
        }
    }

    /**
     * @param {{ libraryId: number|string, status: string, lastError?: string }} data
     */
    #updateRow(data) {
        const status = this.#normalizeStatus(data.status);
        if (!status) {
            return;
        }

        const row = this.#findRowForLibraryId(data.libraryId);
        if (!row) {
            return;
        }

        const badge = row.querySelector('[data-status-badge]')
            ?? row.querySelector('td[data-column="status"] .badge')
            ?? row.querySelector('td[data-column="status"] .label')
            ?? row.querySelector('td[data-column="status"] span');

        if (!badge) {
            return;
        }

        const statusCell = row.querySelector('td[data-column="status"]');

        badge.textContent = this.#formatStatusLabel(status);
        this.#applyBadgeClasses(badge, status);

        if (statusCell) {
            statusCell.setAttribute('data-live-status', status);
        }

        row.setAttribute('data-library-status', status);
    }

    /**
     * @param {number|string} libraryId
     * @returns {Element|null}
     */
    #findRowForLibraryId(libraryId) {
        const id = String(libraryId);

        const byLibraryId = this.element.querySelector(`tr[data-library-id="${id}"]`);
        if (byLibraryId) {
            return byLibraryId;
        }

        const byRowId = this.element.querySelector(`tr[data-id="${id}"]`);
        if (byRowId) {
            return byRowId;
        }

        const byDataId = this.element.querySelector(`[data-id="${id}"]`);
        if (byDataId instanceof Element) {
            return byDataId.closest('tr');
        }

        return null;
    }

    /**
     * @param {Element} badge
     * @param {string} status
     */
    #applyBadgeClasses(badge, status) {
        const statusClasses = ['badge-secondary', 'badge-warning', 'badge-info', 'badge-success', 'badge-danger'];

        badge.classList.add('badge');
        badge.classList.remove(...statusClasses);
        badge.classList.add(this.#statusBadgeClass(status));
    }

    /**
     * @param {string} status
     * @returns {string}
     */
    #statusBadgeClass(status) {
        const colors = {
            draft: 'badge-secondary',
            queued: 'badge-warning',
            indexing: 'badge-info',
            ready: 'badge-success',
            failed: 'badge-danger',
        };

        return colors[status] || colors.draft;
    }

    /**
     * @param {string} status
     * @returns {string}
     */
    #normalizeStatus(status) {
        if ('string' !== typeof status) {
            return '';
        }

        return status.trim().toLowerCase();
    }

    /**
     * @param {string} status
     * @returns {string}
     */
    #formatStatusLabel(status) {
        if ('string' !== typeof status || '' === status) {
            return '';
        }

        return `${status.charAt(0).toUpperCase()}${status.slice(1).toLowerCase()}`;
    }
}
