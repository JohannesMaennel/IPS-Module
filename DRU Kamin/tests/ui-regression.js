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
            actionPending: false,
            actionError: "",
            waveResult: "idle",
            waveSettings: { available: true, interval: 10, stages: Array(20).fill(50) },
            offTimer: { state: "idle", duration: 3600, remaining: 0, deadline: 0, serverTime: Math.floor(Date.now() / 1000), error: "" },
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
            let currentWave = { available: true, interval: 25, stages: Array.from({ length: 20 }, (_, index) => index * 5) };
            const confirmWave = settings => {
                push(516, { Fireplace: true, Wave: true }, {
                    actionPending: true, pendingAction: "WaveSave", waveResult: "pending", waveSettings: currentWave
                });
                currentWave = { available: true, interval: settings.interval,
                    stages: settings.stages.map(stage => Math.round(Math.round(stage * 14 / 100) * 100 / 14)) };
                push(516, { Fireplace: true, Wave: true }, { waveResult: "applied", waveSettings: currentWave });
            };
            const choosePreset = id => {
                const select = controls.querySelector(".wave-profile-select");
                select.value = id;
                select.dispatchEvent(new Event("change"));
            };
            choosePreset("even");
            check(controls.querySelector(".wave-summary").textContent.includes("10 s")
                && controls.querySelector(".wave-summary").textContent.includes("50–100"), "Even preset summarizes range and interval");
            check(actions.at(-1)[0] === "SaveWaveSettings" && controls.querySelector(".wave-profile-select").disabled,
                "Selecting a preset transfers immediately and blocks duplicate selection pending confirmation");
            confirmWave(JSON.parse(actions.at(-1)[1]));
            choosePreset("gentle");
            check(controls.querySelector(".wave-summary").textContent.includes("20 s")
                && controls.querySelector(".wave-summary").textContent.includes("20–60"), "Gentle preset summarizes range and interval");
            confirmWave(JSON.parse(actions.at(-1)[1]));
            choosePreset("strong");
            check(controls.querySelector(".wave-summary").textContent.includes("10–100"), "Strong preset spans 10-100 percent");
            confirmWave(JSON.parse(actions.at(-1)[1]));
            const draftSelect = controls.querySelector(".wave-profile-select");
            push(516, { Fireplace: true, Wave: true }, {
                waveResult: "applied", waveSettings: currentWave
            });
            check(controls.querySelector(".wave-profile-select") === draftSelect && draftSelect.value === "strong",
                "Identical readback preserves draft profile");
            check(actions.length === beforePreset + 3, "Each preset selection sends exactly one transfer without save click");
            controls.querySelector(".wave-edit").click();
            check(controls.querySelectorAll(".wave-slider").length === 20
                && controls.querySelector(".interval-control input").value === "10"
                && controls.querySelectorAll(".wave-slider")[5].value === "100",
                "Pencil expands twenty sliders and interval from selected JSON preset");
            const edited = controls.querySelector(".wave-slider");
            edited.value = "25";
            edited.dispatchEvent(new Event("input"));
            push(516, { Fireplace: true, Wave: true }, {
                waveResult: "applied", waveSettings: currentWave
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
            confirmWave(saved);
            choosePreset("current");
            check(actions.at(-1)[0] === "ReloadWaveSettings"
                && controls.querySelector(".wave-summary").textContent.includes("10 s"),
                "Load current only requests readback of the newly applied device profile");
            controls.querySelector(".wave-edit").click();
            check(controls.querySelector(".interval-control input").value === "10"
                && controls.querySelectorAll(".wave-slider")[0].value === String(currentWave.stages[0]),
                "Editing current profile loads synchronized device data");
            controls.querySelector(".wave-edit").click();
            choosePreset("even");
            push(516, { Fireplace: true, Wave: true }, {
                waveSettings: currentWave, waveResult: "failed", actionError: "Wave-Schreiben abgelehnt"
            });
            check(controls.querySelector(".wave-profile-select").value === "current"
                && !controls.querySelector(".wave-profile-select").disabled
                && controls.querySelector("#connectionStatus").textContent === "Wave-Schreiben abgelehnt",
                "Rejected preset restores actual profile and visibly reports failure");
            choosePreset("even");
            const resumeSettings = JSON.parse(actions.at(-1)[1]);
            push(516, { Fireplace: true, Wave: true }, {
                waveSettings: currentWave, waveResult: "applied", waveRequestId: "another-client"
            });
            check(controls.querySelector(".wave-profile-select").disabled,
                "Another client's Wave result cannot confirm this tile's transfer");
            currentWave = { available: true, interval: resumeSettings.interval,
                stages: resumeSettings.stages.map(stage => Math.round(Math.round(stage * 14 / 100) * 100 / 14)) };
            push(516, { Fireplace: true, Wave: true }, {
                waveSettings: currentWave, waveResult: "applied", waveRequestId: resumeSettings.requestId
            });
            check(!controls.querySelector(".wave-profile-select").disabled
                && controls.querySelector(".wave-profile-select").value === "even",
                "Correlated final result confirms preset even when suspended tile missed pending heartbeat");
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
        push(0, { Fireplace: false, RoomTemperature: 20 });
        const noReleaseClickButton = controls.querySelector(".fire-button");
        noReleaseClickButton.dispatchEvent(new PointerEvent("pointerdown", { button: 0, isPrimary: true }));
        await new Promise(resolve => window.setTimeout(resolve, 100));
        push(0, { Fireplace: false, RoomTemperature: 21 });
        check(controls.querySelector(".fire-button") === noReleaseClickButton,
            "Temperature heartbeat during hold preserves the original touch target");
        await new Promise(resolve => window.setTimeout(resolve, 3000));
        noReleaseClickButton.dispatchEvent(new PointerEvent("pointerup", { button: 0, isPrimary: true }));
        const beforeSeparateTap = actions.length;
        check(noReleaseClickButton.dataset.state === "ready" && actions.length === beforeSeparateTap,
            "Long hold without synthetic release click unlocks but never ignites");
        noReleaseClickButton.dispatchEvent(new PointerEvent("pointerdown", { button: 0, isPrimary: true }));
        noReleaseClickButton.dispatchEvent(new PointerEvent("pointerup", { button: 0, isPrimary: true }));
        noReleaseClickButton.click();
        check(actions.length === beforeSeparateTap + 1 && actions.at(-1)[0] === "Fireplace" && actions.at(-1)[1] === true,
            "First new tap after hold ignites even when iOS omitted the release click");
        push(0, { Fireplace: false }, { actionPending: true, pendingAction: "Ignition" });
        check(controls.querySelector(".fire-button").disabled, "Server-side ignition pending blocks duplicate start while polling remains fresh");
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

        const timerPayload = (state, extra = {}) => ({ state, duration: 3600, remaining: 120,
            deadline: Math.floor(Date.now() / 1000) + 120, serverTime: Math.floor(Date.now() / 1000), error: "", ...extra });
        controls.querySelector(".timer-button").click();
        const timerDialog = controls.querySelector(".timer-dialog");
        const hours = timerDialog.querySelector("#timerHours");
        const minutes = timerDialog.querySelector("#timerMinutes");
        const active = timerDialog.querySelector("#timerActive");
        const resetTimer = timerDialog.querySelector('[data-timer-action="reset"]');
        check(hours.value === "1" && minutes.value === "0", "Timer dialog initially selects one hour");
        check(hours.tagName === "SELECT" && minutes.tagName === "SELECT" && hours.options.length === 25 && minutes.options.length === 60,
            "Timer uses native touch selection for all hour/minute values without keyboard fields");
        check(document.activeElement === hours && controls.querySelector("#controls").inert,
            "Timer dialog receives focus and isolates underlying controls");
        hours.value = "24";
        minutes.value = "1";
        minutes.dispatchEvent(new Event("input"));
        check(active.disabled && resetTimer.disabled, "Timer cannot exceed 24 hours");
        hours.value = "0";
        minutes.value = "0";
        minutes.dispatchEvent(new Event("input"));
        check(active.disabled && resetTimer.disabled, "Zero timer duration cannot start");
        minutes.value = "1";
        minutes.dispatchEvent(new Event("input"));
        check(!active.disabled && !resetTimer.disabled, "One minute is a valid timer duration");
        const beforeTimer = actions.length;
        active.checked = true;
        active.dispatchEvent(new Event("change"));
        const startAction = JSON.parse(actions.at(-1)[1]);
        check(actions.length === beforeTimer + 1 && actions.at(-1)[0] === "OffTimerAction"
            && startAction.action === "start" && startAction.duration === 60,
            "Activation sends explicit duration to server, not fireplace ignition");
        resetTimer.click();
        check(actions.length === beforeTimer + 1, "Pending timer action prevents duplicate clicks");
        push(4, { Fireplace: true, FlameHeight: 60 }, { offTimer: timerPayload("running") });
        check(controls.querySelector(".timer-dialog") === timerDialog && hours.value === "0" && minutes.value === "1",
            "Timer heartbeat preserves open dialog and duration draft");
        check(active.checked && controls.querySelector(".timer-countdown").textContent.endsWith("02:00"),
            "Running countdown uses server deadline rather than draft duration");
        hours.value = "24";
        minutes.value = "0";
        minutes.dispatchEvent(new Event("input"));
        check(!resetTimer.disabled, "24 hours exactly is a valid timer reset duration");
        minutes.value = "12";
        hours.dispatchEvent(new Event("change"));
        check(minutes.value === "0" && minutes.options[1].disabled,
            "Selecting 24 hours restricts minutes to zero");
        push(4, { Fireplace: true }, { statusAvailable: false, offTimer: timerPayload("running") });
        check(!active.disabled && !timerDialog.querySelector('[data-timer-action="pause"]').disabled
            && !timerDialog.querySelector('[data-timer-action="delete"]').disabled && resetTimer.disabled,
            "Offline timer allows pause/delete but not reset");
        timerDialog.querySelector('[data-timer-action="pause"]').click();
        check(JSON.parse(actions.at(-1)[1]).action === "pause", "Stopp requests pause only");
        push(4, { Fireplace: true }, { statusAvailable: false, offTimer: timerPayload("paused", { deadline: 0 }) });
        check(!active.checked && active.disabled && controls.querySelector(".timer-countdown").textContent.startsWith("Ⅱ"),
            "Paused countdown is retained and visually identified offline");
        check(getComputedStyle(timerDialog).backgroundColor === "rgb(255, 255, 255)"
            && getComputedStyle(timerDialog).color === "rgb(23, 23, 23)"
            && getComputedStyle(controls.querySelector("footer")).backgroundColor === "rgb(255, 255, 255)",
            "Dialog and footer keep explicit light colors independent of system dark theme");
        timerDialog.querySelector('[data-timer-action="delete"]').click();
        check(JSON.parse(actions.at(-1)[1]).action === "delete", "Delete requests removal only");
        push(4, { Fireplace: true }, { offTimer: timerPayload("idle") });
        check(controls.querySelector(".timer-countdown").textContent === "", "Confirmed timer deletion removes countdown");
        resetTimer.click();
        check(JSON.parse(actions.at(-1)[1]).action === "reset" && JSON.parse(actions.at(-1)[1]).duration === 86400,
            "Reset explicitly restarts selected duration immediately");
        push(4, { Fireplace: true }, { offTimer: timerPayload("stopping", { error: "Abschaltung fehlgeschlagen" }) });
        check(controls.querySelector(".timer-countdown").textContent === "Abschaltung…"
            && controls.querySelector("#connectionStatus").textContent === "Abschaltung fehlgeschlagen",
            "Failed shutdown stays pending and visibly reports error");
        timerDialog.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }));
        check(!controls.querySelector(".timer-dialog") && !controls.querySelector("#controls").inert
            && document.activeElement === controls.querySelector(".timer-button"),
            "Escape closes dialog without changing timer and restores focus");
        push(16384, { Fireplace: false }, { offTimer: timerPayload("idle") });
        controls.querySelector(".timer-button").click();
        check(!controls.querySelector("#timerActive").disabled, "Active temperature automation allows timer with burner off");
        controls.querySelector("[data-timer-close]").click();
        const originalTileStyle = controls.style.cssText;
        try {
            for (const [width, height] of [[280, 180], [320, 220], [390, 260]]) {
                controls.style.width = `${width}px`;
                controls.style.height = `${height}px`;
                controls.style.fontSize = "18px";
                controls.querySelector(".timer-button").click();
                const dialog = controls.querySelector(".timer-dialog");
                const tileRect = controls.getBoundingClientRect();
                const dialogRect = dialog.getBoundingClientRect();
                dialog.scrollTop = dialog.scrollHeight;
                check(dialogRect.left >= tileRect.left && dialogRect.right <= tileRect.right
                    && dialogRect.top >= tileRect.top && dialogRect.bottom <= tileRect.bottom
                    && dialog.scrollWidth === dialog.clientWidth
                    && dialog.querySelector(".timer-actions").getBoundingClientRect().bottom <= dialogRect.bottom,
                    `Timer dialog fits ${width}x${height} tile with enlarged text and reachable actions`);
                controls.querySelector("[data-timer-close]").click();
            }
        } finally {
            controls.style.cssText = originalTileStyle;
        }
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
