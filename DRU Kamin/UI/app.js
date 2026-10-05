(() => {
    const payload = document.getElementById("druData");
    let data = JSON.parse(payload.textContent);
    let values = data.values || {};
    let features = data.features || {};
    let waveSettings = data.waveSettings || { interval: 10, stages: Array(20).fill(50) };
    const controls = document.getElementById("controls");
    const statusLabel = document.getElementById("connectionStatus");
    const temperature = document.getElementById("temperature");
    let status = 0;
    let statusAvailable = false;
    let mainBurnerOn = false;
    let fault = false;
    let ignitionForbidden = false;
    let waveActive = false;
    let temperatureState = 0;
    let temperatureActive = false;
    let operationMode = "manual";

    const toggleLabels = {
        Fireplace: "Hauptbrenner",
        SecondBurner: "Zweiter Brenner",
        Light: "Kaminlicht",
        BoostFan: "Boost-Lüfter"
    };

    function sendAction(ident, value) {
        statusLabel.textContent = "Befehl gesendet – warte auf Rückmeldung";
        requestAction(ident, value);
    }

    function applyData(nextData) {
        data = nextData;
        values = data.values || {};
        features = data.features || {};
        waveSettings = data.waveSettings || waveSettings;
        status = Number(data.statusRegister || 0);
        statusAvailable = Boolean(data.statusAvailable);
        mainBurnerOn = (status & (1 << 2)) !== 0;
        fault = (status & 1) !== 0;
        ignitionForbidden = (status & (1 << 15)) !== 0;
        waveActive = (status & (1 << 9)) !== 0;
        temperatureState = (status >> 13) & 0b11;
        temperatureActive = temperatureState === 0b10;
        operationMode = temperatureActive
            ? "temperature"
            : waveActive
                ? "wave"
                : "manual";

        statusLabel.classList.remove("error");
        if (data.message) {
            statusLabel.textContent = data.message;
        } else if (!statusAvailable) {
            statusLabel.textContent = "Kein aktueller Modbus-Status – Steuerung gesperrt";
            statusLabel.classList.add("error");
        } else if (fault) {
            statusLabel.textContent = "Kaminfehler erkannt";
            statusLabel.classList.add("error");
        } else {
            statusLabel.textContent = "Verbunden";
        }

        temperature.textContent = values.RoomTemperature === undefined
            ? ""
            : `${Number(values.RoomTemperature).toFixed(1)} °C`;
        render();
    }

    window.handleMessage = applyData;

    function render() {
        controls.replaceChildren();

        Object.entries(toggleLabels).forEach(([ident, label]) => {
            if (values[ident] === undefined) {
                return;
            }

            const isOn = Boolean(values[ident]);
            const button = document.createElement("button");
            button.className = "control";
            button.type = "button";
            button.setAttribute("aria-pressed", String(isOn));
            button.textContent = `${label}: ${isOn ? "Ein" : "Aus"}`;

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
            controls.appendChild(button);
        });

        const modeSection = document.createElement("section");
        modeSection.className = "mode-section";
        const modeTitle = document.createElement("h2");
        modeTitle.textContent = "Betriebsart";
        modeSection.appendChild(modeTitle);

        [
            ["manual", "Manuell", true],
            ["temperature", "Temperaturregelung", Boolean(features.temperatureControl)],
            ["wave", "Wave", Boolean(features.wave)]
        ].forEach(([mode, label, available]) => {
            if (!available) {
                return;
            }

            const button = document.createElement("button");
            button.className = "control mode-button";
            button.type = "button";
            button.textContent = label;
            button.setAttribute("aria-pressed", String(operationMode === mode));
            const selected = operationMode === mode;
            let allowed = statusAvailable && !selected;
            if (mode === "temperature") {
                allowed = allowed && !fault && temperatureState !== 0b00
                    && temperatureState !== 0b11 && (mainBurnerOn || temperatureActive);
            } else if (mode === "wave") {
                allowed = allowed && !fault && mainBurnerOn;
            }
            button.disabled = !allowed;
            if (!statusAvailable) {
                button.title = "Auf einen aktuellen Gerätestatus warten.";
            } else if (mode !== "manual" && !mainBurnerOn && !selected) {
                button.title = "Der Modus kann erst bei eingeschaltetem Hauptbrenner gewählt werden.";
            }
            button.addEventListener("click", () => sendAction("OperationMode", mode));
            modeSection.appendChild(button);
        });
        controls.appendChild(modeSection);

        if (operationMode === "manual" && values.FlameHeight !== undefined) {
            const wrapper = document.createElement("label");
            wrapper.className = "range-control";
            wrapper.textContent = "Flammenhöhe / Leistung";

            const range = document.createElement("input");
            range.type = "range";
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

            wrapper.append(range, output);
            controls.appendChild(wrapper);
        }

        if (operationMode === "temperature" && values.TemperatureSetpoint !== undefined) {
            const wrapper = document.createElement("label");
            wrapper.className = "range-control";
            wrapper.textContent = "Solltemperatur";

            const range = document.createElement("input");
            range.type = "range";
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

            wrapper.append(range, output);
            controls.appendChild(wrapper);
        }

        if (operationMode === "wave" && features.wave) {
            renderWaveEditor();
        }
    }

    function renderWaveEditor() {
        const editor = document.createElement("section");
        editor.className = "wave-editor";

        const heading = document.createElement("h2");
        heading.textContent = "Wave-Muster";
        editor.appendChild(heading);

        const enabled = statusAvailable && mainBurnerOn && waveActive && !fault;
        const intervalLabel = document.createElement("label");
        intervalLabel.className = "interval-control";
        intervalLabel.textContent = "Intervall zwischen den Stufen";

        const interval = document.createElement("input");
        interval.type = "range";
        interval.min = "5";
        interval.max = "60";
        interval.step = "1";
        interval.value = String(waveSettings.interval);
        interval.disabled = !enabled;

        const intervalOutput = document.createElement("output");
        intervalOutput.value = `${interval.value} s`;
        interval.addEventListener("input", () => {
            intervalOutput.value = `${interval.value} s`;
        });
        intervalLabel.append(interval, intervalOutput);
        editor.appendChild(intervalLabel);

        const stages = document.createElement("div");
        stages.className = "wave-stages";
        const stageValues = Array.from({ length: 20 }, (_, index) =>
            Number(waveSettings.stages[index] ?? 50)
        );

        stageValues.forEach((value, index) => {
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
                output.value = `${range.value}%`;
                stageValues[index] = Number(range.value);
            });

            const label = document.createElement("span");
            label.textContent = String(index + 1);
            wrapper.append(output, range, label);
            stages.appendChild(wrapper);
        });
        editor.appendChild(stages);

        const save = document.createElement("button");
        save.className = "control wave-save";
        save.type = "button";
        save.textContent = "Wave-Muster speichern";
        save.disabled = !enabled;
        save.addEventListener("click", () => {
            save.disabled = true;
            sendAction("SaveWaveSettings", JSON.stringify({
                interval: Number(interval.value),
                stages: stageValues
            }));
            window.setTimeout(() => {
                save.disabled = !enabled;
            }, 2000);
        });
        editor.appendChild(save);
        controls.appendChild(editor);
    }

    applyData(data);
})();
