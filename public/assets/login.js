"use strict";

const pw = document.getElementById("pw");
const reveal = document.getElementById("reveal");

if (pw && reveal) {
    reveal.addEventListener("click", () => {
        const show = pw.type === "password";
        pw.type = show ? "text" : "password";
        reveal.classList.toggle("on", show);
        reveal.setAttribute("aria-label", show ? "Masquer le mot de passe" : "Afficher le mot de passe");
        pw.focus();
    });
}
