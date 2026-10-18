import '../bootstrap.js';
import './admin.css';

/*
 * Il Grigio Backoffice - Admin JavaScript Entry Point
 */

// Toolbar presets live in admin.css; block file attachments in every editor.
// The storefront additionally sanitizes the HTML server-side (see
// html_sanitizer in framework.yaml).
document.addEventListener('trix-file-accept', (event) => event.preventDefault());
