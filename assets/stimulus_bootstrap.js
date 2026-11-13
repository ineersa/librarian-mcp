import { startStimulusApp } from '@symfony/stimulus-bundle';
import ProjectDirectoryController from './controllers/project-directory_controller.js';

const app = startStimulusApp();

app.register('project-directory', ProjectDirectoryController);
