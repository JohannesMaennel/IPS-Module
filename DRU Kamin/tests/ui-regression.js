window.runDRUUiRegression = async function () {
    const results = [];
    const originalRequestAction = window.requestAction;
    const actions = [];
    window.requestAction = (ident, value) => actions.push([ident, value]);
    const controls = document.querySelector(".dru");
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
        check(controls.querySelector(".fire-button").dataset.state === "locked", "Status 15360 overrides stale ON variable with locked start");
        controls.querySelector(".fire-button").click();
        check(actions.length === 0, "Simple click on locked flame cannot ignite");
        check(controls.querySelector(".mode-select").disabled, "Mode selection stays disabled before ignition");
        const shortHold = controls.querySelector(".fire-button");
        shortHold.dispatchEvent(new KeyboardEvent("keydown", { key: "Enter" }));
        await new Promise(resolve => window.setTimeout(resolve, 50));
        shortHold.dispatchEvent(new KeyboardEvent("keyup", { key: "Enter" }));
        check(shortHold.dataset.state === "locked" && actions.length === 0, "Short hold is canceled without unlocking or starting");
        shortHold.dispatchEvent(new KeyboardEvent("keydown", { key: "Enter" }));
        await new Promise(resolve => window.setTimeout(resolve, 3100));
        check(shortHold.dataset.state === "ready" && actions.length === 0, "Three-second hold unlocks without sending ignition");
        shortHold.dispatchEvent(new KeyboardEvent("keyup", { key: "Enter" }));
        check(actions.length === 0, "Release after unlocking cannot trigger ignition");
        shortHold.click();
        check(actions.at(-1)[0] === "Fireplace" && actions.at(-1)[1] === true
            && controls.querySelector(".fire-button").dataset.state === "igniting", "Separate click starts ignition animation");
        check(!controls.querySelector(".fire-toolbar") && controls.querySelector(".mode-select").disabled, "Ignition animation never presents confirmed operation");
        const ignitionButton = controls.querySelector(".fire-button");
        push(15360, { Fireplace: true, Light: false, BoostFan: false });
        check(controls.querySelector(".fire-button") === ignitionButton, "Status heartbeat preserves ignition animation");
        ignitionButton.click();
        check(actions.length === 1, "Repeated click while igniting sends no duplicate command");

        push(8196, { Fireplace: false, Light: true, BoostFan: true });
        check(controls.querySelector("header .fire-toolbar .fire-button").dataset.state === "running"
            && !controls.querySelector(".fire-toolbar .fire-label")
            && controls.querySelector(".fire-toolbar .fire-button").getAttribute("aria-pressed") === "true",
            "Only bit 2 confirms active icon-only OFF flame in status bar");
        check(controls.querySelector(".mode-icon")
            && controls.querySelector('.mode-select option[value="manual"]').textContent === "\u270B\uFE0E Manuell"
            && controls.querySelector('.mode-select option[value="temperature"]').textContent === "\u{1F321}\uFE0E Temp."
            && controls.querySelector('.mode-select option[value="wave"]').textContent === "≋ Wave",
            "Mode dropdown retains SVG and icons in all native options");
        controls.querySelector(".fire-button").click();
        check(actions.at(-1)[0] === "Fireplace" && actions.at(-1)[1] === false, "Small running flame sends OFF without hold");
        check(controls.querySelector('header [data-ident="Light"]').getAttribute("aria-label") === "Kaminlicht: Aus", "Light uses its own status bit in top status bar");
        check(!controls.querySelector('.mode-select option[value="temperature"]').disabled, "Temperature mode is enabled after status confirmation");
        check(controls.querySelector(".mode-select").value === "manual"
            && !controls.querySelector(".mode-button"), "One mode dropdown replaces separate buttons");
        const modeSelect = controls.querySelector(".mode-select");
        modeSelect.value = "temperature";
        modeSelect.dispatchEvent(new Event("change"));
        check(actions.at(-1)[0] === "OperationMode" && actions.at(-1)[1] === "temperature"
            && modeSelect.value === "manual", "Mode request waits for device status before changing selection");
        check(!controls.querySelector(".second-burner"), "Uninstalled second burner remains hidden");
        const previousButton = controls.querySelector("button");
        push(8196, { Fireplace: false, Light: true, BoostFan: true });
        check(previousButton === controls.querySelector("button"), "Unchanged polling messages do not rebuild controls");

        push(12, { Fireplace: true, SecondBurner: false });
        check(controls.querySelector(".fire-toolbar .second-burner").getAttribute("aria-pressed") === "true"
            && controls.querySelector(".second-burner").getAttribute("aria-label") === "Zweiter Brenner: Ein", "Second burner state follows bit 3 in top-right mini flame");
        push(516, { Fireplace: true, Wave: true }, {
            waveSettings: { available: true, interval: 25, stages: Array.from({ length: 20 }, (_, index) => index * 5) }
        });
        check(controls.querySelector(".wave-summary").textContent.includes("25 s")
            && controls.querySelector(".wave-summary").textContent.includes("0–95")
            && !controls.querySelector(".wave-slider"), "Compact Wave view summarizes synchronized profile without detail sliders");
        check(!controls.querySelector(".wave-editor h2") && !controls.querySelector(".wave-current")
            && controls.querySelector(".wave-save").disabled, "Collapsed Wave profile keeps save disabled without duplicate labels or load button");
        if (window.druWavePresets) {
            const beforePreset = actions.length;
            const choosePreset = id => {
                const select = controls.querySelector(".wave-profile-select");
                select.value = id;
                select.dispatchEvent(new Event("change"));
            };
            choosePreset("even");
            check(controls.querySelector(".wave-summary").textContent.includes("10 s")
                && controls.querySelector(".wave-summary").textContent.includes("50–100"), "Even preset summarizes range and interval");
            choosePreset("gentle");
            check(controls.querySelector(".wave-summary").textContent.includes("20 s")
                && controls.querySelector(".wave-summary").textContent.includes("20–60"), "Gentle preset summarizes range and interval");
            choosePreset("strong");
            check(controls.querySelector(".wave-summary").textContent.includes("10–100"), "Strong preset spans 10-100 percent");
            const draftSelect = controls.querySelector(".wave-profile-select");
            push(516, { Fireplace: true, Wave: true }, {
                waveSettings: { available: true, interval: 25, stages: Array.from({ length: 20 }, (_, index) => index * 5) }
            });
            check(controls.querySelector(".wave-profile-select") === draftSelect && draftSelect.value === "strong",
                "Identical readback preserves draft profile");
            check(actions.length === beforePreset, "Preset selection never writes to device");
            controls.querySelector(".wave-edit").click();
            check(controls.querySelectorAll(".wave-slider").length === 20
                && controls.querySelector(".interval-control input").value === "10"
                && controls.querySelectorAll(".wave-slider")[5].value === "100",
                "Pencil expands twenty sliders and interval from selected JSON preset");
            const edited = controls.querySelector(".wave-slider");
            edited.value = "25";
            edited.dispatchEvent(new Event("input"));
            push(516, { Fireplace: true, Wave: true }, {
                waveSettings: { available: true, interval: 25, stages: Array.from({ length: 20 }, (_, index) => index * 5) }
            });
            check(controls.querySelector(".wave-slider") === edited && edited.value === "25",
                "Heartbeat preserves expanded editor and unsaved slider value");
            check(controls.querySelector(".wave-save").textContent === ""
                && controls.querySelector(".wave-save svg") && !controls.querySelector(".wave-save").disabled
                && controls.querySelector(".wave-edit").nextElementSibling === controls.querySelector(".wave-save"),
                "Enabled diskette is next to pencil only while editing");
            controls.querySelector(".wave-save").click();
            const saved = JSON.parse(actions.at(-1)[1]);
            check(actions.at(-1)[0] === "SaveWaveSettings" && saved.interval === 10
                && saved.stages.length === 20 && saved.stages[0] === 25 && saved.stages[5] === 100,
                "Explicit save sends selected preset with twenty stages");
            check(!controls.querySelector(".wave-slider") && controls.querySelector(".wave-save").disabled,
                "Saving collapses Wave details immediately and disables diskette");
            choosePreset("current");
            check(actions.at(-1)[0] === "ReloadWaveSettings"
                && controls.querySelector(".wave-summary").textContent.includes("25 s")
                && controls.querySelector(".wave-summary").textContent.includes("0–95"), "Load current restores device profile and requests fresh readback");
            controls.querySelector(".wave-edit").click();
            check(controls.querySelector(".interval-control input").value === "25"
                && controls.querySelectorAll(".wave-slider")[19].value === "95",
                "Editing current profile loads synchronized device data");
            controls.querySelector(".wave-edit").click();
        }
        push(516, { Fireplace: true, Wave: true }, {
            waveSettings: { available: false, interval: 25, stages: Array(20).fill(50) }
        });
        check(!controls.querySelector(".wave-slider") && !controls.querySelector(".wave-save")
            && controls.textContent.includes("aus dem Kamin gelesen"), "Unavailable device pattern is not presented as editable cached data");
        push(12, { Fireplace: true, SecondBurner: true }, { statusAvailable: false, statusValidForMs: 0 });
        check(controls.querySelector(".fire-button").disabled && !controls.querySelector(".fire-toolbar"), "Read failure removes running display and disables start");

        push(0, { Fireplace: false });
        check(!controls.querySelector(".reset-button"), "No reset button without Fault");
        push(1, { Fireplace: false });
        check(controls.querySelector(".reset-button").disabled, "Fault without reset permission shows disabled reset");
        push(129, { Fireplace: false });
        check(controls.querySelector(".reset-button").disabled, "Boost bit does not authorize reset");
        push(65, { Fireplace: false });
        check(!controls.querySelector(".reset-button").disabled, "Fault with bit 6 enables reset");
        const actionCount = actions.length;
        controls.querySelector(".reset-button").click();
        check(actions.length === actionCount + 1 && actions.at(-1)[0] === "ResetFireplace"
            && controls.querySelector(".reset-button").disabled, "Reset click immediately locks button and sends action");
        controls.querySelector(".reset-button").click();
        check(actions.length === actionCount + 1, "Locked reset cannot be clicked twice");
        push(65, { Fireplace: false }, { resetPending: true });
        check(controls.querySelector(".reset-button").disabled, "Pending reset stays locked while Fault persists");
        push(65, { Fireplace: false }, { resetPending: false });
        check(!controls.querySelector(".reset-button").disabled, "Timeout or write failure restores reset permission");
        push(1, { Fireplace: false }, { resetPending: false });
        check(controls.querySelector(".reset-button").disabled, "Revoked reset permission prevents retry");
        push(65, { Fireplace: false }, { statusAvailable: false });
        check(controls.querySelector(".reset-button").disabled, "Stale Fault cannot authorize reset");
        push(0, { Fireplace: false }, { resetPending: false });
        check(!controls.querySelector(".reset-button"), "Confirmed Fault clearance removes reset button");

        window.handleMessage("invalid JSON");
        push(0, { Fireplace: false });
        check(controls.querySelector(".fire-button").dataset.state === "locked" && !controls.querySelector(".fire-button").disabled, "Valid update recovers locked after malformed JSON");
        push(4, { Fireplace: true }, { statusValidForMs: 30 });
        await new Promise(resolve => window.setTimeout(resolve, 100));
        check(controls.querySelector(".fire-button").disabled && !controls.querySelector(".fire-toolbar"), "Missing heartbeat expires instead of leaving stale ON");
        push(0, { Fireplace: false });
        const pointerButton = controls.querySelector(".fire-button");
        pointerButton.dispatchEvent(new PointerEvent("pointerdown", { button: 0, isPrimary: true }));
        await new Promise(resolve => window.setTimeout(resolve, 2900));
        check(pointerButton.dataset.state === "locked", "Pointer hold remains locked before three-second threshold");
        await new Promise(resolve => window.setTimeout(resolve, 220));
        check(pointerButton.dataset.state === "ready", "Primary pointer hold unlocks after three seconds");
        pointerButton.dispatchEvent(new PointerEvent("pointerup", { button: 0, isPrimary: true }));
        const beforePointerStart = actions.length;
        pointerButton.click();
        check(actions.length === beforePointerStart, "Pointer release click is consumed after unlocking");
        window.dispatchEvent(new Event("blur"));
        check(controls.querySelector(".fire-button").dataset.state === "locked", "Window focus loss revokes unlock");
        push(0, { Fireplace: false });
        const cancelledButton = controls.querySelector(".fire-button");
        cancelledButton.dispatchEvent(new PointerEvent("pointerdown", { button: 0, isPrimary: true }));
        cancelledButton.dispatchEvent(new PointerEvent("pointercancel", { isPrimary: true }));
        check(controls.querySelector(".fire-button").dataset.state === "locked", "Canceled touch returns to locked start");
        push(16384, { Fireplace: false, TemperatureSetpoint: 20 });
        check(controls.querySelector(".mode-section") && controls.querySelector(".range-control")
            && controls.querySelector(".fire-button").disabled, "Autonomous temperature controls remain accessible with burner off");
        check(controls.querySelector("header .temperature-active")
            && controls.querySelector(".temperature-target").textContent.includes("20.0"),
            "Active temperature mode shows indicator and target in status bar with burner off");
        check(getComputedStyle(controls.querySelector(".temperature-slider")).writingMode === "horizontal-tb",
            "Target temperature slider is horizontal");
        check(getComputedStyle(controls.querySelector(".range-control")).textAlign === "center",
            "Temperature label and value are centered");
        push(4, { Fireplace: true, FlameHeight: 60 });
        check(getComputedStyle(controls.querySelector(".range-control")).textAlign === "center",
            "Manual power label and value are centered");
        check(controls.querySelector("footer #connectionStatus"), "Connection status is in tile footer");
        push(65, { Fireplace: false });
        check(controls.querySelector(".reset-button") && controls.querySelector(".fire-button").disabled, "Fault blocks start but preserves reset");
        const originalTimeout = window.setTimeout;
        const originalAnimationFrame = window.requestAnimationFrame;
        const originalCancelAnimationFrame = window.cancelAnimationFrame;
        const originalNow = performance.now.bind(performance);
        const nowDescriptor = Object.getOwnPropertyDescriptor(performance, "now");
        let timeOffset = 0;
        let timeoutCallback;
        let timeoutId;
        let animationCallback;
        try {
            window.requestAnimationFrame = callback => { animationCallback = callback; return 1; };
            window.cancelAnimationFrame = () => { animationCallback = undefined; };
            window.setTimeout = (callback, delay, ...args) => {
                const id = originalTimeout(callback, delay, ...args);
                if (delay === 30000) {
                    timeoutCallback = callback;
                    timeoutId = id;
                }
                return id;
            };
            Object.defineProperty(performance, "now", { configurable: true, value: () => originalNow() + timeOffset });
            push(0, { Fireplace: false }, { statusValidForMs: 60000 });
            const fireButton = controls.querySelector(".fire-button");
            fireButton.dispatchEvent(new KeyboardEvent("keydown", { key: " " }));
            await new Promise(resolve => originalTimeout(resolve, 3100));
            fireButton.dispatchEvent(new KeyboardEvent("keyup", { key: " " }));
            fireButton.click();
            timeOffset = 5000;
            animationCallback();
            const halfFill = parseFloat(controls.querySelector(".fire-button").style.getPropertyValue("--fire-fill"));
            check(halfFill >= 44 && halfFill < 50, "Five seconds colors approximately half the flame from below");
            timeOffset = 10000;
            animationCallback();
            check(controls.querySelector(".fire-button").style.getPropertyValue("--fire-fill") === "90%"
                && !controls.querySelector(".fire-toolbar"), "Ten-second animation stops below full operation without bit 2");
            push(32768, { Fireplace: false }, { statusValidForMs: 60000 });
            check(controls.querySelector(".fire-button").dataset.state === "igniting", "Temporary ignition lock bit does not cancel active ignition display");
            push(0, { Fireplace: false }, { statusValidForMs: 60000 });
            window.clearTimeout(timeoutId);
            timeoutCallback();
            check(controls.querySelector(".fire-button").dataset.state === "locked" && controls.querySelector(".mode-select").disabled,
                "Thirty-second confirmation timeout relocks without claiming operation");
        } finally {
            window.setTimeout = originalTimeout;
            window.requestAnimationFrame = originalAnimationFrame;
            window.cancelAnimationFrame = originalCancelAnimationFrame;
            if (nowDescriptor) {
                Object.defineProperty(performance, "now", nowDescriptor);
            } else {
                delete performance.now;
            }
        }
        return results;
    } finally {
        window.requestAction = originalRequestAction;
    }
};
