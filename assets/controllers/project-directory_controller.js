import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'input',
        'openButton',
        'dialog',
        'location',
        'parentButton',
        'currentPath',
        'error',
        'entries',
        'truncated',
        'selectButton',
    ];

    static values = {
        browseUrl: String,
    };

    /** @type {AbortController|null} */
    #abortController = null;

    /** @type {string|null} */
    #currentPath = null;

    /** @type {string|null} */
    #parentPath = null;

    /** @type {((event: KeyboardEvent) => void)|null} */
    #onDocumentKeydown = null;

    /** @type {((event: Event) => void)|null} */
    #onEntriesClick = null;

    connect() {
        this.#onEntriesClick = (event) => {
            const target = event.target;
            if (!(target instanceof HTMLElement)) {
                return;
            }

            const button = target.closest('button[data-path]');
            if (!(button instanceof HTMLButtonElement) || !this.entriesTarget.contains(button)) {
                return;
            }

            const path = button.dataset.path;
            if (!path) {
                return;
            }

            this.#load(path);
        };

        this.entriesTarget.addEventListener('click', this.#onEntriesClick);
    }

    disconnect() {
        this.#abortInFlight();
        this.#unbindDocumentKeydown();
        if (this.#onEntriesClick && this.hasEntriesTarget) {
            this.entriesTarget.removeEventListener('click', this.#onEntriesClick);
        }
        this.#onEntriesClick = null;
        this.close();
    }

    open() {
        if (!this.hasDialogTarget) {
            return;
        }

        this.#bindDocumentKeydown();
        this.dialogTarget.showModal();
        this.#load(this.inputTarget.value || '');
        this.locationTarget.focus();
    }

    close() {
        if (this.hasDialogTarget && this.dialogTarget.open) {
            this.dialogTarget.close();
        }

        this.#unbindDocumentKeydown();
    }

    goParent() {
        if (!this.#parentPath) {
            return;
        }

        this.#load(this.#parentPath);
    }

    goToLocation() {
        const path = this.locationTarget.value.trim();
        this.#load(path);
    }

    selectCurrent() {
        if (!this.#currentPath) {
            this.#setError('Select an available directory first.');
            return;
        }

        this.inputTarget.value = this.#currentPath;
        this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }));
        this.inputTarget.dispatchEvent(new Event('change', { bubbles: true }));
        this.close();
    }

    /**
     * @param {string} path
     */
    async #load(path) {
        if (!this.browseUrlValue) {
            this.#setError('Directory browse URL is not configured.');
            return;
        }

        this.#abortInFlight();
        this.#abortController = new AbortController();
        this.#setBusy(true);
        this.#setError('');

        const url = new URL(this.browseUrlValue, window.location.origin);
        if (path) {
            url.searchParams.set('path', path);
        }

        try {
            const response = await fetch(url.toString(), {
                headers: {
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
                signal: this.#abortController.signal,
            });

            const payload = await response.json();
            this.#renderListing(payload, response.ok);
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') {
                return;
            }

            this.#setError('Unable to browse directories. Check that PROJECT_BROWSE_ROOT is mounted.');
        } finally {
            this.#setBusy(false);
        }
    }

    /**
     * @param {{path?: string, parent?: string|null, entries?: Array<{name: string, path: string}>, truncated?: boolean, error?: string|null}} payload
     * @param {boolean} ok
     */
    #renderListing(payload, ok) {
        const currentPath = typeof payload.path === 'string' ? payload.path : '';
        const parentPath = typeof payload.parent === 'string' ? payload.parent : null;
        const entries = Array.isArray(payload.entries) ? payload.entries : [];
        const truncated = Boolean(payload.truncated);
        const error = typeof payload.error === 'string' && payload.error !== ''
            ? payload.error
            : (ok ? '' : 'Unable to browse directories.');

        this.#currentPath = currentPath || null;
        this.#parentPath = parentPath;

        this.currentPathTarget.textContent = currentPath || 'No directory available';
        this.locationTarget.value = currentPath;
        this.parentButtonTarget.disabled = !parentPath;
        this.selectButtonTarget.disabled = !currentPath;
        this.truncatedTarget.hidden = !truncated;
        this.#setError(error);
        this.#renderEntries(entries);
    }

    /**
     * @param {Array<{name?: string, path?: string}>} entries
     */
    #renderEntries(entries) {
        this.entriesTarget.replaceChildren();

        if (0 === entries.length) {
            const empty = document.createElement('p');
            empty.className = 'mb-0 p-3 small text-muted';
            empty.textContent = 'No readable subdirectories.';
            this.entriesTarget.appendChild(empty);
            return;
        }

        for (const entry of entries) {
            if (!entry || typeof entry.name !== 'string' || typeof entry.path !== 'string') {
                continue;
            }

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'list-group-item list-group-item-action bg-dark text-light';
            button.setAttribute('role', 'option');
            button.textContent = entry.name;
            button.dataset.path = entry.path;
            this.entriesTarget.appendChild(button);
        }
    }

    /**
     * @param {string} message
     */
    #setError(message) {
        if (!this.hasErrorTarget) {
            return;
        }

        this.errorTarget.textContent = message;
        this.errorTarget.hidden = '' === message;
    }

    /**
     * @param {boolean} busy
     */
    #setBusy(busy) {
        if (this.hasOpenButtonTarget) {
            this.openButtonTarget.disabled = busy;
        }

        if (this.hasSelectButtonTarget) {
            this.selectButtonTarget.disabled = busy || !this.#currentPath;
        }

        if (this.hasParentButtonTarget) {
            this.parentButtonTarget.disabled = busy || !this.#parentPath;
        }
    }

    #abortInFlight() {
        if (this.#abortController) {
            this.#abortController.abort();
            this.#abortController = null;
        }
    }

    #bindDocumentKeydown() {
        if (this.#onDocumentKeydown) {
            return;
        }

        this.#onDocumentKeydown = (event) => {
            if (event.key === 'Escape') {
                this.close();
            }
        };

        document.addEventListener('keydown', this.#onDocumentKeydown);
    }

    #unbindDocumentKeydown() {
        if (!this.#onDocumentKeydown) {
            return;
        }

        document.removeEventListener('keydown', this.#onDocumentKeydown);
        this.#onDocumentKeydown = null;
    }
}
