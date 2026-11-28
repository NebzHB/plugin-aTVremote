#!/usr/bin/env python3
# Python/asyncio replacement for aTVremoted.js: same HTTP contract (/cmd, /connect,
# /disconnect, /test, /stop), same Jeedom event shapes, but ONE persistent pyatv
# connection per device instead of spawning atvremote/atvscript subprocesses.
#
# Usage (same positional argv contract as aTVremoted.js):
#   aTVremoted.py <urlJeedom> <apiKey> <serverPort> <logLevel> <preConnect3> <preConnect4> <preConnectHP>
# preConnect* are comma-separated MAC lists, or the literal string "None".

from __future__ import annotations

import asyncio
import json
import logging
import os
import re
import signal
import sys
from collections import deque
from datetime import datetime
from enum import Enum

import aiohttp
from aiohttp import web

import pyatv
from pyatv.const import Protocol, ShuffleState, RepeatState, InputAction, FeatureName, FeatureState
from pyatv.interface import DeviceListener, PushListener, PowerListener, Playing, retrieve_commands
from pyatv.storage.memory_storage import MemoryStorage

try:
    from pyatv.interface import AudioListener
except ImportError:
    AudioListener = None


BASE_DIR = os.path.dirname(os.path.abspath(__file__))
DATA_DIR = os.path.join(BASE_DIR, "..", "data")  # même dossier que le démon Node.js : <plugin>/data/<mac>-airplay.key
ARTWORK_DIR = os.path.join(BASE_DIR, "..", "core", "img", "artworks")
ARTWORK_PATH = os.path.join(ARTWORK_DIR, "artwork.png")

LEVEL_ERROR, LEVEL_WARNING, LEVEL_INFO, LEVEL_DEBUG = 0, 1, 2, 3
_LEVEL_NAMES = {LEVEL_ERROR: "ERROR", LEVEL_WARNING: "WARNING", LEVEL_INFO: "INFO", LEVEL_DEBUG: "DEBUG"}
_log_level = LEVEL_ERROR


def set_log_level(level_str: str) -> None:
    global _log_level
    _log_level = {"debug": LEVEL_DEBUG, "info": LEVEL_INFO, "warning": LEVEL_WARNING}.get(level_str, LEVEL_ERROR)


def log_fr(message: str, level: int = LEVEL_INFO) -> None:
    if level > _log_level:
        return
    timestamp = datetime.now().strftime("%d-%m-%Y %H:%M:%S")
    print(f"[{timestamp}][{_LEVEL_NAMES[level]}] : {message}", flush=True)


def setup_library_logging() -> None:
    """Messages de pyatv / aiohttp / asyncio (warnings, erreurs, tracebacks) dans le même log
    aTVremote_deamon et au même format que les nôtres et que ceux du démon Node.js."""
    handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(logging.Formatter("[%(asctime)s][%(levelname)s] : [%(name)s] %(message)s", "%d-%m-%Y %H:%M:%S"))
    root = logging.getLogger()
    root.handlers[:] = [handler]
    root.setLevel(logging.WARNING)  # pyatv en debug est beaucoup trop bavard


_LEADING_INT_RE = re.compile(r"\s*[+-]?\d+")


def js_parse_int(value) -> int | None:
    """Mimic JS parseInt(): parse a leading digit run, ignore the rest (e.g. "4K" -> 4).
    Returns None where JS would return NaN (no leading digits at all)."""
    match = _LEADING_INT_RE.match(str(value))
    return int(match.group()) if match else None


# ---------------------------------------------------------------------------
# Jeedom event queue: single global FIFO, one in-flight POST at a time, failed
# sends are retried from the FRONT of the queue (up to 5 retries), ~10ms pacing
# between ticks. Mirrors resources/utils/jeedom.js exactly.
# ---------------------------------------------------------------------------

JEEDOM_URL: str = ""
API_KEY: str = ""

_jeedom_deque: deque = deque()
_jeedom_event = asyncio.Event()


def send_to_jeedom(event_type: str, mac: str, data: str | None = None, description: str | None = None) -> None:
    payload = {"type": "event", "apikey": API_KEY, "plugin": "aTVremote", "eventType": event_type, "mac": mac}
    if description is not None:
        payload["description"] = description
    elif data is not None:
        payload["data"] = data
    _jeedom_deque.append((payload, 0))
    _jeedom_event.set()


async def jeedom_sender_task() -> None:
    async with aiohttp.ClientSession() as session:
        while True:
            if not _jeedom_deque:
                _jeedom_event.clear()
                await _jeedom_event.wait()
                continue
            payload, try_count = _jeedom_deque.popleft()
            try:
                async with session.post(JEEDOM_URL, data=payload, timeout=aiohttp.ClientTimeout(total=10)) as resp:
                    body = await resp.text()
                    if _log_level >= LEVEL_DEBUG and body.strip():
                        log_fr(f"Réponse de Jeedom : {body}", LEVEL_DEBUG)
            except Exception as exc:
                if try_count < 5:
                    _jeedom_deque.appendleft((payload, try_count + 1))
                else:
                    log_fr(f"Abandon de l'envoi vers Jeedom après 5 essais : {exc}", LEVEL_WARNING)
            await asyncio.sleep(0.01)


# ---------------------------------------------------------------------------
# Event payload builders (exact shapes consumed by core/class/aTVremote.class.php)
# ---------------------------------------------------------------------------

def _enum_name(value, lower: bool = False):
    if isinstance(value, Enum):
        return value.name.lower() if lower else value.name
    return value


def build_playing_payload(playing: Playing, app) -> str:
    commands = retrieve_commands(Playing)
    # en minuscules comme atvscript (« paused », « music », « off »…) : le PHP compare ces valeurs
    values = {k: _enum_name(getattr(playing, k, None), lower=True) for k in commands}
    values["app"] = app.name if app else None
    values["app_id"] = app.identifier if app else None
    return json.dumps(values)


def spawn(coro, what: str) -> asyncio.Task:
    """Lance une tâche et journalise son erreur éventuelle (sinon une exception dans une tâche
    lancée depuis un listener disparaît sans laisser de trace)."""
    task = asyncio.ensure_future(coro)

    def _done(t: asyncio.Task) -> None:
        if not t.cancelled() and t.exception() is not None:
            log_fr(f"Erreur dans {what} : {t.exception()!r}", LEVEL_ERROR)

    task.add_done_callback(_done)
    return task


def current_app(atv):
    """App en cours, seulement si la fonction « App » est disponible (comme atvscript) : sur un
    HomePod au repos, atv.metadata.app lève une exception."""
    try:
        if atv.features.in_state(FeatureState.Available, FeatureName.App):
            return atv.metadata.app
    except Exception as exc:
        log_fr(f"App indisponible : {exc!r}", LEVEL_DEBUG)
    return None


async def send_playing_event(device: "Device", playing: Playing | None = None) -> None:
    atv = device.atv
    if atv is None:
        return
    if playing is None:
        playing = await atv.metadata.playing()
    app = current_app(atv)
    data = build_playing_payload(playing, app)
    log_fr(f"[MSG][{device.mac}] Reçu playing et envoyé à jeedom |{data}", LEVEL_DEBUG)
    send_to_jeedom("playing", device.mac, data=data)
    schedule_features(device, playing, app.identifier if app else None)


def send_powerstate_event(mac: str, power_state) -> None:
    log_fr(f"[MSG][{mac}] Reçu power_state et envoyé à jeedom |{_enum_name(power_state, lower=True)}", LEVEL_DEBUG)
    send_to_jeedom("powerstate", mac, data=json.dumps({"power_state": _enum_name(power_state, lower=True)}))  # « on » / « off »


async def send_app_event(device: "Device") -> None:
    apps = await device.atv.apps.app_list()
    text = ", ".join(f"App: {app.name} ({app.identifier})" for app in apps)
    send_to_jeedom("app", device.mac, data=text)


async def send_features_event(device: "Device", only_if_changed: bool = False) -> None:
    if device.atv is None:
        return
    try:
        features = device.atv.features.all_features()
    except Exception as exc:
        log_fr(f"[{device.mac}] Impossible de récupérer les features : {exc}", LEVEL_DEBUG)
        return
    values = {feat.name: info.state.name for feat, info in features.items()}
    if only_if_changed and values == device.last_features:
        log_fr(f"[MSG][{device.mac}] Features inchangées, rien à envoyer", LEVEL_DEBUG)
        return
    device.last_features = values
    log_fr(f"[MSG][{device.mac}] Envoi features (SetShuffle={values.get('SetShuffle')}, SetRepeat={values.get('SetRepeat')})", LEVEL_DEBUG)
    send_to_jeedom("features", device.mac, data=json.dumps(values))


# Fonctions utilisables (aléatoire, répétition…) : elles dépendent de la source (Apple Music, AirPlay…) et
# l'appareil envoie plusieurs messages pendant le chargement d'un morceau. Après un changement de morceau,
# d'app ou d'état, on relit donc la liste UNE fois, 3 s après le dernier message (comme le démon Node.js).
# Ici la lecture est locale (pas de commande à envoyer) : on n'envoie à Jeedom que si elle a changé.
FEATURES_DELAY = 3.0


def schedule_features(device: "Device", playing: Playing, app_id) -> None:
    state = str(_enum_name(playing.device_state, lower=True) or "")
    key = (playing.hash, app_id, state)
    if key == device.features_key and device.features_timer is None:
        return  # rien de nouveau
    if device.features_timer is not None:
        device.features_timer.cancel()

    def _fire() -> None:
        device.features_timer = None
        device.features_key = key
        if state not in ("playing", "paused"):
            return  # rien à lire : inutile
        spawn(send_features_event(device, only_if_changed=True), f"l'envoi des features de {device.mac}")

    device.features_timer = asyncio.get_event_loop().call_later(FEATURES_DELAY, _fire)


# ---------------------------------------------------------------------------
# Command parsing + generic dispatch (replicates what the atvremote interactive
# CLI did internally when Node piped raw command strings into its stdin).
# ---------------------------------------------------------------------------

_INPUT_ACTION_COMMANDS = {"up", "down", "left", "right", "select", "menu", "home"}


def _typeparse(raw: str):
    try:
        return int(raw)
    except ValueError:
        return raw


def parse_command(command_str: str):
    if "=" in command_str:
        name, raw_args = command_str.split("=", 1)
        args = [_typeparse(part.strip()) for part in raw_args.split(",")]
    else:
        name, args = command_str, []
    return name.strip(), args


def _coerce_args(name: str, args: list):
    if name == "set_shuffle":
        return [ShuffleState(args[0])]
    if name == "set_repeat":
        return [RepeatState(args[0])]
    if name in _INPUT_ACTION_COMMANDS and args:
        return [InputAction(args[0])]
    if name == "set_volume":
        return [float(args[0])]
    if name == "set_position":
        return [int(args[0])]
    return args


async def resolve_and_call(atv, name: str, args: list):
    args = _coerce_args(name, args)

    if name == "features":
        return atv.features.all_features()
    if name == "delay":
        await asyncio.sleep(float(args[0]) / 1000.0)
        return None
    if name == "artwork_save":
        artwork = await atv.metadata.artwork(*args) if args else await atv.metadata.artwork()
        return artwork

    for namespace in (
        atv.audio,
        atv.remote_control,
        atv.metadata,
        atv.power,
        atv.stream,
        atv.keyboard,
        atv.device_info,
        atv.apps,
        atv.user_accounts,
        atv.touch,
    ):
        attr = getattr(namespace, name, None)
        if attr is None:
            continue
        if callable(attr):
            result = attr(*args)
            if asyncio.iscoroutine(result):
                return await result
            return result
        return attr

    playing = await atv.metadata.playing()
    attr = getattr(playing, name, None)
    if attr is not None:
        return attr() if callable(attr) else attr

    raise AttributeError(f"Commande inconnue : {name}")


_INFO_COMMANDS = {"playing", "hash", "power_state", "app_list", "volume", "features"}


async def dispatch_and_notify(device: "Device", name: str, args: list) -> None:
    """Run one command against the device, then (for read/informational commands)
    proactively send the matching Jeedom event using the result, mirroring what
    Node's stdout substring-parser used to do for the same CLI command."""
    if name == "artwork_save":
        artwork = await resolve_and_call(device.atv, name, args)
        if artwork is not None:
            os.makedirs(ARTWORK_DIR, exist_ok=True)
            with open(ARTWORK_PATH, "wb") as f:
                f.write(artwork.bytes)
        else:
            send_to_jeedom("reaskArtwork", device.mac)
        return

    result = await resolve_and_call(device.atv, name, args)

    if name not in _INFO_COMMANDS:
        return
    if name in ("playing", "hash"):
        await send_playing_event(device)
    elif name == "power_state":
        send_powerstate_event(device.mac, result)
    elif name == "app_list":
        await send_app_event(device)
    elif name == "volume":
        send_to_jeedom("volume", device.mac, data=str(result))
    elif name == "features":
        await send_features_event(device)


# ---------------------------------------------------------------------------
# Listeners (bridge pyatv's sync callbacks into the async supervisor loop)
# ---------------------------------------------------------------------------

class _ConnDeviceListener(DeviceListener):
    def __init__(self, mac: str):
        self.mac = mac
        self.disconnected = asyncio.Event()

    def connection_lost(self, exception: Exception) -> None:
        log_fr(f"[{self.mac}] Connexion perdue : {exception}", LEVEL_DEBUG)
        self.disconnected.set()

    def connection_closed(self) -> None:
        log_fr(f"[{self.mac}] Déconnecté !", LEVEL_DEBUG)
        self.disconnected.set()


class _ConnPushListener(PushListener):
    def __init__(self, device: "Device"):
        self.device = device

    def playstatus_update(self, updater, playstatus: Playing) -> None:
        spawn(send_playing_event(self.device, playstatus), f"l'envoi de la lecture de {self.device.mac}")

    def playstatus_error(self, updater, exception: Exception) -> None:
        log_fr(f"[{self.device.mac}] Erreur de mise à jour : {exception}", LEVEL_DEBUG)


class _ConnPowerListener(PowerListener):
    def __init__(self, mac: str):
        self.mac = mac

    def powerstate_update(self, old_state, new_state) -> None:
        send_powerstate_event(self.mac, new_state)


if AudioListener is not None:
    class _ConnAudioListener(AudioListener):
        def __init__(self, device: "Device"):
            self.device = device

        def volume_update(self, old_level, new_level) -> None:
            log_fr(f"[MSG][{self.device.mac}] Reçu volume et envoyé à jeedom |{new_level}", LEVEL_DEBUG)
            send_to_jeedom("volume", self.device.mac, data=str(new_level))
else:
    _ConnAudioListener = None


# ---------------------------------------------------------------------------
# Per-device supervisor: persistent connection, reconnect with backoff
# ---------------------------------------------------------------------------

class DeviceNotFound(Exception):
    pass


class Device:
    def __init__(self, mac: str, version_num: int | None):
        self.mac = mac
        self.version_num = version_num
        self.atv = None
        self.task: asyncio.Task | None = None
        self.give_up = False
        # pyatv ne garde qu'une référence FAIBLE vers les listeners : sans référence ici ils sont
        # détruits aussitôt créés et plus aucun message (lecture, veille, volume) n'arrive.
        self.listeners: list = []
        # relecture des features après un changement de morceau (voir schedule_features)
        self.features_timer: asyncio.TimerHandle | None = None
        self.features_key = None
        self.last_features: dict | None = None

    def cancel_features(self) -> None:
        if self.features_timer is not None:
            self.features_timer.cancel()
            self.features_timer = None


devices: dict[str, Device] = {}


async def _load_credentials(conf, mac: str, version_num: int | None) -> None:
    if version_num not in (3, 4):
        return

    airplay_path = os.path.join(DATA_DIR, f"{mac}-airplay.key")
    if os.path.exists(airplay_path) and conf.get_service(Protocol.AirPlay) is not None:
        with open(airplay_path, "r") as f:
            conf.set_credentials(Protocol.AirPlay, f.read().strip())
    else:
        log_fr(f"Pas de clé airplay pour {mac}, merci de faire l'appairage avant de connecter cet équipement", LEVEL_WARNING)

    if version_num == 4:
        companion_path = os.path.join(DATA_DIR, f"{mac}-companion.key")
        if os.path.exists(companion_path) and conf.get_service(Protocol.Companion) is not None:
            with open(companion_path, "r") as f:
                conf.set_credentials(Protocol.Companion, f.read().strip())
        else:
            log_fr(f"Pas de clé companion pour {mac}, merci de faire l'appairage avant de connecter cet équipement", LEVEL_WARNING)


async def _connect_once(mac: str, version_num: int | None):
    loop = asyncio.get_event_loop()
    storage = MemoryStorage()
    results = await pyatv.scan(loop, identifier=mac, storage=storage)
    if not results:
        raise DeviceNotFound()
    conf = results[0]
    await _load_credentials(conf, mac, version_num)
    atv = await pyatv.connect(conf, loop, storage=storage)
    return atv


async def _supervisor(mac: str, version_num: int | None) -> None:
    device = devices[mac]
    backoff = 1.0
    while not device.give_up:
        try:
            atv = await _connect_once(mac, version_num)
        except DeviceNotFound:
            log_fr(f"[{mac}] Équipement introuvable sur le réseau, abandon de la connexion", LEVEL_DEBUG)
            break
        except Exception as exc:
            log_fr(f"[{mac}] Erreur de connexion : {exc}, nouvelle tentative dans {backoff:.0f}s", LEVEL_DEBUG)
            await asyncio.sleep(backoff)
            backoff = min(backoff * 2, 30)
            continue

        backoff = 1.0
        device.atv = atv
        log_fr(f"[{mac}] Connecté avec succès !", LEVEL_INFO)

        conn_listener = _ConnDeviceListener(mac)
        power_listener = _ConnPowerListener(mac)
        device.listeners = [conn_listener, power_listener]
        atv.listener = conn_listener
        atv.power.listener = power_listener
        if version_num != 3:
            push_listener = _ConnPushListener(device)
            device.listeners.append(push_listener)
            atv.push_updater.listener = push_listener
            atv.push_updater.start()
        if _ConnAudioListener is not None:
            try:
                audio_listener = _ConnAudioListener(device)
                device.listeners.append(audio_listener)
                atv.audio.listener = audio_listener
            except Exception:
                pass

        # état initial, comme atvscript push_updates (sinon rien n'arrive tant que rien ne change)
        try:
            send_powerstate_event(mac, atv.power.power_state)
        except Exception as exc:
            log_fr(f"[{mac}] État d'alimentation indisponible : {exc!r}", LEVEL_DEBUG)
        if version_num == 3:
            spawn(send_playing_event(device), f"l'envoi de la lecture de {mac}")

        await conn_listener.disconnected.wait()
        device.cancel_features()
        device.atv = None
        device.listeners = []
        atv.close()
        if device.give_up:
            break
        log_fr(f"[{mac}] Reconnexion...", LEVEL_DEBUG)
        await asyncio.sleep(0.1)

    devices.pop(mac, None)


def start_device_supervisor(mac: str, version_num: int | None) -> bool:
    existing = devices.get(mac)
    if existing is not None and not existing.give_up:
        return False
    device = Device(mac, version_num)
    devices[mac] = device
    device.task = asyncio.ensure_future(_supervisor(mac, version_num))
    return True


async def stop_device_supervisor(mac: str) -> None:
    device = devices.get(mac)
    if device is None:
        return
    device.give_up = True
    if device.atv is not None:
        device.atv.close()
    else:
        devices.pop(mac, None)


# ---------------------------------------------------------------------------
# HTTP routes (identical shapes to aTVremoted.js)
# ---------------------------------------------------------------------------

routes = web.RouteTableDef()


@routes.get("/cmd")
async def handle_cmd(request: web.Request) -> web.Response:
    mac = request.query.get("mac", "").upper()
    cmd_str = request.query.get("cmd", "").replace(" ", "", 1)

    if "push_updates" in cmd_str:
        return web.json_response({"result": "ko", "msg": "unsupported"})

    device = devices.get(mac)
    if device is None or device.atv is None:
        return web.json_response({"result": "ko", "msg": "notConnectedATM"})

    for sub in cmd_str.split("|"):
        name, args = parse_command(sub)
        try:
            await asyncio.wait_for(dispatch_and_notify(device, name, args), timeout=10)
        except Exception as exc:
            log_fr(f"[CMD][{mac}] Erreur sur la commande '{sub}' : {exc}", LEVEL_ERROR)

    return web.json_response({"result": "ok"})


@routes.get("/connect")
async def handle_connect(request: web.Request) -> web.Response:
    mac = request.query.get("mac", "").upper()
    version_num = js_parse_int(request.query.get("version", ""))

    existing = devices.get(mac)
    if existing is not None and not existing.give_up:
        log_fr(f"Connexion demandée mais déjà connecté/en cours sur {mac}", LEVEL_INFO)
        return web.json_response({"result": "ko", "msg": "alreadyConnected"})

    log_fr(f"Connexion sur {mac} (version={version_num})...", LEVEL_INFO)
    start_device_supervisor(mac, version_num)
    return web.json_response({"result": "ok"})


@routes.get("/disconnect")
async def handle_disconnect(request: web.Request) -> web.Response:
    mac = request.query.get("mac", "").upper()
    if mac not in devices:
        log_fr(f"Déconnexion demandée mais pas connecté sur {mac}", LEVEL_INFO)
        return web.json_response({"result": "ko", "msg": "notConnected"})
    log_fr(f"Déconnexion de {mac}", LEVEL_INFO)
    await stop_device_supervisor(mac)
    return web.json_response({"result": "ok"})


@routes.get("/test")
async def handle_test(request: web.Request) -> web.Response:
    return web.json_response({"result": "ok"})


@routes.get("/stop")
async def handle_stop(request: web.Request) -> web.Response:
    asyncio.ensure_future(_shutdown())
    return web.json_response({"result": "stopped"})


@web.middleware
async def error_middleware(request, handler):
    try:
        return await handler(request)
    except web.HTTPException:
        raise
    except Exception as exc:
        log_fr(f"Erreur non gérée sur {request.path} : {exc}", LEVEL_ERROR)
        return web.json_response({"result": "ko", "msg": str(exc)})


# ---------------------------------------------------------------------------
# Startup / shutdown
# ---------------------------------------------------------------------------

_runner: web.AppRunner | None = None
_shutting_down = False
_stop_event: asyncio.Event | None = None
_server_port: int | None = None


async def _shutdown() -> None:
    global _shutting_down
    if _shutting_down:
        return
    _shutting_down = True
    log_fr("Arrêt du démon en cours...", LEVEL_INFO)
    close_tasks = []
    for mac, device in list(devices.items()):
        device.give_up = True
        device.cancel_features()
        if device.atv is not None:
            try:
                close_tasks.extend(device.atv.close() or [])
            except Exception as exc:
                log_fr(f"[{mac}] Erreur à la déconnexion : {exc!r}", LEVEL_DEBUG)
    if close_tasks:
        await asyncio.wait(close_tasks, timeout=3)  # connexions aux appareils fermées proprement
    if _runner is not None:
        await _runner.cleanup()  # ferme le port d'écoute HTTP
        log_fr(f"Port {_server_port} fermé", LEVEL_DEBUG)
    if _stop_event is not None:
        _stop_event.set()


def _install_signal_handlers(loop: asyncio.AbstractEventLoop) -> None:
    for sig in (signal.SIGTERM, getattr(signal, "SIGHUP", None)):
        if sig is None:
            continue
        try:
            loop.add_signal_handler(sig, lambda: asyncio.ensure_future(_shutdown()))
        except (NotImplementedError, RuntimeError):
            pass  # not supported on this platform (e.g. Windows dev machine)


async def main() -> None:
    global JEEDOM_URL, API_KEY, _runner, _stop_event, _server_port

    url_jeedom = sys.argv[1]
    API_KEY = sys.argv[2]
    server_port = int(sys.argv[3])
    _server_port = server_port
    _stop_event = asyncio.Event()
    log_level_str = sys.argv[4]
    pre_connect_3 = [] if sys.argv[5] == "None" else [m for m in sys.argv[5].split(",") if m]
    pre_connect_4 = [] if sys.argv[6] == "None" else [m for m in sys.argv[6].split(",") if m]
    pre_connect_hp = [] if sys.argv[7] == "None" else [m for m in sys.argv[7].split(",") if m]

    JEEDOM_URL = url_jeedom
    set_log_level(log_level_str)

    log_fr(
        f"Démarrage du démon aTVremote (Python, fichier du {datetime.fromtimestamp(os.path.getmtime(__file__)):%d-%m-%Y %H:%M}, "
        f"pyatv {pyatv.const.__version__}) sur le port {server_port}, "
        f"preConnect3={pre_connect_3}, preConnect4={pre_connect_4}, preConnectHP={pre_connect_hp}",
        LEVEL_INFO,
    )

    os.makedirs(ARTWORK_DIR, exist_ok=True)

    app = web.Application(middlewares=[error_middleware])
    app.add_routes(routes)

    _runner = web.AppRunner(app)
    await _runner.setup()
    site = web.TCPSite(_runner, "0.0.0.0", server_port)
    await site.start()

    asyncio.ensure_future(jeedom_sender_task())

    for mac in pre_connect_3:
        start_device_supervisor(mac.upper(), 3)
    for mac in pre_connect_4:
        start_device_supervisor(mac.upper(), 4)
    for mac in pre_connect_hp:
        start_device_supervisor(mac.upper(), None)

    _install_signal_handlers(asyncio.get_event_loop())
    log_fr("Démon prêt et à l'écoute !", LEVEL_INFO)

    await _stop_event.wait()


if __name__ == "__main__":
    setup_library_logging()
    loop = asyncio.new_event_loop()  # get_event_loop() hors boucle est obsolète depuis Python 3.12
    asyncio.set_event_loop(loop)
    exit_code = 0
    try:
        loop.run_until_complete(main())
    except KeyboardInterrupt:
        loop.run_until_complete(_shutdown())
    except Exception as exc:
        logging.getLogger("aTVremoted").exception("Erreur fatale du démon : %s", exc)
        exit_code = 1
    finally:
        # tâches restantes (envoi vers Jeedom, surveillance des appareils) annulées proprement
        pending = [t for t in asyncio.all_tasks(loop) if not t.done()]
        for task in pending:
            task.cancel()
        if pending:
            loop.run_until_complete(asyncio.gather(*pending, return_exceptions=True))
        loop.close()
        log_fr("Démon arrêté", LEVEL_INFO)
    sys.exit(exit_code)
