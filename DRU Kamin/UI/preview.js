(() => {
    const scenario = document.getElementById("scenario");
    const log = document.getElementById("previewLog");
    const statuses = {
        off: 8192,
        on: 8204,
        wave: 8716,
        temperature: 16396,
        "temperature-off": 16384,
        fault: 8257,
        "fault-locked": 8193,
        "ignition-locked": 40960,
        offline: 8192
    };
    let status = statuses.off;
    let available = true;
    let resetPending = false;
    let flameHeight = 50;
    let setpoint = 21;
    let wave = { available: true, interval: 10, stages: Array(20).fill(50) };
    let pendingTimer;
    let pendingAction = "";

    function note(message) {
        log.textContent = `${new Date().toLocaleTimeString()} ${message}\n${log.textContent}`.slice(0, 4000);
    }

    function payload() {
        return {
            values: {
                Fireplace: (status & 4) !== 0,
                FireplaceFault: (status & 1) !== 0,
                SecondBurner: (status & 8) !== 0,
                Light: (status & 256) !== 0,
                BoostFan: (status & 128) !== 0,
                Wave: (status & 512) !== 0,
                TemperatureControl: ((status >> 13) & 3) === 2,
                FlameHeight: flameHeight,
                TemperatureSetpoint: setpoint,
                RoomTemperature: 20.5
            },
            features: { temperatureControl: true, wave: true },
            statusRegister: status,
            statusAvailable: available,
            statusValidForMs: available ? 15000 : 0,
            resetPending,
            canSetFlameHeight: available && (status & 4) !== 0 && (status & 513) === 0 && ((status >> 13) & 3) !== 2,
            waveSettings: wave
        };
    }

    function push() {
        window.handleMessage(JSON.stringify(payload()));
    }

    function delayed(action, delay, callback) {
        pendingAction = action;
        pendingTimer = window.setTimeout(() => {
            pendingAction = "";
            callback();
            push();
        }, delay);
    }

    scenario.addEventListener("change", () => {
        window.clearTimeout(pendingTimer);
        pendingAction = "";
        resetPending = false;
        status = statuses[scenario.value];
        available = scenario.value !== "offline";
        // An explicit offline update clears local unlock/ignition state when switching scenarios.
        window.handleMessage(JSON.stringify({ ...payload(), statusAvailable: false }));
        push();
        note(`Szenario: ${scenario.selectedOptions[0].textContent}`);
    });

    window.requestAction = (ident, value) => {
        note(`SIMULATION ${ident}: ${String(value)}`);
        if (!available) {
            note("Abgelehnt: keine Verbindung.");
            return;
        }
        switch (ident) {
            case "Fireplace":
                if (value) {
                    if ((status & (1 | 4 | 32768)) !== 0 || ((status >> 13) & 3) === 2 || pendingAction) {
                        note("Abgelehnt: Zündung nicht erlaubt.");
                        return;
                    }
                    delayed("ignition", 10000, () => {
                        if (document.getElementById("ignitionFails").checked) {
                            status |= 1 | 64;
                            note("Zündung fehlgeschlagen: Fault und Resetfreigabe gesetzt.");
                        } else {
                            status |= 4 | 8;
                            flameHeight = 100;
                            note("Zündung bestätigt: Haupt- und Zweitbrenner an.");
                        }
                    });
                } else {
                    window.clearTimeout(pendingTimer);
                    pendingAction = "";
                    status &= ~(4 | 8 | 512);
                    status = (status & ~(3 << 13)) | (1 << 13);
                }
                break;
            case "ResetFireplace":
                if ((status & 65) !== 65 || pendingAction) {
                    note("Abgelehnt: Reset nicht freigegeben oder bereits aktiv.");
                    return;
                }
                resetPending = true;
                delayed("reset", document.getElementById("resetFails").checked ? 20000 : 3000, () => {
                    resetPending = false;
                    if (!document.getElementById("resetFails").checked) {
                        status &= ~1;
                    }
                    note((status & 1) !== 0 ? "Fault bleibt gesetzt: Reset erneut möglich." : "Fault gelöscht.");
                });
                break;
            case "SecondBurner":
            case "Light":
            case "BoostFan": {
                const bit = { SecondBurner: 8, Light: 256, BoostFan: 128 }[ident];
                status = value ? status | bit : status & ~bit;
                break;
            }
            case "OperationMode":
                status &= ~512;
                status = (status & ~(3 << 13)) | ((value === "temperature" ? 2 : 1) << 13);
                if (value === "wave") {
                    status |= 512;
                }
                break;
            case "FlameHeight":
                flameHeight = Number(value);
                break;
            case "TemperatureSetpoint":
                setpoint = Number(value);
                break;
            case "SaveWaveSettings": {
                const settings = JSON.parse(value);
                wave = {
                    available: true,
                    interval: settings.interval,
                    stages: settings.stages.map(percentage => Math.round(Math.round(percentage * 14 / 100) * 100 / 14))
                };
                note("Wave-Muster lokal gespeichert (keine Geräteübertragung).");
                break;
            }
            case "ReloadWaveSettings":
                note("Aktuelles simuliertes Profil geladen.");
                break;
            default:
                note(`Nicht simulierte Aktion: ${ident}`);
                return;
        }
        push();
    };

    document.getElementById("druData").textContent = JSON.stringify(payload());
    window.setInterval(push, 5000);
})();
