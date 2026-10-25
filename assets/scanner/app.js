/*
 * Il Grigio ticket scanner entrypoint (served on SCANNER_HOST, scan.ilgrigio.nl).
 *
 * Own Stimulus application: does not import assets/bootstrap.js, so storefront
 * and admin controllers are not loaded on the scanner page.
 */
import { Application } from '@hotwired/stimulus';

import './styles/app.css';

export const scannerApp = Application.start();
