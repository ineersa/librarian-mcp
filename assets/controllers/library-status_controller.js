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
            return;
        }

        const url = new URL(hubUrl);
        url.searchParams.append('topic', 'libraries');

        this.#eventSource = new EventSource(url.toString());
        this.#eventSource.addEventListener('message', (event) => {
            try {
                const data = JSON.parse(event.data);
                if (data.libraryId && data.status) {
                    this.#updateRow(data);
                }
            } catch {
                // Ignore malformed JSON
            }
        });
    }

    disconnect() {
        if (this.#eventSource) {
            this.#eventSource.close();
            this.#eventSource = null;
        }
    }

    /**
     * @param {{ libraryId: number, status: string, lastError?: string }} data
     */
    #updateRow(data) {
        const row = this.element.querySelector(`[data-library-id="${data.libraryId}"]`);
        if (!row) {
            return;
        }

        const badge = row.querySelector('[data-status-badge]');
        if (badge) {
            badge.textContent = data.status;
            badge.className = this.#badgeClasses(data.status);
        }
    }

    /**
     * @param {string} status
     * @returns {string}
     */
    #badgeClasses(status) {
        const base = 'inline-block px-2 py-0.5 text-xs font-semibold rounded-full';

        const colors = {
            draft: 'bg-gray-100 text-gray-700',
            queued: 'bg-yellow-100 text-yellow-800',
            indexing: 'bg-blue-100 text-blue-800',
            ready: 'bg-green-100 text-green-800',
            failed: 'bg-red-100 text-red-700',
        };

        return `${base} ${colors[status] || colors.draft}`;
    }
}
