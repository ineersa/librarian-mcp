import { startStimulusApp } from '@symfony/stimulus-bundle';
import LibraryFormController from './controllers/library-form_controller.js';
import LibraryStatusController from './controllers/library-status_controller.js';

const app = startStimulusApp();

app.register('library-form', LibraryFormController);
app.register('library-status', LibraryStatusController);
