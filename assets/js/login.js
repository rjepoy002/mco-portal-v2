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
   GOOGLE SIGN-IN
   Placeholder for now
========================================= */

const googleLogin =
    document.getElementById("googleLogin");


if (googleLogin) {

    googleLogin.addEventListener(
        "click",
        function () {

            alert(
                "Google Sign-In will be connected later."
            );

        }
    );

}