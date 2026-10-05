(() => {
    const payload = document.getElementById("druData");
    const data = JSON.parse(payload.textContent);
    const values = data.values || {};
    const features = data.features || {};
    const waveSettings = data.waveSettings || { interval: 10, stages: Array(20).fill(50) };
    const controls = document.getElementById("controls");
    const status = document.getElementById("connectionStatus");
    const temperature = document.getElementById("temperature");

    const toggleLabels = {
        Fireplace: "Hauptbrenner",
        SecondBurner: "Zweiter Brenner",
        Light: "Kaminlicht",
        BoostFan: "Boost-Lüfter"
    };
    let operationMode = values.TemperatureControl
        ? "temperature"
        : values.Wave
            ? "wave"
            : "manual";

    const issues = [];
    if (data.communicationError) {
        issues.push(data.communicationError);
    }
    if (values.FireplaceFault) {
        issues.push("Kaminfehler erkannt");
    }
    status.textContent = issues.join(" · ") || "Verbunden";
    if (issues.length > 0) {
        status.classList.add("error");
    }

    if (values.RoomTemperature !== undefined) {
        temperature.textContent = `${Number(values.RoomTemperature).toFixed(1)} °C`;
    }

    function render() {
        controls.replaceChildren();

        Object.entries(toggleLabels).forEach(([ident, label]) => {
            if (values[ident] === undefined) {
                return;
            }

            const button = document.createElement("button");
            button.className = "control";
            button.type = "button";
            button.setAttribute("aria-pressed", String(Boolean(values[ident])));
            button.textContent = `${label}: ${values[ident] ? "Ein" : "Aus"}`;
            if (ident === "Fireplace" && operationMode === "temperature") {
                button.disabled = true;
                button.title = "Der Kamin regelt den Hauptbrenner im Temperaturmodus selbst.";
                controls.appendChild(button);
                return;
            }
            button.addEventListener("click", () => {
                const nextValue = !Boolean(values[ident]);
                requestAction(ident, nextValue);
                values[ident] = nextValue;
                render();
            });
            controls.appendChild(button);
        });

        if (!values.Fireplace && operationMode !== "temperature" && operationMode !== "wave") {
            return;
        }

        const modeSection = document.createElement("section");
        modeSection.className = "mode-section";
        const modeTitle = document.createElement("h2");
        modeTitle.textContent = "Betriebsart";
        modeSection.appendChild(modeTitle);

        const modeButtons = [
            ["manual", "Manuell", true],
            ["temperature", "Temperaturregelung", Boolean(features.temperatureControl)],
            ["wave", "Wave", Boolean(features.wave)]
        ];
        modeButtons.forEach(([mode, label, available]) => {
            if (!available) {
                return;
            }

            const button = document.createElement("button");
            button.className = "control mode-button";
            button.type = "button";
            button.textContent = label;
            button.setAttribute("aria-pressed", String(operationMode === mode));
            button.addEventListener("click", () => {
                requestAction("OperationMode", mode);
                operationMode = mode;
                values.TemperatureControl = mode === "temperature";
                values.Wave = mode === "wave";
                render();
            });
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

            const output = document.createElement("output");
            output.value = `${range.value} %`;
            range.addEventListener("input", () => {
                output.value = `${range.value} %`;
            });
            range.addEventListener("change", () => {
                requestAction("FlameHeight", Number(range.value));
                values.FlameHeight = Number(range.value);
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

            const output = document.createElement("output");
            output.value = `${Number(range.value).toFixed(1)} °C`;
            range.addEventListener("input", () => {
                output.value = `${Number(range.value).toFixed(1)} °C`;
            });
            range.addEventListener("change", () => {
                requestAction("TemperatureSetpoint", Number(range.value));
                values.TemperatureSetpoint = Number(range.value);
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

        const intervalLabel = document.createElement("label");
        intervalLabel.className = "interval-control";
        intervalLabel.textContent = "Intervall zwischen den Stufen";

        const interval = document.createElement("input");
        interval.type = "range";
        interval.min = "5";
        interval.max = "60";
        interval.step = "1";
        interval.value = String(waveSettings.interval);

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
        save.addEventListener("click", () => {
            save.disabled = true;
            save.textContent = "Speichere …";
            requestAction("SaveWaveSettings", JSON.stringify({
                interval: Number(interval.value),
                stages: stageValues
            }));
            waveSettings.interval = Number(interval.value);
            waveSettings.stages = stageValues.map(value =>
                Math.round((Math.round(value * 14 / 100)) * 100 / 14)
            );
            save.textContent = "Speicherauftrag gesendet";
            window.setTimeout(() => {
                save.disabled = false;
                save.textContent = "Wave-Muster speichern";
            }, 2000);
        });
        editor.appendChild(save);
        controls.appendChild(editor);
    }

    render();
})();
