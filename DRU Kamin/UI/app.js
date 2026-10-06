(() => {
    const payload = document.getElementById("druData");
    let data = JSON.parse(payload.textContent);
    let values = data.values || {};
    let features = data.features || {};
    let waveSettings = data.waveSettings || { interval: 10, stages: Array(20).fill(50) };
    const controls = document.getElementById("controls");
    const statusLabel = document.getElementById("connectionStatus");
    const temperature = document.getElementById("temperature");
    const statusBar = temperature.parentElement;
    let status = 0;
    let statusAvailable = false;
    let mainBurnerOn = false;
    let fault = false;
    let resetPending = false;
    let ignitionForbidden = false;
    let waveActive = false;
    let temperatureState = 0;
    let temperatureActive = false;
    let operationMode = "manual";
    let statusExpiryTimer;
    let renderedState = "";
    let startUnlocked = false;
    let ignitionStartedAt = 0;
    let holdTimer;
    let holdFrame;
    let ignitionFrame;
    let ignitionTimeout;
    let holdStartedAt = 0;
    let holdButton;
    let suppressStartClick = false;
    let waveDraft;
    let waveEditing = false;
    let offTimer = { state: "idle", duration: 3600, deadline: 0, remaining: 0, error: "" };
    let timerClockOffset = 0;
    let timerPending = false;
    let timerPendingTimeout;
    let timerDialog;
    const footer = statusLabel.parentElement;
    footer.classList.add("timer-footer");
    const countdown = document.createElement("span");
    countdown.className = "timer-countdown";
    countdown.setAttribute("aria-label", "Austimer Restzeit");
    const timerButton = document.createElement("button");
    timerButton.type = "button";
    timerButton.className = "control timer-button";
    timerButton.title = "Austimer";
    timerButton.setAttribute("aria-label", "Austimer öffnen");
    timerButton.setAttribute("aria-haspopup", "dialog");
    timerButton.append(symbolSvg("timer"));
    timerButton.addEventListener("click", openTimerDialog);
    footer.append(countdown, timerButton);
    window.setInterval(updateTimerDisplay, 1000);
    const presetElement = document.getElementById("wavePresets");
    const wavePresets = presetElement ? JSON.parse(presetElement.textContent) : window.druWavePresets || [];
    if (!Array.isArray(wavePresets) || wavePresets.some(preset =>
        typeof preset.id !== "string" || typeof preset.name !== "string"
        || !Number.isInteger(preset.interval) || preset.interval < 5 || preset.interval > 60
        || !Array.isArray(preset.stages) || preset.stages.length !== 20
        || preset.stages.some(stage => !Number.isInteger(stage) || stage < 0 || stage > 100))) {
        throw new Error("Ungültige Wave-Vorlagen");
    }

    const flamePaths = [
        "M52 7C67 25 80 42 80 62C80 81 67 94 50 94C32 94 20 81 20 64C20 47 29 36 39 26C37 40 42 48 47 53C57 39 60 24 52 7Z",
        "M53 30C63 44 69 54 69 67C69 80 61 89 50 89C38 89 31 80 31 68C31 57 37 49 43 43C43 52 47 58 51 62C57 53 59 42 53 30Z",
        "M51 55C59 63 62 69 62 75C62 83 57 88 50 88C43 88 38 82 38 75C38 68 43 62 46 59C46 65 49 68 51 70C54 65 54 60 51 55Z"
    ];

    function flameSvg(colored) {
        const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
        svg.setAttribute("viewBox", "0 0 100 100");
        svg.setAttribute("aria-hidden", "true");
        svg.classList.add(colored ? "flame-color" : "flame-gray");
        const colors = colored ? ["#ef4444", "#f59e0b", "#fde047"] : ["#6b7280", "#9ca3af", "#d1d5db"];
        flamePaths.forEach((shape, index) => {
            const path = document.createElementNS(svg.namespaceURI, "path");
            path.setAttribute("d", shape);
            path.setAttribute("fill", colors[index]);
            svg.appendChild(path);
        });
        return svg;
    }

    function symbolSvg(name) {
        const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
        svg.setAttribute("viewBox", "0 0 32 32");
        svg.setAttribute("aria-hidden", "true");
        svg.classList.add("symbol");
        const shapes = {
            manual: ["M8 24V13a2 2 0 0 1 4 0v5V7a2 2 0 0 1 4 0v10V9a2 2 0 0 1 4 0v9V13a2 2 0 0 1 4 0v8l-4 7H12l-6-7a2 2 0 0 1 3-2"],
            temperature: ["M13 20V7a3 3 0 0 1 6 0v13a6 6 0 1 1-6 0Z", "M16 12v11", "M24 8h3M24 13h3"],
            wave: ["M3 10Q8 3 13 10T23 10T29 10", "M3 17Q8 10 13 17T23 17T29 17", "M3 24Q8 17 13 24T23 24T29 24"],
            light: ["M10 19a9 9 0 1 1 12 0l-2 3h-8Z", "M12 26h8M14 29h4", "M16 10v8M12 13l4 4 4-4"],
            boost: ["M16 14C9 4 22 1 24 8c1 4-5 7-8 6Z", "M18 17c12-2 12 11 5 12-4 0-6-7-5-12Z", "M14 18C8 28 0 18 4 13c3-3 9 1 10 5Z"],
            edit: ["M7 21 22 6l4 4-15 15-6 2Z", "M19 9l4 4"],
            save: ["M5 5h19l3 3v19H5Z", "M10 5v9h12V5", "M10 27v-9h12v9"],
            timer: ["M12 3h8M16 3v4", "M25 7l3 3", "M16 10v8l4 2", "M27 18a11 11 0 1 1-22 0 11 11 0 0 1 22 0"],
            reset: ["M25 12a11 11 0 1 0 1 10", "M25 4v8h-8", "M16 11v7M16 23v.1"]
        };
        (shapes[name] || []).forEach(shape => {
            const path = document.createElementNS(svg.namespaceURI, "path");
            path.setAttribute("d", shape);
            path.setAttribute("fill", "none");
            path.setAttribute("stroke", "currentColor");
            path.setAttribute("stroke-width", "2");
            path.setAttribute("stroke-linecap", "round");
            path.setAttribute("stroke-linejoin", "round");
            svg.appendChild(path);
        });
        return svg;
    }

    function addButtonIcon(button, name) {
        const text = button.textContent;
        const label = document.createElement("span");
        label.textContent = text;
        button.replaceChildren(symbolSvg(name), label);
        button.classList.add("icon-button");
        button.setAttribute("aria-label", text);
    }

    function endpointFlame(size) {
        const flame = flameSvg(true);
        flame.classList.add("range-flame", size);
        return flame;
    }

    function cancelHold() {
        window.clearTimeout(holdTimer);
        window.cancelAnimationFrame(holdFrame);
        if (holdButton) {
            holdButton.style.setProperty("--hold-progress", "0");
        }
        holdButton = undefined;
        holdStartedAt = 0;
    }

    function clearStartState() {
        cancelHold();
        startUnlocked = false;
        suppressStartClick = false;
        ignitionStartedAt = 0;
        window.clearTimeout(ignitionTimeout);
        window.cancelAnimationFrame(ignitionFrame);
    }

    function canStart() {
        return statusAvailable && !mainBurnerOn && !fault && !ignitionForbidden && !temperatureActive
            && values.Fireplace !== undefined;
    }

    function startIgnition() {
        if (!startUnlocked || !canStart() || ignitionStartedAt) {
            return;
        }
        startUnlocked = false;
        ignitionStartedAt = performance.now();
        ignitionTimeout = window.setTimeout(() => {
            if (!ignitionStartedAt) {
                return;
            }
            clearStartState();
            renderedState = "";
            render();
            statusLabel.textContent = "Keine Zündbestätigung – Start erneut freigeben.";
        }, 30000);
        renderedState = "";
        render();
        sendAction("Fireplace", true);
    }

    window.addEventListener("blur", () => {
        if (!ignitionStartedAt) {
            clearStartState();
            renderedState = "";
            render();
        }
    });
    document.addEventListener("pointerup", cancelHold);
    document.addEventListener("pointercancel", cancelHold);
    document.addEventListener("visibilitychange", () => {
        if (document.hidden) {
            clearStartState();
            renderedState = "";
            render();
        }
    });

    const toggleLabels = {
        Fireplace: "Hauptbrenner",
        SecondBurner: "Zweiter Brenner",
        Light: "Kaminlicht",
        BoostFan: "Boost-Lüfter"
    };
    const toggleBits = { Fireplace: 2, SecondBurner: 3, Light: 8, BoostFan: 7 };

    function sendAction(ident, value) {
        statusLabel.textContent = "Befehl gesendet – warte auf Rückmeldung";
        requestAction(ident, value);
    }

    function applyData(nextData) {
        try {
            data = typeof nextData === "string" ? JSON.parse(nextData) : nextData;
            if (!data || typeof data !== "object" || !Number.isInteger(data.statusRegister)
                || data.statusRegister < 0 || data.statusRegister > 65535) {
                throw new Error("Ungültige Statusnachricht");
            }
        } catch (error) {
            console.error("DRU Statusnachricht konnte nicht verarbeitet werden", error);
            window.clearTimeout(statusExpiryTimer);
            renderedState = "";
            statusAvailable = false;
            clearStartState();
            statusLabel.textContent = "Statusnachricht ungültig – Steuerung gesperrt";
            statusLabel.classList.add("error");
            render();
            return;
        }
        values = data.values || {};
        features = data.features || {};
        if (data.offTimer) {
            offTimer = data.offTimer;
            timerClockOffset = Number(offTimer.serverTime) * 1000 - Date.now();
            timerPending = false;
            window.clearTimeout(timerPendingTimeout);
        }
        const nextWaveSettings = data.waveSettings || waveSettings;
        if (JSON.stringify(nextWaveSettings) !== JSON.stringify(waveSettings)) {
            waveDraft = undefined;
        }
        waveSettings = nextWaveSettings;
        status = Number(data.statusRegister || 0);
        statusAvailable = data.statusAvailable === true;
        mainBurnerOn = (status & (1 << 2)) !== 0;
        fault = (status & 1) !== 0;
        resetPending = data.resetPending === true;
        ignitionForbidden = (status & (1 << 15)) !== 0;
        waveActive = (status & (1 << 9)) !== 0;
        temperatureState = (status >> 13) & 0b11;
        temperatureActive = temperatureState === 0b10;
        operationMode = temperatureActive
            ? "temperature"
            : waveActive
                ? "wave"
                : "manual";
        if (operationMode !== "wave") {
            waveDraft = undefined;
            waveEditing = false;
        }
        if (!statusAvailable || mainBurnerOn || fault || temperatureActive || values.Fireplace === undefined
            || (!ignitionStartedAt && ignitionForbidden)) {
            clearStartState();
        }

        statusLabel.classList.remove("error");
        if (data.message) {
            statusLabel.textContent = data.message;
        } else if (!statusAvailable) {
            statusLabel.textContent = "Kein aktueller Modbus-Status – Steuerung gesperrt";
            statusLabel.classList.add("error");
        } else if (fault && resetPending) {
            statusLabel.textContent = "Kamin-Reset läuft – warte auf Rückmeldung";
        } else if (fault) {
            statusLabel.textContent = "Kaminfehler erkannt";
            statusLabel.classList.add("error");
        } else if (ignitionStartedAt) {
            statusLabel.textContent = "Zündung läuft – warte auf Hauptbrenner";
        } else {
            statusLabel.textContent = "Verbunden";
        }
        if (offTimer.error) {
            statusLabel.textContent = offTimer.error;
            statusLabel.classList.add("error");
        }
        updateTimerDisplay();

        window.clearTimeout(statusExpiryTimer);
        if (statusAvailable) {
            statusExpiryTimer = window.setTimeout(() => {
                statusAvailable = false;
                clearStartState();
                renderedState = "";
                statusLabel.textContent = "Kein aktueller Modbus-Status – Steuerung gesperrt";
                statusLabel.classList.add("error");
                updateTimerDisplay();
                render();
            }, Math.max(0, Number(data.statusValidForMs ?? 15000)));
        }
        const nextState = JSON.stringify([values, features, waveSettings, status, statusAvailable, resetPending, data.canSetFlameHeight]);
        if (nextState !== renderedState) {
            renderedState = nextState;
            render();
        }
    }

    window.handleMessage = applyData;

    function timerRemaining() {
        return offTimer.state === "running"
            ? Math.max(0, Math.ceil(Number(offTimer.deadline) - (Date.now() + timerClockOffset) / 1000))
            : Math.max(0, Number(offTimer.remaining) || 0);
    }

    function updateTimerDisplay() {
        const remaining = timerRemaining();
        const time = [Math.floor(remaining / 3600), Math.floor(remaining / 60) % 60, remaining % 60]
            .map(part => String(part).padStart(2, "0")).join(":");
        countdown.textContent = offTimer.state === "idle" ? "" : offTimer.state === "stopping"
            ? "Abschaltung…" : `${offTimer.state === "paused" ? "Ⅱ " : ""}${time}`;
        countdown.title = offTimer.state === "paused" ? "Austimer pausiert" : "Austimer Restzeit";
        countdown.classList.toggle("error", Boolean(offTimer.error));
        timerButton.setAttribute("aria-pressed", String(offTimer.state !== "idle"));
        if (!timerDialog) {
            return;
        }
        const active = timerDialog.querySelector("#timerActive");
        active.checked = offTimer.state === "running" || offTimer.state === "stopping";
        const canRun = statusAvailable && !fault && (mainBurnerOn || waveActive || temperatureActive)
            && values.Fireplace !== undefined;
        const valid = timerDuration() !== null;
        active.disabled = timerPending || (!active.checked && (!canRun || !valid));
        timerDialog.querySelector('[data-timer-action="reset"]').disabled = timerPending || !canRun || !valid;
        timerDialog.querySelector('[data-timer-action="pause"]').disabled = timerPending || !active.checked;
        timerDialog.querySelector('[data-timer-action="delete"]').disabled = timerPending || offTimer.state === "idle";
        timerDialog.querySelector(".timer-feedback").textContent = timerPending ? "Warte auf Timer-Rückmeldung…"
            : offTimer.error || (offTimer.state === "paused" ? `Pausiert: ${time}` : offTimer.state === "stopping"
                ? "Abschaltung wird bestätigt; bei Fehler Wiederholung alle 30 Sekunden."
                : offTimer.state === "running" ? `Restzeit: ${time}` : "Kein Austimer aktiv.");
    }

    function timerDuration() {
        const hours = timerDialog.querySelector("#timerHours");
        const minutes = timerDialog.querySelector("#timerMinutes");
        if (!hours.checkValidity() || !minutes.checkValidity() || hours.value === "" || minutes.value === "") {
            return null;
        }
        const duration = Number(hours.value) * 3600 + Number(minutes.value) * 60;
        return Number.isInteger(duration) && duration >= 60 && duration <= 86400 ? duration : null;
    }

    function timerAction(action) {
        if (timerPending) {
            return;
        }
        const duration = timerDuration();
        if ((action === "start" || action === "reset") && duration === null) {
            timerDialog.querySelector(".timer-feedback").textContent = "Bitte 1 Minute bis 24 Stunden wählen.";
            return;
        }
        timerPending = true;
        updateTimerDisplay();
        timerPendingTimeout = window.setTimeout(() => {
            timerPending = false;
            updateTimerDisplay();
            statusLabel.textContent = "Keine Timer-Rückmeldung – bitte Status prüfen.";
            statusLabel.classList.add("error");
        }, 15000);
        sendAction("OffTimerAction", JSON.stringify({ action, duration }));
    }

    function closeTimerDialog() {
        if (!timerDialog) {
            return;
        }
        timerDialog.remove();
        timerDialog = undefined;
        [statusBar, controls, footer].forEach(element => { element.inert = false; });
        timerButton.focus();
    }

    function openTimerDialog() {
        if (timerDialog) {
            return;
        }
        timerDialog = document.createElement("div");
        timerDialog.className = "timer-overlay";
        timerDialog.innerHTML = `<section class="timer-dialog" role="dialog" aria-modal="true" aria-labelledby="timerTitle">
            <div class="timer-heading"><h2 id="timerTitle">Austimer</h2>
                <button type="button" class="control" data-timer-close aria-label="Austimer schließen">×</button></div>
            <div class="timer-duration">
                <label>Stunden<input id="timerHours" type="number" min="0" max="24" step="1" required></label>
                <label>Minuten<input id="timerMinutes" type="number" min="0" max="59" step="1" required></label>
            </div>
            <label class="timer-switch">Aktiv<input id="timerActive" type="checkbox" role="switch"></label>
            <p class="timer-feedback" role="status"></p>
            <div class="timer-actions">
                <button type="button" class="control" data-timer-action="pause">Stopp</button>
                <button type="button" class="control" data-timer-action="delete">Löschen</button>
                <button type="button" class="control" data-timer-action="reset">Reset</button>
            </div>
        </section>`;
        const duration = Number(offTimer.duration) || 3600;
        timerDialog.querySelector("#timerHours").value = Math.floor(duration / 3600);
        timerDialog.querySelector("#timerMinutes").value = Math.floor(duration / 60) % 60;
        timerDialog.querySelector("[data-timer-close]").addEventListener("click", closeTimerDialog);
        timerDialog.querySelector("#timerActive").addEventListener("change", event => {
            timerAction(event.target.checked ? "start" : "pause");
        });
        timerDialog.querySelectorAll("[data-timer-action]").forEach(button => {
            button.addEventListener("click", () => timerAction(button.dataset.timerAction));
        });
        timerDialog.querySelectorAll('input[type="number"]').forEach(input => {
            input.addEventListener("input", updateTimerDisplay);
        });
        timerDialog.addEventListener("keydown", event => {
            if (event.key === "Escape") {
                closeTimerDialog();
            } else if (event.key === "Tab") {
                const elements = [...timerDialog.querySelectorAll("button:not(:disabled), input:not(:disabled)")];
                const index = elements.indexOf(document.activeElement);
                if ((event.shiftKey && index <= 0) || (!event.shiftKey && index === elements.length - 1)) {
                    event.preventDefault();
                    elements[event.shiftKey ? elements.length - 1 : 0].focus();
                }
            }
        });
        document.querySelector(".dru").append(timerDialog);
        [statusBar, controls, footer].forEach(element => { element.inert = true; });
        updateTimerDisplay();
        timerDialog.querySelector("#timerHours").focus();
    }

    function render() {
        cancelHold();
        window.cancelAnimationFrame(ignitionFrame);
        renderedState = JSON.stringify([values, features, waveSettings, status, statusAvailable, resetPending, data.canSetFlameHeight]);
        controls.replaceChildren();
        statusBar.replaceChildren(temperature);
        temperature.replaceChildren();
        temperature.removeAttribute("aria-label");
        if (values.RoomTemperature !== undefined) {
            const actual = document.createElement("span");
            actual.textContent = `${Number(values.RoomTemperature).toFixed(1)} °C`;
            actual.title = "Isttemperatur";
            temperature.append(symbolSvg("temperature"), actual);
        }
        if (statusAvailable && temperatureActive) {
            const indicator = document.createElement("span");
            indicator.className = "temperature-active";
            indicator.textContent = "●";
            indicator.title = "Temperaturregelung aktiv";
            indicator.setAttribute("aria-label", indicator.title);
            temperature.appendChild(indicator);
            if (values.TemperatureSetpoint !== undefined) {
                const target = document.createElement("span");
                target.className = "temperature-target";
                target.textContent = `→ ${Number(values.TemperatureSetpoint).toFixed(1)} °C`;
                target.title = "Solltemperatur";
                temperature.appendChild(target);
            }
        }
        controls.classList.toggle("fireplace-running", statusAvailable && mainBurnerOn);
        controls.classList.toggle("temperature-regulating", statusAvailable && temperatureActive);

        if (values.Fireplace !== undefined) {
            renderFireButton();
        }

        if (fault) {
            const reset = document.createElement("button");
            reset.className = "control reset-button";
            reset.type = "button";
            reset.textContent = resetPending ? "Reset läuft …" : "Kamin zurücksetzen";
            addButtonIcon(reset, "reset");
            reset.disabled = !statusAvailable || resetPending || (status & (1 << 6)) === 0;
            reset.title = resetPending
                ? "Auf die Rückmeldung des Kamins warten."
                : (status & (1 << 6)) === 0
                    ? "Der Kamin erlaubt derzeit keinen Reset durch den Benutzer."
                    : "Kaminfehler zurücksetzen.";
            reset.addEventListener("click", () => {
                resetPending = true;
                renderedState = "";
                render();
                sendAction("ResetFireplace", true);
            });
            controls.appendChild(reset);
        }

        Object.entries(toggleLabels).forEach(([ident, label]) => {
            if (ident === "Fireplace" || values[ident] === undefined
                || (ident === "SecondBurner" && (!statusAvailable || !mainBurnerOn))) {
                return;
            }

            const isOn = (status & (1 << toggleBits[ident])) !== 0;
            const button = document.createElement("button");
            button.className = "control";
            button.type = "button";
            button.setAttribute("aria-pressed", String(statusAvailable && isOn));
            button.textContent = `${label}: ${statusAvailable ? (isOn ? "Ein" : "Aus") : "Unbekannt"}`;
            button.dataset.ident = ident;
            if (ident === "SecondBurner") {
                const accessibleLabel = button.textContent;
                const badge = document.createElement("span");
                badge.className = "burner-number";
                badge.textContent = "2";
                button.replaceChildren(flameSvg(statusAvailable && isOn), badge);
                button.classList.add("second-burner");
                button.setAttribute("aria-label", accessibleLabel);
                button.title = accessibleLabel;
            } else {
                addButtonIcon(button, ident === "Light" ? "light" : "boost");
                button.classList.add("status-toggle");
                button.title = button.getAttribute("aria-label");
                button.querySelector("span").hidden = true;
            }

            let allowed = statusAvailable;
            if (ident === "Fireplace" && !isOn) {
                allowed = allowed && !fault && !ignitionForbidden && !temperatureActive;
                if (temperatureActive) {
                    button.title = "Im Temperaturmodus regelt der Kamin den Brenner selbst.";
                } else if (ignitionForbidden) {
                    button.title = "Der Kamin hat die Zündung momentan nicht freigegeben.";
                } else if (fault) {
                    button.title = "Ein Kaminfehler verhindert die Zündung.";
                }
            } else if (ident === "SecondBurner" && !isOn) {
                allowed = allowed && mainBurnerOn && !fault;
            } else if (!isOn) {
                allowed = allowed && !fault;
            }
            button.disabled = !allowed;
            button.addEventListener("click", () => sendAction(ident, !isOn));
            if (ident === "SecondBurner") {
                let toolbar = controls.querySelector(".fire-toolbar");
                if (!toolbar) {
                    toolbar = document.createElement("section");
                    toolbar.className = "fire-toolbar";
                    controls.prepend(toolbar);
                }
                toolbar.appendChild(button);
            } else {
                statusBar.appendChild(button);
            }
        });

        const burnerToolbar = controls.querySelector(".fire-toolbar");
        const statusActions = document.createElement("div");
        statusActions.className = "status-actions";
        statusActions.append(...statusBar.querySelectorAll(".status-toggle"));
        if (burnerToolbar) {
            statusActions.appendChild(burnerToolbar);
        }
        statusBar.prepend(statusActions);
        const modeSection = document.createElement("section");
        modeSection.className = "mode-section";
        const modeSelect = document.createElement("select");
        modeSelect.className = "mode-select";
        modeSelect.setAttribute("aria-label", "Betriebsart");
        modeSelect.disabled = !statusAvailable || (!mainBurnerOn && !temperatureActive && !waveActive);
        const modeIcon = symbolSvg(operationMode);
        modeIcon.classList.add("mode-icon");
        const modeLabel = document.createElement("span");
        modeLabel.className = "mode-label";
        modeLabel.setAttribute("aria-hidden", "true");
        modeLabel.textContent = { manual: "Manuell", temperature: "Temp.", wave: "Wave" }[operationMode];
        modeSection.append(modeIcon, modeLabel, modeSelect);

        [
            ["manual", "Manuell", true],
            ["temperature", "Temp.", Boolean(features.temperatureControl)],
            ["wave", "Wave", Boolean(features.wave)]
        ].forEach(([mode, label, available]) => {
            if (!available) {
                return;
            }

            const option = document.createElement("option");
            option.value = mode;
            option.textContent = `${{ manual: "\u270B\uFE0E", temperature: "\u{1F321}\uFE0E", wave: "≋" }[mode]} ${label}`;
            const selected = operationMode === mode;
            let allowed = !modeSelect.disabled && !selected;
            if (mode === "temperature") {
                allowed = allowed && !fault && temperatureState !== 0b00
                    && temperatureState !== 0b11 && mainBurnerOn;
            } else if (mode === "wave") {
                allowed = allowed && !fault && mainBurnerOn;
            }
            option.disabled = !allowed && !selected;
            modeSelect.appendChild(option);
        });
        modeSelect.value = operationMode;
        modeSelect.addEventListener("change", () => {
            const selected = modeSelect.selectedOptions[0];
            if (!selected || selected.disabled) {
                modeSelect.value = operationMode;
                return;
            }
            const requested = modeSelect.value;
            modeSelect.value = operationMode;
            sendAction("OperationMode", requested);
        });
        statusBar.appendChild(modeSection);
        renderRanges();
    }

    function renderFireButton() {
        const running = statusAvailable && mainBurnerOn;
        const section = document.createElement("section");
        section.className = running ? "fire-toolbar" : "fire-start";
        const button = document.createElement("button");
        button.type = "button";
        button.className = "fire-button";
        if (running) {
            button.classList.add("control");
            button.setAttribute("aria-pressed", "true");
            button.title = "Kamin ausschalten";
        }
        button.dataset.state = running ? "running" : ignitionStartedAt ? "igniting" : startUnlocked ? "ready" : "locked";
        button.disabled = running ? false : !canStart() || Boolean(ignitionStartedAt);
        button.setAttribute("aria-label", running ? "Kamin ausschalten" : temperatureActive ? "Temperaturregelung steuert den Brenner automatisch" : "Kamin starten: drei Sekunden halten, danach antippen");
        const icon = document.createElement("span");
        icon.className = "fire-icon";
        icon.append(flameSvg(false), flameSvg(true));
        const lock = document.createElementNS("http://www.w3.org/2000/svg", "svg");
        lock.classList.add("fire-lock");
        lock.setAttribute("viewBox", "0 0 32 36");
        lock.setAttribute("aria-hidden", "true");
        const shackle = document.createElementNS(lock.namespaceURI, "path");
        shackle.setAttribute("d", "M8 15V10a8 8 0 0 1 16 0v5");
        shackle.setAttribute("fill", "none");
        shackle.setAttribute("stroke", "currentColor");
        shackle.setAttribute("stroke-width", "3");
        const body = document.createElementNS(lock.namespaceURI, "rect");
        body.setAttribute("x", "3"); body.setAttribute("y", "14");
        body.setAttribute("width", "26"); body.setAttribute("height", "20"); body.setAttribute("rx", "5");
        body.setAttribute("fill", "currentColor");
        const keyhole = document.createElementNS(lock.namespaceURI, "path");
        keyhole.setAttribute("d", "M16 19a3 3 0 0 1 1.5 5.6V29h-3v-4.4A3 3 0 0 1 16 19Z");
        keyhole.setAttribute("fill", "#374151");
        lock.append(shackle, body, keyhole);
        icon.appendChild(lock);
        const label = document.createElement("span");
        label.className = "fire-label";
        label.textContent = running ? "AUS" : temperatureActive ? "Automatik" : ignitionStartedAt ? "Zündung läuft" : startUnlocked ? "Antippen zum Zünden" : "3 Sekunden halten";
        button.appendChild(icon);
        if (!running) {
            button.appendChild(label);
        }
        section.appendChild(button);
        if (!running) {
            const hint = document.createElement("p");
            hint.className = "fire-hint";
            hint.textContent = !statusAvailable ? "Auf aktuellen Kaminstatus warten."
                : fault ? "Kaminfehler – Reset prüfen."
                    : temperatureActive ? "Der Temperaturmodus steuert den Brenner automatisch."
                        : ignitionForbidden ? "Der Kamin hat die Zündung noch nicht freigegeben."
                            : ignitionStartedAt ? "Die Farbe zeigt den Zündablauf, nicht die Betriebsbestätigung."
                                : "Halten zum Entriegeln. Danach separat antippen.";
            section.appendChild(hint);
        }
        const beginHold = () => {
            if (!canStart() || startUnlocked || ignitionStartedAt || holdStartedAt) {
                return;
            }
            suppressStartClick = false;
            holdStartedAt = performance.now();
            holdButton = button;
            const update = () => {
                button.style.setProperty("--hold-progress", String(Math.min(1, (performance.now() - holdStartedAt) / 3000)));
                holdFrame = window.requestAnimationFrame(update);
            };
            update();
            holdTimer = window.setTimeout(() => {
                if (!canStart() || holdButton !== button) {
                    cancelHold();
                    return;
                }
                startUnlocked = true;
                suppressStartClick = true;
                button.dataset.state = "ready";
                label.textContent = "Antippen zum Zünden";
                button.setAttribute("aria-label", "Kamin zünden");
                cancelHold();
            }, 3000);
        };
        button.addEventListener("pointerdown", event => {
            if (event.button !== 0 || !event.isPrimary) {
                return;
            }
            beginHold();
        });
        button.addEventListener("pointerup", cancelHold);
        button.addEventListener("pointerleave", cancelHold);
        button.addEventListener("pointercancel", () => { cancelHold(); startUnlocked = false; renderedState = ""; render(); });
        button.addEventListener("contextmenu", event => event.preventDefault());
        button.addEventListener("keydown", event => {
            if (event.key !== " " && event.key !== "Enter") {
                return;
            }
            event.preventDefault();
            if (!event.repeat) {
                beginHold();
            }
        });
        button.addEventListener("keyup", event => {
            if (event.key === " " || event.key === "Enter") {
                event.preventDefault();
                cancelHold();
                button.click();
            }
        });
        button.addEventListener("blur", cancelHold);
        button.addEventListener("click", () => {
            if (running) {
                sendAction("Fireplace", false);
            } else if (suppressStartClick) {
                suppressStartClick = false;
            } else {
                startIgnition();
            }
        });
        controls.appendChild(section);
        if (ignitionStartedAt) {
            const updateFill = () => {
                // Never visually complete ignition before the real main-burner status is confirmed.
                const fill = Math.min(90, (performance.now() - ignitionStartedAt) / 10000 * 90);
                button.style.setProperty("--fire-fill", `${fill}%`);
                ignitionFrame = window.requestAnimationFrame(updateFill);
            };
            updateFill();
        }
    }

    function renderRanges() {
        if (operationMode === "manual" && mainBurnerOn && values.FlameHeight !== undefined) {
            const wrapper = document.createElement("label");
            wrapper.className = "range-control";
            wrapper.textContent = "Flammenhöhe / Leistung";
            const range = document.createElement("input");
            range.type = "range";
            range.setAttribute("aria-label", "Flammenhöhe / Leistung");
            range.min = "0";
            range.max = "100";
            range.step = "1";
            range.value = String(values.FlameHeight);
            range.disabled = !statusAvailable || !data.canSetFlameHeight;
            if (range.disabled) {
                range.title = "Flammenhöhe ist erst nach freigegebener Zündung und im manuellen Modus verfügbar.";
            }
            const output = document.createElement("output");
            output.value = `${range.value} %`;
            range.addEventListener("input", () => {
                output.value = `${range.value} %`;
            });
            range.addEventListener("change", () => {
                sendAction("FlameHeight", Number(range.value));
            });

            const track = document.createElement("span");
            track.className = "flame-range";
            track.append(endpointFlame("small"), range, endpointFlame("large"));
            wrapper.append(track, output);
            controls.appendChild(wrapper);
        }

        if (operationMode === "temperature" && values.TemperatureSetpoint !== undefined) {
            const wrapper = document.createElement("label");
            wrapper.className = "range-control";
            wrapper.classList.add("temperature-control");
            wrapper.textContent = "Solltemperatur";

            const range = document.createElement("input");
            range.type = "range";
            range.className = "temperature-slider";
            range.setAttribute("aria-label", "Solltemperatur");
            range.min = "5";
            range.max = "35";
            range.step = "0.5";
            range.value = String(values.TemperatureSetpoint);
            range.disabled = !statusAvailable || fault;

            const output = document.createElement("output");
            output.value = `${Number(range.value).toFixed(1)} °C`;
            range.addEventListener("input", () => {
                output.value = `${Number(range.value).toFixed(1)} °C`;
            });
            range.addEventListener("change", () => {
                sendAction("TemperatureSetpoint", Number(range.value));
            });

            const track = document.createElement("span");
            track.className = "temperature-range flame-range";
            track.append(endpointFlame("small"), range, endpointFlame("large"));
            wrapper.append(track, output);
            controls.appendChild(wrapper);
        }

        if (operationMode === "wave" && features.wave) {
            renderWaveEditor();
        }
    }

    function renderWaveEditor() {
        const editor = document.createElement("section");
        editor.className = "wave-editor";

        if (waveSettings.available !== true) {
            const notice = document.createElement("p");
            notice.textContent = "Wave-Einstellungen konnten noch nicht gültig aus dem Kamin gelesen werden.";
            editor.appendChild(notice);
            controls.appendChild(editor);
            return;
        }

        const enabled = statusAvailable && mainBurnerOn && waveActive && !fault;
        const draft = waveDraft || { interval: waveSettings.interval, stages: [...waveSettings.stages] };
        waveDraft = draft;

        const profiles = document.createElement("div");
        profiles.className = "wave-profiles";
        const profileLabel = document.createElement("label");
        const profileSelect = document.createElement("select");
        profileSelect.className = "wave-profile-select";
        profileSelect.setAttribute("aria-label", "Wave-Profil");
        profileSelect.disabled = !enabled;
        [["current", "Aktuelles Profil (Kamin)"], ...wavePresets.map(preset => [preset.id, preset.name])]
            .forEach(([id, name]) => {
                const option = document.createElement("option");
                option.value = id;
                option.textContent = name;
                profileSelect.appendChild(option);
            });
        profileSelect.value = draft.profile || "current";
        const loadProfile = () => {
            const selected = profileSelect.value === "current" ? waveSettings : wavePresets.find(preset => preset.id === profileSelect.value);
            if (!selected) {
                console.error("Wave-Profil nicht gefunden", profileSelect.value);
                return;
            }
            waveDraft = { interval: selected.interval, stages: [...selected.stages], profile: profileSelect.value };
            render();
            if (profileSelect.value === "current") {
                sendAction("ReloadWaveSettings", true);
            }
        };
        profileSelect.addEventListener("change", loadProfile);
        profileLabel.appendChild(profileSelect);
        const edit = document.createElement("button");
        edit.className = "control wave-edit icon-button";
        edit.type = "button";
        edit.setAttribute("aria-label", "Wave-Profil bearbeiten");
        edit.setAttribute("aria-expanded", String(waveEditing));
        edit.setAttribute("aria-controls", "wave-details");
        edit.title = "Wave-Profil bearbeiten";
        edit.appendChild(symbolSvg("edit"));
        edit.disabled = !enabled;
        edit.addEventListener("click", () => {
            waveEditing = !waveEditing;
            render();
        });
        const save = document.createElement("button");
        save.className = "control wave-save icon-button";
        save.type = "button";
        save.setAttribute("aria-label", "Wave-Muster speichern");
        save.title = "Wave-Muster speichern";
        save.appendChild(symbolSvg("save"));
        save.disabled = !enabled || !waveEditing;
        save.addEventListener("click", () => {
            save.disabled = true;
            const settings = JSON.stringify({
                interval: draft.interval,
                stages: [...draft.stages]
            });
            waveEditing = false;
            waveDraft = undefined;
            render();
            sendAction("SaveWaveSettings", settings);
        });
        profiles.append(profileLabel, edit, save);
        editor.appendChild(profiles);
        const summary = document.createElement("p");
        summary.className = "wave-summary";
        const updateSummary = () => {
            summary.textContent = `${draft.interval} s · 20 Stufen · ${Math.min(...draft.stages)}–${Math.max(...draft.stages)} %`;
        };
        updateSummary();
        editor.appendChild(summary);

        if (!waveEditing) {
            controls.appendChild(editor);
            return;
        }
        const details = document.createElement("section");
        details.id = "wave-details";
        details.className = "wave-details";
        const intervalLabel = document.createElement("label");
        intervalLabel.className = "interval-control";
        intervalLabel.textContent = "Intervall";
        const interval = document.createElement("input");
        interval.type = "range";
        interval.min = "5";
        interval.max = "60";
        interval.step = "1";
        interval.value = String(draft.interval);
        interval.disabled = !enabled;
        const intervalOutput = document.createElement("output");
        intervalOutput.value = `${draft.interval} s`;
        interval.addEventListener("input", () => {
            draft.interval = Number(interval.value);
            intervalOutput.value = `${draft.interval} s`;
            updateSummary();
        });
        intervalLabel.append(interval, intervalOutput);
        details.appendChild(intervalLabel);
        const stages = document.createElement("div");
        stages.className = "wave-stages";
        draft.stages.forEach((value, index) => {
            const wrapper = document.createElement("label");
            wrapper.className = "wave-stage";
            const output = document.createElement("output");
            output.value = `${value}%`;
            const range = document.createElement("input");
            range.type = "range";
            range.className = "wave-slider";
            range.min = "0";
            range.max = "100";
            range.step = "1";
            range.value = String(value);
            range.disabled = !enabled;
            range.setAttribute("aria-label", `Wave-Stufe ${index + 1}`);
            range.addEventListener("input", () => {
                draft.stages[index] = Number(range.value);
                output.value = `${range.value}%`;
                updateSummary();
            });
            const number = document.createElement("span");
            number.textContent = String(index + 1);
            wrapper.append(output, range, number);
            stages.appendChild(wrapper);
        });
        details.appendChild(stages);
        editor.appendChild(details);
        controls.appendChild(editor);
    }

    applyData(data);
})();
