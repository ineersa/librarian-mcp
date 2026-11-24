import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['model', 'reasoning', 'reasoningRow'];

    connect() {
        this.updateReasoning(this.reasoningTarget.value);
    }

    changeModel() {
        this.updateReasoning();
    }

    updateReasoning(previousValue) {
        const { reasoningChoices, defaultReasoning } = this.modelTarget.selectedOptions[0]?.dataset ?? {};
        const choices = Object.entries(JSON.parse(reasoningChoices ?? '{}'));
        this.reasoningTarget.replaceChildren(...choices.map(([value, label]) => new Option(label, value)));
        this.reasoningTarget.value = choices.some(([value]) => value === previousValue) ? previousValue : (defaultReasoning ?? '');
        this.reasoningRowTarget.classList.toggle('d-none', choices.length <= 1);
    }
}
