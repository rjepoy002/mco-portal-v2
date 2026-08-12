/* =========================================
   SHOW / HIDE PASSWORD
========================================= */

const passwordInput =
    document.getElementById("password");

const passwordToggle =
    document.getElementById("passwordToggle");

if (passwordInput && passwordToggle) {

    passwordToggle.addEventListener(
        "click",
        function () {

            const isPassword =
                passwordInput.type === "password";

            passwordInput.type =
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

        }
    );

}


/* =========================================
   GOOGLE LOGIN
========================================= */

const googleLoginButton =
    document.getElementById("googleLogin");

if (googleLoginButton) {

    googleLoginButton.addEventListener(
        "click",
        function () {

            window.location.href =
                "google-login.php";

        }
    );

}

/* =========================================
   CLEAN RESET SUCCESS URL
========================================= */

const urlParams =
    new URLSearchParams(window.location.search);

if (
    urlParams.get("reset") === "success"
) {

    window.history.replaceState(
        {},
        document.title,
        window.location.pathname
    );

}