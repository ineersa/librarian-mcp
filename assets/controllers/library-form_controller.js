import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    /** @type {HTMLFormElement|null} */
    #form = null;

    /** @type {((event: Event) => void)|null} */
    #onFormInput = null;

    /** @type {string|null} */
    #lastGeneratedName = null;

    /** @type {string|null} */
    #lastGeneratedSlug = null;

    connect() {
        this.#form = this.element.form;
        if (!this.#form) {
            return;
        }

        this.#onFormInput = (event) => {
            const target = event.target;
            if (!(target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement)) {
                return;
            }

            if (target === this.element || this.#isBranchField(target)) {
                this.#applyDefaults();
            }
        };

        this.#form.addEventListener('input', this.#onFormInput);
        this.#form.addEventListener('change', this.#onFormInput);

        this.#applyDefaults();
    }

    disconnect() {
        if (this.#form && this.#onFormInput) {
            this.#form.removeEventListener('input', this.#onFormInput);
            this.#form.removeEventListener('change', this.#onFormInput);
        }

        this.#form = null;
        this.#onFormInput = null;
    }

    #applyDefaults() {
        const gitUrl = this.element.value?.trim() ?? '';
        if (!gitUrl) {
            return;
        }

        const ownerRepo = this.#parseOwnerRepo(gitUrl);
        if (!ownerRepo) {
            return;
        }

        const branchField = this.#findField('branch');
        const branch = branchField?.value?.trim() || 'main';

        const generatedName = this.#buildLibraryIdentifier(ownerRepo, branch);
        const generatedSlug = generatedName;

        const nameField = this.#findField('name');
        if (nameField && this.#shouldApplyGeneratedValue(nameField.value, this.#lastGeneratedName)) {
            nameField.value = generatedName;
        }

        const slugField = this.#findField('slug');
        if (slugField && this.#shouldApplyGeneratedValue(slugField.value, this.#lastGeneratedSlug)) {
            slugField.value = generatedSlug;
        }

        this.#lastGeneratedName = generatedName;
        this.#lastGeneratedSlug = generatedSlug;
    }

    /**
     * @param {string} currentValue
     * @param {string|null} lastGeneratedValue
     */
    #shouldApplyGeneratedValue(currentValue, lastGeneratedValue) {
        const value = currentValue.trim();

        return '' === value || (null !== lastGeneratedValue && value === lastGeneratedValue);
    }

    /**
     * @param {Element} element
     */
    #isBranchField(element) {
        if (!(element instanceof HTMLInputElement)) {
            return false;
        }

        return element.name.endsWith('[branch]');
    }

    /**
     * @param {string} fieldName
     * @returns {HTMLInputElement|null}
     */
    #findField(fieldName) {
        if (!this.#form) {
            return null;
        }

        const element = this.#form.querySelector(`input[name$="[${fieldName}]"]`);

        return element instanceof HTMLInputElement ? element : null;
    }

    /**
     * @param {string} gitUrl
     * @returns {string|null}
     */
    #parseOwnerRepo(gitUrl) {
        const normalizedUrl = gitUrl.trim().replace(/\/$/, '').replace(/\.git$/, '');
        const matches = normalizedUrl.match(/^https:\/\/github\.com\/([a-zA-Z0-9_.-]+\/[a-zA-Z0-9_.-]+)$/);

        return matches ? matches[1].toLowerCase() : null;
    }

    /**
     * @param {string} ownerRepo
     * @param {string} branch
     * @returns {string}
     */
    #buildLibraryIdentifier(ownerRepo, branch) {
        const normalizedBranch = branch.trim();

        return normalizedBranch.toLowerCase() === 'main'
            ? ownerRepo
            : `${ownerRepo}@${normalizedBranch}`;
    }
}
