/* =========================================
   ELEMENTS
========================================= */

const registerForm =
    document.getElementById("registerForm");

const nameInput =
    document.getElementById("name");

const emailInput =
    document.getElementById("email");

const passwordInput =
    document.getElementById("password");

const confirmPasswordInput =
    document.getElementById("confirm_password");

const termsInput =
    document.getElementById("terms");

const passwordToggle =
    document.getElementById("passwordToggle");

const confirmPasswordToggle =
    document.getElementById("confirmPasswordToggle");

const registrationSection =
    document.getElementById("registrationSection");

const otpSection =
    document.getElementById("otpSection");

const otpEmail =
    document.getElementById("otpEmail");

const otpForm =
    document.getElementById("otpForm");

const otpInputs =
    document.querySelectorAll(".otp-input");

const resendOtp =
    document.getElementById("resendOtp");

const resendTimer =
    document.getElementById("resendTimer");

const backToRegistration =
    document.getElementById("backToRegistration");


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
   EMAIL VALIDATION
========================================= */

function isValidEmail(email) {

    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(
        email
    );

}


/* =========================================
   PASSWORD STRENGTH
========================================= */

function updatePasswordStrength() {

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

    passwordWrapper.classList.toggle(
        "input-valid",
        passwordValid
    );

    if (passwordValid) {

        passwordInput.classList.remove(
            "input-error"
        );

        document.getElementById(
            "passwordError"
        ).textContent = "";

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


passwordInput.addEventListener(
    "input",
    updatePasswordStrength
);

/* =========================================
   CONFIRM PASSWORD
========================================= */

function validateConfirmPassword() {

    const password =
        passwordInput.value;

    const confirmPassword =
        confirmPasswordInput.value;

    const confirmWrapper =
        confirmPasswordInput.closest(".input-wrapper");


    /* Empty */

    if (confirmPassword === "") {

        confirmPasswordInput.classList.remove(
            "input-error"
        );

        confirmWrapper.classList.remove(
            "input-valid"
        );

        return;
    }


    /* Passwords match */

    if (confirmPassword === password) {

        confirmPasswordInput.classList.remove(
            "input-error"
        );

        document.getElementById(
            "confirmPasswordError"
        ).textContent = "";

        confirmWrapper.classList.add(
            "input-valid"
        );

    } else {

        confirmPasswordInput.classList.add(
            "input-error"
        );

        confirmWrapper.classList.remove(
            "input-valid"
        );

    }

}


/* Validate when Confirm Password changes */

confirmPasswordInput.addEventListener(
    "input",
    validateConfirmPassword
);


/* Revalidate when Password changes */

passwordInput.addEventListener(
    "input",
    validateConfirmPassword
);

/* =========================================
   LIVE ERROR CLEARING
========================================= */

nameInput.addEventListener(
    "input",
    function () {

        if (this.value.trim() !== "") {

            this.classList.remove(
                "input-error"
            );

            document.getElementById(
                "nameError"
            ).textContent = "";

        }

    }
);


emailInput.addEventListener(
    "input",
    function () {

        const email =
            this.value.trim();

        if (
            email !== "" &&
            isValidEmail(email)
        ) {

            this.classList.remove(
                "input-error"
            );

            document.getElementById(
                "emailError"
            ).textContent = "";

        }

    }
);


passwordInput.addEventListener(
    "input",
    function () {

        const password =
            this.value;

        const hasLength =
            password.length >= 8;

        const hasNumber =
            /\d/.test(password);

        const hasLetter =
            /[A-Za-z]/.test(password);


        if (
            hasLength &&
            hasNumber &&
            hasLetter
        ) {

            this.classList.remove(
                "input-error"
            );

            document.getElementById(
                "passwordError"
            ).textContent = "";

        }

    }
);


confirmPasswordInput.addEventListener(
    "input",
    function () {

        if (
            this.value !== "" &&
            this.value === passwordInput.value
        ) {

            this.classList.remove(
                "input-error"
            );

            document.getElementById(
                "confirmPasswordError"
            ).textContent = "";

        }

    }
);


termsInput.addEventListener(
    "change",
    function () {

        if (this.checked) {

            document.getElementById(
                "termsError"
            ).textContent = "";

        }

    }
);


/* =========================================
   FORM VALIDATION
========================================= */

registerForm.addEventListener(
    "submit",
    function (event) {

        let valid = true;


        /* Clear previous errors */

        document.querySelectorAll(
            ".field-error"
        ).forEach(function (element) {

            element.textContent = "";

        });


        document.querySelectorAll(
            ".form-control"
        ).forEach(function (element) {

            element.classList.remove(
                "input-error"
            );

        });


        /* Name */

        if (
            nameInput.value.trim() === ""
        ) {

            document.getElementById(
                "nameError"
            ).textContent =
                "Please enter your full name.";

            nameInput.classList.add(
                "input-error"
            );

            valid = false;

        }


        /* Email */

        const email =
            emailInput.value.trim();


        if (email === "") {

            document.getElementById(
                "emailError"
            ).textContent =
                "Please enter your email address.";

            emailInput.classList.add(
                "input-error"
            );

            valid = false;

        } else if (!isValidEmail(email)) {

            document.getElementById(
                "emailError"
            ).textContent =
                "Please enter a valid email address.";

            emailInput.classList.add(
                "input-error"
            );

            valid = false;

        }


        /* Password */

        const password =
            passwordInput.value;


        if (password.length < 8) {

            document.getElementById(
                "passwordError"
            ).textContent =
                "Password must be at least 8 characters.";

            passwordInput.classList.add(
                "input-error"
            );

            valid = false;

        }


        /* Confirm password */

        if (
            confirmPasswordInput.value !==
            password
        ) {

            document.getElementById(
                "confirmPasswordError"
            ).textContent =
                "Passwords do not match.";

            confirmPasswordInput.classList.add(
                "input-error"
            );

            valid = false;

        }


        /* Terms */

        if (!termsInput.checked) {

            document.getElementById(
                "termsError"
            ).textContent =
                "Please agree to the Terms of Use and Privacy Policy.";

            valid = false;

        }


        if (!valid) {

            event.preventDefault();

            return;

        }


        /*
         * For now, allow PHP form submission.
         *
         * Once the database and email service
         * are connected, PHP will generate the
         * OTP and send it to the email address.
         */

    }
);


/* =========================================
   OTP INPUTS
========================================= */

otpInputs.forEach(
    function (input, index) {

        input.addEventListener(
            "input",
            function () {

                this.value =
                    this.value.replace(
                        /\D/g,
                        ""
                    );


                if (
                    this.value &&
                    index <
                    otpInputs.length - 1
                ) {

                    otpInputs[
                        index + 1
                    ].focus();

                }

            }
        );


        input.addEventListener(
            "keydown",
            function (event) {

                if (
                    event.key === "Backspace" &&
                    !this.value &&
                    index > 0
                ) {

                    otpInputs[
                        index - 1
                    ].focus();

                }

            }
        );

    }
);


/* =========================================
   OTP VERIFICATION
========================================= */

otpForm.addEventListener(
    "submit",
    function (event) {

        let code = "";

        otpInputs.forEach(
            function (input) {

                code += input.value;

            }
        );


        const otpError =
            document.getElementById(
                "otpError"
            );


        if (code.length !== 6) {

            event.preventDefault();

            otpError.textContent =
                "Please enter the 6-digit verification code.";

            return;

        }


        /*
         * Put the complete OTP into the
         * hidden field before submitting
         * the form to PHP.
         */

        document.getElementById(
            "otpCode"
        ).value = code;

    }
);

/* =========================================
   SHOW OTP SECTION
========================================= */

function showOtpSection() {

    registrationSection.hidden = true;

    otpSection.hidden = false;

    otpEmail.textContent =
        emailInput.value.trim();

    otpInputs[0].focus();

}


/* =========================================
   BACK TO REGISTRATION
========================================= */

backToRegistration.addEventListener(
    "click",
    function () {

        otpSection.hidden = true;

        registrationSection.hidden = false;

        emailInput.focus();

    }
);



/* =========================================
   RESEND OTP
========================================= */

let resendInterval = null;


function startResendTimer(seconds = 60) {

    if (resendInterval !== null) {

        clearInterval(resendInterval);

    }


    if (seconds <= 0) {

        resendOtp.disabled = false;

        resendTimer.textContent = "";

        return;
    }


    resendOtp.disabled = true;

    resendTimer.textContent =
        `You can request another code in ${seconds}s`;


    resendInterval = setInterval(
        function () {

            seconds--;

            resendTimer.textContent =
                `You can request another code in ${seconds}s`;


            if (seconds <= 0) {

                clearInterval(resendInterval);

                resendInterval = null;

                resendOtp.disabled = false;

                resendTimer.textContent = "";

            }

        },
        1000
    );

}


resendOtp.addEventListener(
    "click",
    async function () {

        if (resendOtp.disabled) {
            return;
        }


        resendOtp.disabled = true;

        resendOtp.textContent =
            "Sending...";


        try {

            const response = await fetch(
                "register.php",
                {
                    method: "POST",

                    headers: {
                        "Content-Type":
                            "application/x-www-form-urlencoded"
                    },

                    body:
                        "action=resend_otp"
                }
            );


            const result =
                await response.json();


            if (result.success) {

                resendOtp.textContent =
                    "Code Sent";

                startResendTimer(
                    result.remaining || 60
                );


                setTimeout(
                    function () {

                        resendOtp.textContent =
                            "Resend Code";

                    },
                    2000
                );


            } else {

                resendOtp.disabled = false;

                resendOtp.textContent =
                    "Resend Code";


                const otpError =
                    document.getElementById(
                        "otpError"
                    );

                otpError.textContent =
                    result.message;


                if (result.remaining) {

                    startResendTimer(
                        result.remaining
                    );

                }

            }


        } catch (error) {

            console.error(
                "Resend OTP error:",
                error
            );


            resendOtp.disabled = false;

            resendOtp.textContent =
                "Resend Code";


            const otpError =
                document.getElementById(
                    "otpError"
                );

            otpError.textContent =
                "Unable to send a new verification code. Please try again.";

        }

    }
);