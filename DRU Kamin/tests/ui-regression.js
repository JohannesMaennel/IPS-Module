window.runDRUUiRegression = async function () {
    const results = [];
    const originalRequestAction = window.requestAction;
    const actions = [];
    window.requestAction = (ident, value) => actions.push([ident, value]);
    const controls = document.getElementById("controls");
    const check = (condition, description) => {
        if (!condition) {
            throw new Error(`FAIL: ${description}`);
        }
        results.push(`PASS: ${description}`);
    };
    const push = (statusRegister, values, overrides = {}) => {
        window.handleMessage(JSON.stringify({
            values,
            features: { temperatureControl: true, wave: true },
            statusAvailable: true,
            statusRegister,
            statusValidForMs: 15000,
            canSetFlameHeight: (statusRegister & 4) !== 0,
            waveSettings: { available: true, interval: 10, stages: Array(20).fill(50) },
            ...overrides
        }));
    };
    try {
        push(15360, { Fireplace: true, Light: false, BoostFan: false });
        check(controls.querySelector("button").textContent === "Hauptbrenner: Aus", "Status 15360 overrides stale ON variable");
        controls.querySelector("button").click();
        check(actions[0][0] === "Fireplace" && actions[0][1] === true, "ON action uses the actual OFF status");
        check(controls.querySelector("button").textContent === "Hauptbrenner: Aus", "Click does not optimistically switch to ON");
        check([...controls.querySelectorAll(".mode-button")].filter(button => button.textContent !== "Manuell").every(button => button.disabled), "Mode switching is unavailable before main burner confirmation");

        push(8196, { Fireplace: false, Light: true, BoostFan: true });
        check(controls.querySelector("button").textContent === "Hauptbrenner: Ein", "JSON string message with bit 2 confirms ON");
        check([...controls.querySelectorAll("button")].some(button => button.textContent === "Kaminlicht: Aus"), "Light uses its own status bit, not its variable");
        check([...controls.querySelectorAll(".mode-button")].some(button => button.textContent === "Temperaturregelung" && !button.disabled), "Temperature mode is enabled after status confirmation");
        check(![...controls.querySelectorAll("button")].some(button => button.textContent.startsWith("Zweiter Brenner")), "Uninstalled second burner remains hidden");
        const previousButton = controls.querySelector("button");
        push(8196, { Fireplace: false, Light: true, BoostFan: true });
        check(previousButton === controls.querySelector("button"), "Unchanged polling messages do not rebuild controls");

        push(12, { Fireplace: true, SecondBurner: false });
        check([...controls.querySelectorAll("button")].some(button => button.textContent === "Zweiter Brenner: Ein"), "Second burner state follows bit 3");
        push(516, { Fireplace: true, Wave: true }, {
            waveSettings: { available: true, interval: 25, stages: Array.from({ length: 20 }, (_, index) => index * 5) }
        });
        check(controls.querySelector(".interval-control input").value === "25"
            && controls.querySelectorAll(".wave-slider").length === 20
            && controls.querySelectorAll(".wave-slider")[19].value === "95", "Wave editor uses synchronized device interval and stages");
        const waveSlider = controls.querySelector(".wave-slider");
        waveSlider.value = "75";
        waveSlider.dispatchEvent(new Event("input"));
        push(516, { Fireplace: true, Wave: true }, {
            waveSettings: { available: true, interval: 25, stages: Array.from({ length: 20 }, (_, index) => index * 5) }
        });
        check(controls.querySelector(".wave-slider") === waveSlider && waveSlider.value === "75", "Unchanged readback preserves unsaved slider edits");
        push(516, { Fireplace: true, Wave: true }, {
            waveSettings: { available: false, interval: 25, stages: Array(20).fill(50) }
        });
        check(!controls.querySelector(".wave-slider") && !controls.querySelector(".wave-save")
            && controls.textContent.includes("aus dem Kamin gelesen"), "Unavailable device pattern is not presented as editable cached data");
        push(12, { Fireplace: true, SecondBurner: true }, { statusAvailable: false, statusValidForMs: 0 });
        check(controls.querySelector("button").textContent === "Hauptbrenner: Unbekannt" && controls.querySelector("button").disabled, "Read failure shows unknown state and disables actions");

        window.handleMessage("invalid JSON");
        push(0, { Fireplace: false });
        check(controls.querySelector("button").textContent === "Hauptbrenner: Aus" && !controls.querySelector("button").disabled, "Valid update recovers after malformed JSON");
        push(4, { Fireplace: true }, { statusValidForMs: 30 });
        await new Promise(resolve => window.setTimeout(resolve, 100));
        check(controls.querySelector("button").textContent === "Hauptbrenner: Unbekannt" && controls.querySelector("button").disabled, "Missing heartbeat expires instead of leaving stale ON");
        return results;
    } finally {
        window.requestAction = originalRequestAction;
    }
};
