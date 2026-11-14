import { Controller } from '@hotwired/stimulus';

// Opens the printable ticket sheet of a just saved sale. The sheet opens its own
// print dialog. A popup blocker may refuse a tab that is not opened by a click,
// so fall back to opening the sheet in this tab (the "Tickets printen" link stays
// on the page as well).
export default class extends Controller {
    open(event) {
        const url = event.detail && event.detail.url;
        if (typeof url !== 'string' || !url.startsWith('/') || url.startsWith('//')) {
            return;
        }
        if (!window.open(url, '_blank')) {
            window.location.assign(url);
        }
    }
}
