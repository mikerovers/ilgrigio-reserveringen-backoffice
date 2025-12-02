import './bootstrap.js';

/*
 * Il Grigio TicketHub - Main JavaScript Entry Point
 */

// Import minimal CSS styles
import './styles/app.css';

// Import Stimulus controllers
import { app } from './bootstrap.js';
import TicketSelectionController from './controllers/ticket_selection_controller.js';

// Register controllers
app.register('ticket-selection', TicketSelectionController);

console.log('Il Grigio TickefewfwefwefewfetHub loaded successfully!');