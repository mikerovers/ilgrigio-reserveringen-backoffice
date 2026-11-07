import { Controller } from "@hotwired/stimulus";

// Connects to data-controller="scanner--autosubmit"
// Submits the form that contains the changed field (e.g. the show picker).
export default class extends Controller {
    submit(event) {
        event.target.form?.requestSubmit();
    }
}
