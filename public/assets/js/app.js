// public/assets/js/app.js

document.addEventListener("DOMContentLoaded", () => {
    // --- Gestion du thème --------------------------------------------------
    const themeToggleBtn = document.getElementById("theme-toggle");
    const themeToggleIcon = document.getElementById("theme-toggle-icon");
    const themeToggleLabel = document.getElementById("theme-toggle-label");

    function applyTheme(theme) {
        const root = document.documentElement;

        // Tailwind v4 browser : thème piloté par data-theme
        root.setAttribute("data-theme", theme);

        if (theme === "dark") {
            if (themeToggleIcon) themeToggleIcon.textContent = "🌙";
            if (themeToggleLabel) themeToggleLabel.textContent = "Thème sombre";
        } else {
            if (themeToggleIcon) themeToggleIcon.textContent = "☀️";
            if (themeToggleLabel) themeToggleLabel.textContent = "Thème clair";
        }

        localStorage.setItem("bsi_theme", theme);
    }

    // Thème initial : localStorage > préférence système > dark par défaut
    const storedTheme = localStorage.getItem("bsi_theme");
    if (storedTheme === "light" || storedTheme === "dark") {
        applyTheme(storedTheme);
    } else {
        const prefersDark =
            window.matchMedia &&
            window.matchMedia("(prefers-color-scheme: dark)").matches;
        // Mets "light" ici si tu veux clair par défaut
        applyTheme(prefersDark ? "dark" : "dark");
    }

    if (themeToggleBtn) {
        themeToggleBtn.addEventListener("click", () => {
            const current =
                document.documentElement.getAttribute("data-theme") === "dark"
                    ? "dark"
                    : "light";
            const next = current === "dark" ? "light" : "dark";
            applyTheme(next);
        });
    }

    // --- Reste du code : formulaire & API ----------------------------------

    const form = document.getElementById("bsi-form");
    const submitButton = document.getElementById("submit-button");
    const submitButtonLabel = document.getElementById("submit-button-label");
    const submitButtonSpinner = document.getElementById(
        "submit-button-spinner"
    );

    const inputBsiMoney = document.getElementById("input-bsi-money");
    const inputBsiJours = document.getElementById("input-bsi-jours");
    const inputBsiDescription = document.getElementById(
        "input-bsi-description"
    );

    const labelBsiMoney = document.getElementById("label-bsi-money");
    const labelBsiJours = document.getElementById("label-bsi-jours");
    const labelBsiDescription = document.getElementById(
        "label-bsi-description"
    );

    const progressBar = document.getElementById("progress-bar");
    const progressLabel = document.getElementById("progress-label");
    const logsContainer = document.getElementById("logs");

    const statTotalEmployees = document.getElementById(
        "stat-total-employees"
    );
    const statForfaitJours = document.getElementById("stat-forfait-jours");
    const statFilesGenerated = document.getElementById("stat-files-generated");

    const runBadge = document.getElementById("run-badge");
    const downloadWrapper = document.getElementById("download-wrapper");
    const downloadLink = document.getElementById("download-link");

    const campaignYearInput = document.getElementById("campaign-year");
    const campaignYearValue = document.getElementById("campaign-year-value");

    // Helpers UI
    function setButtonLoading(isLoading) {
        if (!submitButton || !submitButtonLabel || !submitButtonSpinner) return;
        submitButton.disabled = isLoading;
        if (isLoading) {
            submitButtonLabel.textContent = "Génération en cours…";
            submitButtonSpinner.classList.remove("hidden");
        } else {
            submitButtonLabel.textContent = "Lancer la génération";
            submitButtonSpinner.classList.add("hidden");
        }
    }

    function setProgress(value, label) {
        if (!progressBar || !progressLabel) return;
        const clamped = Math.max(0, Math.min(100, value));
        progressBar.style.width = clamped + "%";
        if (label) {
            progressLabel.textContent = label;
        }
    }

    function appendLog(message, type = "info") {
        if (!logsContainer) return;
        const line = document.createElement("p");
        line.textContent = message;
        if (type === "error") {
            line.className = "text-red-500 dark:text-red-300";
        } else if (type === "success") {
            line.className = "text-emerald-700 dark:text-emerald-300";
        } else {
            line.className = "text-slate-700 dark:text-slate-300";
        }
        logsContainer.appendChild(line);
        logsContainer.scrollTop = logsContainer.scrollHeight;
    }

    function resetStats() {
        if (statTotalEmployees) statTotalEmployees.textContent = "–";
        if (statForfaitJours) statForfaitJours.textContent = "–";
        if (statFilesGenerated) statFilesGenerated.textContent = "–";
        if (downloadWrapper) downloadWrapper.classList.add("hidden");
        if (downloadLink) downloadLink.removeAttribute("href");
    }

    function setRunStatus(text, variant = "idle") {
        if (!runBadge) return;
        runBadge.textContent = text;
        runBadge.className =
            "inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium";

        if (variant === "running") {
            runBadge.classList.add(
                "bg-sky-100",
                "text-sky-700",
                "dark:bg-sky-500/10",
                "dark:text-sky-300"
            );
        } else if (variant === "success") {
            runBadge.classList.add(
                "bg-emerald-100",
                "text-emerald-700",
                "dark:bg-emerald-500/10",
                "dark:text-emerald-300"
            );
        } else if (variant === "error") {
            runBadge.classList.add(
                "bg-red-100",
                "text-red-700",
                "dark:bg-red-500/10",
                "dark:text-red-300"
            );
        } else {
            runBadge.classList.add(
                "bg-slate-100",
                "text-slate-500",
                "dark:bg-slate-800",
                "dark:text-slate-300"
            );
        }
    }

    // Gestion labels fichiers
    function updateFileLabel(input, labelElement, multiple = false) {
        if (!input || !labelElement) return;
        const files = input.files;
        if (!files || files.length === 0) {
            labelElement.textContent = "Aucun fichier sélectionné";
            labelElement.classList.remove("text-sky-300", "text-sky-600");
            labelElement.classList.add("text-slate-500");
            return;
        }

        if (multiple) {
            labelElement.textContent =
                files.length === 1
                    ? files[0].name
                    : `${files.length} fichiers sélectionnés`;
        } else {
            labelElement.textContent = files[0].name;
        }

        labelElement.classList.remove("text-slate-500");
        labelElement.classList.add("text-sky-600", "dark:text-sky-300");
    }

    if (inputBsiMoney && labelBsiMoney) {
        inputBsiMoney.addEventListener("change", () => {
            updateFileLabel(inputBsiMoney, labelBsiMoney, false);
        });
    }

    if (inputBsiJours && labelBsiJours) {
        inputBsiJours.addEventListener("change", () => {
            updateFileLabel(inputBsiJours, labelBsiJours, false);
        });
    }

    if (inputBsiDescription && labelBsiDescription) {
        inputBsiDescription.addEventListener("change", () => {
            updateFileLabel(inputBsiDescription, labelBsiDescription, true);
        });
    }

    // Sync année campagne dans le header
    function syncCampaignYear() {
        if (!campaignYearInput || !campaignYearValue) return;
        campaignYearValue.textContent = campaignYearInput.value || "—";
    }

    if (campaignYearInput) {
        campaignYearInput.addEventListener("input", syncCampaignYear);
        syncCampaignYear();
    }

    // Soumission du formulaire
    if (form) {
        form.addEventListener("submit", async (event) => {
            event.preventDefault();

            if (
                !inputBsiMoney?.files.length ||
                !inputBsiJours?.files.length ||
                !inputBsiDescription?.files.length
            ) {
                appendLog(
                    "Merci de sélectionner tous les fichiers requis avant de lancer la génération.",
                    "error"
                );
                setRunStatus("Champs manquants", "error");
                return;
            }

            if (logsContainer) logsContainer.innerHTML = "";
            resetStats();
            setProgress(5, "Démarrage de la génération…");
            setRunStatus("Génération en cours…", "running");
            setButtonLoading(true);
            appendLog("Initialisation de la génération des BSI…");

            const formData = new FormData();
            formData.append("bsi_money", inputBsiMoney.files[0]);
            formData.append("bsi_jours", inputBsiJours.files[0]);
            for (const file of inputBsiDescription.files) {
                formData.append("bsi_description[]", file);
            }
            if (campaignYearInput) {
                formData.append(
                    "campaign_year",
                    campaignYearInput.value || ""
                );
            }

            try {
                setProgress(20, "Envoi des fichiers au serveur…");

                const response = await fetch("api/generate-bsi.php", {
                    method: "POST",
                    body: formData,
                });

                setProgress(50, "Traitement des données en cours…");

                if (!response.ok) {
                    throw new Error(`Erreur HTTP ${response.status}`);
                }

                const result = await response.json();

                if (!result.success) {
                    throw new Error(
                        result.error ||
                            "Une erreur inconnue est survenue pendant la génération."
                    );
                }

                appendLog(
                    "Lecture des CSV et détection des collaborateurs terminées."
                );
                if (
                    typeof result.totalEmployees === "number" &&
                    statTotalEmployees
                ) {
                    statTotalEmployees.textContent = result.totalEmployees;
                }
                if (
                    typeof result.forfaitJoursCount === "number" &&
                    statForfaitJours
                ) {
                    statForfaitJours.textContent = result.forfaitJoursCount;
                }
                if (
                    typeof result.filesGenerated === "number" &&
                    statFilesGenerated
                ) {
                    statFilesGenerated.textContent = result.filesGenerated;
                }

                if (result.downloadUrl && downloadLink && downloadWrapper) {
                    downloadLink.href = result.downloadUrl;
                    downloadWrapper.classList.remove("hidden");
                    appendLog(
                        "Bundle BSI généré avec succès. Prêt au téléchargement.",
                        "success"
                    );
                } else {
                    appendLog(
                        "Génération terminée, mais aucun bundle à télécharger n'a été fourni.",
                        "info"
                    );
                }

                setProgress(100, "Génération terminée");
                setRunStatus("Génération terminée", "success");
            } catch (error) {
                console.error(error);
                appendLog(`Erreur : ${error.message}`, "error");
                setRunStatus("Erreur lors de la génération", "error");
                setProgress(0, "Erreur");
            } finally {
                setButtonLoading(false);
            }
        });
    }
});
