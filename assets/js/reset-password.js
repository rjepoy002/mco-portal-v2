document.addEventListener('DOMContentLoaded', function () {

    const form = document.getElementById('resetPasswordForm');
    const password = document.getElementById('password');
    const confirmPassword = document.getElementById('confirm_password');
    const submitButton = document.getElementById('submitButton');

    /*
     * ============================================
     * PASSWORD VISIBILITY
     * ============================================
     */

    const toggleButtons =
        document.querySelectorAll('.password-toggle');

    toggleButtons.forEach(function (button) {

        button.addEventListener('click', function () {

            const targetId = button.dataset.target;
            const input = document.getElementById(targetId);

            if (!input) {
                return;
            }

            if (input.type === 'password') {

                input.type = 'text';
                button.textContent = 'Hide';
                button.setAttribute(
                    'aria-label',
                    'Hide password'
                );

            } else {

                input.type = 'password';
                button.textContent = 'Show';
                button.setAttribute(
                    'aria-label',
                    'Show password'
                );
            }

        });

    });


    /*
     * ============================================
     * FORM VALIDATION
     * ============================================
     */

    if (!form) {
        return;
    }

    form.addEventListener('submit', function (event) {

        const passwordValue = password.value;
        const confirmValue = confirmPassword.value;

        if (passwordValue.length < 8) {

            event.preventDefault();

            alert('Password must be at least 8 characters.');

            password.focus();

            return;
        }

        if (passwordValue !== confirmValue) {

            event.preventDefault();

            alert('Passwords do not match.');

            confirmPassword.focus();

            return;
        }

        submitButton.disabled = true;
        submitButton.textContent = 'Resetting...';

    });

});

/* =========================================
   ELEMENTS
========================================= */

const resetForm =
    document.getElementById("resetPasswordForm");

const passwordInput =
    document.getElementById("password");

const confirmPasswordInput =
    document.getElementById("confirm_password");

const passwordToggle =
    document.getElementById("passwordToggle");

const confirmPasswordToggle =
    document.getElementById("confirmPasswordToggle");

const submitButton =
    document.getElementById("submitButton");


/* =========================================
   PASSWORD SHOW / HIDE
========================================= */

function setupPasswordToggle(button, input) {

    if (!button || !input) {
        return;
    }

    button.addEventListener("click", function () {

        const isPassword =
            input.type === "password";

        input.type =
            isPassword
                ? "text"
                : "password";

        this.innerHTML =
            isPassword
                ? '<i class="bi bi-eye-slash"></i>'
                : '<i class="bi bi-eye"></i>';

        this.setAttribute(
            "aria-label",
            isPassword
                ? "Hide password"
                : "Show password"
        );

    });

}


setupPasswordToggle(
    passwordToggle,
    passwordInput
);

setupPasswordToggle(
    confirmPasswordToggle,
    confirmPasswordInput
);


/* =========================================
   PASSWORD STRENGTH
========================================= */

function updatePasswordStrength() {

    if (!passwordInput) {
        return;
    }

    const password =
        passwordInput.value;

    const bars =
        document.querySelectorAll(
            ".strength-bars span"
        );

    const strengthText =
        document.getElementById(
            "strengthText"
        );

    const lengthRequirement =
        document.getElementById(
            "lengthRequirement"
        );

    const numberRequirement =
        document.getElementById(
            "numberRequirement"
        );

    const letterRequirement =
        document.getElementById(
            "letterRequirement"
        );


    const hasLength =
        password.length >= 8;

    const hasNumber =
        /\d/.test(password);

    const hasLetter =
        /[A-Za-z]/.test(password);

    const hasSpecial =
        /[^A-Za-z0-9]/.test(password);

    const passwordValid =
        hasLength &&
        hasNumber &&
        hasLetter;


    const passwordWrapper =
        passwordInput.closest(".input-wrapper");


    if (passwordWrapper) {

        passwordWrapper.classList.toggle(
            "input-valid",
            passwordValid
        );

    }


    /* Requirements */

    lengthRequirement.classList.toggle(
        "valid",
        hasLength
    );

    numberRequirement.classList.toggle(
        "valid",
        hasNumber
    );

    letterRequirement.classList.toggle(
        "valid",
        hasLetter
    );


    lengthRequirement.querySelector("i").className =
        hasLength
            ? "bi bi-check-circle-fill"
            : "bi bi-circle";

    numberRequirement.querySelector("i").className =
        hasNumber
            ? "bi bi-check-circle-fill"
            : "bi bi-circle";

    letterRequirement.querySelector("i").className =
        hasLetter
            ? "bi bi-check-circle-fill"
            : "bi bi-circle";


    /* Strength */

    let strength = 0;

    if (hasLength) strength++;
    if (hasNumber) strength++;
    if (hasLetter) strength++;
    if (hasSpecial) strength++;


    bars.forEach(function (bar, index) {

        bar.style.background =
            index < strength
                ? "#168454"
                : "#e5ebe8";

    });


    if (password === "") {

        strengthText.textContent =
            "Enter a password";

    } else if (strength <= 1) {

        strengthText.textContent =
            "Weak";

    } else if (strength === 2) {

        strengthText.textContent =
            "Fair";

    } else if (strength === 3) {

        strengthText.textContent =
            "Good";

    } else {

        strengthText.textContent =
            "Strong";

    }

}


if (passwordInput) {

    passwordInput.addEventListener(
        "input",
        updatePasswordStrength
    );

}


/* =========================================
   CONFIRM PASSWORD
========================================= */

function validateConfirmPassword() {

    if (!passwordInput || !confirmPasswordInput) {
        return;
    }

    const password =
        passwordInput.value;

    const confirmPassword =
        confirmPasswordInput.value;

    const confirmWrapper =
        confirmPasswordInput.closest(
            ".input-wrapper"
        );


    if (confirmPassword === "") {

        confirmPasswordInput.classList.remove(
            "input-error"
        );

        if (confirmWrapper) {

            confirmWrapper.classList.remove(
                "input-valid"
            );

        }

        return;
    }


    if (confirmPassword === password) {

        confirmPasswordInput.classList.remove(
            "input-error"
        );

        document.getElementById(
            "confirmPasswordError"
        ).textContent = "";

        if (confirmWrapper) {

            confirmWrapper.classList.add(
                "input-valid"
            );

        }

    } else {

        confirmPasswordInput.classList.add(
            "input-error"
        );

        if (confirmWrapper) {

            confirmWrapper.classList.remove(
                "input-valid"
            );

        }

    }

}


if (confirmPasswordInput) {

    confirmPasswordInput.addEventListener(
        "input",
        validateConfirmPassword
    );

}


if (passwordInput) {

    passwordInput.addEventListener(
        "input",
        validateConfirmPassword
    );

}


/* =========================================
   FORM VALIDATION
========================================= */

if (resetForm) {

    resetForm.addEventListener(
        "submit",
        function (event) {

            let valid = true;

            const password =
                passwordInput.value;

            const confirmPassword =
                confirmPasswordInput.value;


            document.querySelectorAll(
                ".field-error"
            ).forEach(function (element) {

                element.textContent = "";

            });


            passwordInput.classList.remove(
                "input-error"
            );

            confirmPasswordInput.classList.remove(
                "input-error"
            );


            /* Password */

            if (password.length < 8) {

                document.getElementById(
                    "passwordError"
                ).textContent =
                    "Password must be at least 8 characters.";

                passwordInput.classList.add(
                    "input-error"
                );

                valid = false;

            } else if (!/[A-Za-z]/.test(password)) {

                document.getElementById(
                    "passwordError"
                ).textContent =
                    "Password must contain at least one letter.";

                passwordInput.classList.add(
                    "input-error"
                );

                valid = false;

            } else if (!/[0-9]/.test(password)) {

                document.getElementById(
                    "passwordError"
                ).textContent =
                    "Password must contain at least one number.";

                passwordInput.classList.add(
                    "input-error"
                );

                valid = false;

            }


            /* Confirm password */

            if (confirmPassword !== password) {

                document.getElementById(
                    "confirmPasswordError"
                ).textContent =
                    "Passwords do not match.";

                confirmPasswordInput.classList.add(
                    "input-error"
                );

                valid = false;

            }


            if (!valid) {

                event.preventDefault();

                return;

            }


            /* Prevent double submission */

            if (submitButton) {

                submitButton.disabled = true;

                submitButton.textContent =
                    "Resetting...";

            }

        }
    );

}