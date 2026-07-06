document.querySelectorAll("[data-copy-target]").forEach(function (button) {
    button.addEventListener("click", async function () {
        const field = document.querySelector(button.getAttribute("data-copy-target"));
        if (!field) {
            return;
        }

        try {
            await navigator.clipboard.writeText(field.value);
            button.textContent = "Copiato";
            window.setTimeout(function () {
                button.textContent = "Copia";
            }, 1500);
        } catch (error) {
            field.focus();
            field.select();
        }
    });
});

const inviteReparto = document.getElementById("inviteReparto");
const inviteBoxInfoField = document.getElementById("inviteBoxInfoField");
const inviteBoxInfo = document.getElementById("inviteBoxInfo");
if (inviteReparto && inviteBoxInfoField && inviteBoxInfo) {
    const refreshInviteBoxInfo = function () {
        const allowed = inviteReparto.value === "cs" || inviteReparto.value === "box";
        inviteBoxInfoField.classList.toggle("d-none", !allowed);
        if (!allowed) {
            inviteBoxInfo.checked = false;
        }
    };
    inviteReparto.addEventListener("change", refreshInviteBoxInfo);
    refreshInviteBoxInfo();
}

document.querySelectorAll('form[action="connection_files/manage_invites.php"]').forEach(function (form) {
    form.addEventListener("submit", function (event) {
        if (form.dataset.submitting === "1") {
            event.preventDefault();
            return;
        }

        form.dataset.submitting = "1";
        const action = form.querySelector('input[name="action"]')?.value || "";
        const label = action === "revoke"
            ? "Revoco..."
            : (action === "regenerate" ? "Reinvio..." : "Invio...");
        form.querySelectorAll('button[type="submit"]').forEach(function (button) {
            button.disabled = true;
            button.textContent = label;
        });
    });
});
