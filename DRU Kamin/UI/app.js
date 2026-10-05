(() => {
    const payload = document.getElementById("druData");
    const data = JSON.parse(payload.textContent);
    const values = data.values || {};
    const controls = document.getElementById("controls");
    const status = document.getElementById("connectionStatus");
    const temperature = document.getElementById("temperature");

    const labels = {
        Fireplace: "Hauptbrenner",
        SecondBurner: "Zweiter Brenner",
        Light: "Kaminlicht",
        BoostFan: "Boost-Lüfter",
        Wave: "Wave",
        TemperatureControl: "Temperaturregelung"
    };

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

    Object.entries(labels).forEach(([ident, label]) => {
        if (values[ident] === undefined) {
            return;
        }

        const button = document.createElement("button");
        button.className = "control";
        button.type = "button";
        button.setAttribute("aria-pressed", String(Boolean(values[ident])));
        button.textContent = `${label}: ${values[ident] ? "Ein" : "Aus"}`;

        if (ident === "Wave" && values.TemperatureControl) {
            button.disabled = true;
            button.title = "Wave ist bei aktivierter Temperaturregelung nicht verfuegbar.";
        } else {
            button.addEventListener("click", () => {
                const nextValue = !Boolean(values[ident]);
                requestAction(ident, nextValue);
                values[ident] = nextValue;
                button.setAttribute("aria-pressed", String(nextValue));
                button.textContent = `${label}: ${nextValue ? "Ein" : "Aus"}`;
            });
        }

        controls.appendChild(button);
    });

    if (values.FlameHeight !== undefined) {
        const wrapper = document.createElement("label");
        wrapper.className = "range-control";
        wrapper.textContent = "Flammenhoehe";

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
        });

        wrapper.append(range, output);
        controls.appendChild(wrapper);
    }

    if (values.TemperatureSetpoint !== undefined) {
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
        });

        wrapper.append(range, output);
        controls.appendChild(wrapper);
    }
})();
