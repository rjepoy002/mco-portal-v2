document.addEventListener('DOMContentLoaded', function () {

    const form = document.getElementById('forgotPasswordForm');
    const email = document.getElementById('email');
    const submitButton = document.getElementById('submitButton');

    if (!form || !email || !submitButton) {
        return;
    }

    form.addEventListener('submit', function (event) {

        const emailValue = email.value.trim();

        if (emailValue === '') {
            event.preventDefault();

            email.focus();

            return;
        }

        if (!email.checkValidity()) {
            event.preventDefault();

            email.focus();

            return;
        }

        // Prevent multiple submissions
        submitButton.disabled = true;
        submitButton.textContent = 'Sending...';

    });

});