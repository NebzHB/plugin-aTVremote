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
import hashlib
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
from pyatv import exceptions as atv_exceptions
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


def artwork_path(mac: str) -> str:
    """Une pochette par appareil (le démon Node.js écrit un seul artwork.png commun : deux appareils qui
    changent de morceau en même temps pouvaient s'échanger leur pochette). Même nom côté PHP."""
    return os.path.join(ARTWORK_DIR, "artwork-" + mac.upper().replace(":", "") + ".png")

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
    if device.version_num != 3:
        maybe_fetch_artwork(device, playing)


# ---------------------------------------------------------------------------
# Pochette gérée par le démon (hors Apple TV 3) : à chaque nouveau morceau, il la récupère lui-même,
# l'écrit sous son nom définitif (md5 du hash, comme setArtwork() en PHP) et envoie le chemin à Jeedom.
# Plus de demande artwork_save, d'attente du fichier ni de renommage côté PHP.
# ---------------------------------------------------------------------------

ARTWORK_URL_DIR = "plugins/aTVremote/core/img/artworks/"
ARTWORK_WIDTH, ARTWORK_HEIGHT = 138, -1  # mêmes valeurs que setArtwork() en PHP
ARTWORK_TRIES = 4  # l'appareil n'a pas toujours la pochette tout de suite : 4 essais, 1 s d'écart


def maybe_fetch_artwork(device: "Device", playing: Playing) -> None:
    state = str(_enum_name(playing.device_state, lower=True) or "")
    media = str(_enum_name(playing.media_type, lower=True) or "")
    if not playing.hash or state == "idle" or media == "unknown":
        return  # mêmes conditions que setaTVremoteInfo() en PHP : on garde la pochette précédente
    if playing.hash == device.artwork_hash:
        return  # déjà fait (ou en cours) pour ce morceau
    device.artwork_hash = playing.hash
    spawn(fetch_artwork(device, playing.hash), f"la pochette de {device.mac}")


async def fetch_artwork(device: "Device", track_hash: str) -> None:
    name = hashlib.md5(track_hash.encode("utf-8")).hexdigest() + ".jpg"
    dest = os.path.join(ARTWORK_DIR, name)
    url = ARTWORK_URL_DIR + name
    if os.path.exists(dest):
        os.utime(dest)  # garde la pochette dans le cache (nettoyage par date)
        log_fr(f"[MSG][{device.mac}] Pochette déjà en cache : {name}", LEVEL_DEBUG)
        send_to_jeedom("artwork", device.mac, data=json.dumps({"hash": track_hash, "url": url}))
        return
    for attempt in range(1, ARTWORK_TRIES + 1):
        if device.atv is None or device.artwork_hash != track_hash:
            return  # déconnecté ou morceau suivant déjà là
        try:
            artwork = await device.atv.metadata.artwork(ARTWORK_WIDTH, ARTWORK_HEIGHT)
        except Exception as exc:
            log_fr(f"[MSG][{device.mac}] Pochette indisponible ({exc!r}), essai {attempt}/{ARTWORK_TRIES}", LEVEL_DEBUG)
            artwork = None
        if artwork is not None and artwork.bytes and device.artwork_hash == track_hash:
            os.makedirs(ARTWORK_DIR, exist_ok=True)
            with open(dest + ".tmp", "wb") as f:
                f.write(artwork.bytes)
            os.replace(dest + ".tmp", dest)
            log_fr(f"[MSG][{device.mac}] Pochette enregistrée et envoyée à jeedom : {name} ({len(artwork.bytes)} octets, essai {attempt})", LEVEL_DEBUG)
            send_to_jeedom("artwork", device.mac, data=json.dumps({"hash": track_hash, "url": url}))
            return
        await asyncio.sleep(1)
    if device.artwork_hash == track_hash:
        log_fr(f"[MSG][{device.mac}] Pas de pochette pour ce morceau, image par défaut", LEVEL_DEBUG)
        send_to_jeedom("artwork", device.mac, data=json.dumps({"hash": track_hash, "url": None}))


def send_powerstate_event(mac: str, power_state) -> None:
    log_fr(f"[MSG][{mac}] Reçu power_state et envoyé à jeedom |{_enum_name(power_state, lower=True)}", LEVEL_DEBUG)
    send_to_jeedom("powerstate", mac, data=json.dumps({"power_state": _enum_name(power_state, lower=True)}))  # « on » / « off »


async def send_app_event(device: "Device") -> None:
    apps = await device.atv.apps.app_list()
    text = ", ".join(f"App: {app.name} ({app.identifier})" for app in apps)
    log_fr(f"[MSG][{device.mac}] Liste des apps envoyée à jeedom ({len(apps)} apps)", LEVEL_DEBUG)
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


# ---------------------------------------------------------------------------
# Vrais retours d'erreur : le démon Node.js répond toujours « ok » (il écrit dans le stdin d'atvremote sans
# attendre le résultat). Ici chaque commande est attendue : on renvoie au PHP un code par commande en
# erreur, que le PHP traduit et affiche (exception sur la commande Jeedom).
# ---------------------------------------------------------------------------

class CommandRefused(Exception):
    def __init__(self, code: str, detail: str = ""):
        super().__init__(detail or code)
        self.code = code
        self.detail = detail


# commandes que l'appareil accepte sans rien faire quand la fonction est indisponible : on refuse avant
_REFUSE_WHEN_UNAVAILABLE = {FeatureName.SetShuffle, FeatureName.SetRepeat}


def _feature_for(name: str):
    """set_shuffle -> FeatureName.SetShuffle, volume_up -> FeatureName.VolumeUp… (None si pas de fonction)."""
    return getattr(FeatureName, "".join(part.capitalize() for part in name.split("_")), None)


def check_feature(atv, name: str) -> None:
    if name in _INFO_COMMANDS:
        return
    feature = _feature_for(name)
    if feature is None:
        return
    try:
        state = atv.features.get_feature(feature).state
    except Exception:
        return
    if state == FeatureState.Unsupported:
        raise CommandRefused("unsupported", feature.name)
    if state == FeatureState.Unavailable and feature in _REFUSE_WHEN_UNAVAILABLE:
        raise CommandRefused("unavailable", feature.name)


def error_code(exc: Exception) -> tuple[str, str]:
    """Code (traduit côté PHP) et détail technique d'une erreur de commande."""
    if isinstance(exc, CommandRefused):
        return exc.code, exc.detail
    if isinstance(exc, asyncio.TimeoutError):
        return "timeout", ""
    if isinstance(exc, atv_exceptions.NotSupportedError):
        return "unsupported", str(exc)
    if isinstance(exc, (atv_exceptions.CommandError, atv_exceptions.BlockedStateError)):
        return "refused", str(exc)
    if isinstance(exc, AttributeError):
        return "unknown", str(exc)
    if isinstance(exc, (ValueError, TypeError, IndexError)):
        return "invalid", str(exc)
    return "error", f"{type(exc).__name__}: {exc}"


async def dispatch_and_notify(device: "Device", name: str, args: list) -> None:
    """Run one command against the device, then (for read/informational commands)
    proactively send the matching Jeedom event using the result, mirroring what
    Node's stdout substring-parser used to do for the same CLI command."""
    check_feature(device.atv, name)
    if name == "artwork_save":
        artwork = await resolve_and_call(device.atv, name, args)
        if artwork is not None:
            os.makedirs(ARTWORK_DIR, exist_ok=True)
            path = artwork_path(device.mac)
            with open(path + ".tmp", "wb") as f:
                f.write(artwork.bytes)
            os.replace(path + ".tmp", path)  # fichier complet d'un coup : le PHP ne lit jamais une pochette à moitié écrite
            log_fr(f"[CMD][{device.mac}] Pochette enregistrée ({len(artwork.bytes)} octets)", LEVEL_DEBUG)
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

        # AudioListener est une classe abstraite : ses TROIS méthodes doivent exister, sinon Python refuse
        # de créer l'objet (TypeError) et aucun changement de volume n'arrive.
        def volume_update(self, old_level, new_level) -> None:
            log_fr(f"[MSG][{self.device.mac}] Reçu volume et envoyé à jeedom |{new_level}", LEVEL_DEBUG)
            send_to_jeedom("volume", self.device.mac, data=str(new_level))

        def volume_device_update(self, output_device, old_level, new_level) -> None:
            # volume d'une enceinte de la sortie (groupe multiroom) : c'est le nôtre si l'identifiant de
            # l'enceinte correspond à notre adresse (ex. 8A338CA6-A878-… pour 8A:33:8C:A6:A8:78)
            ident = str(getattr(output_device, "identifier", "") or "").replace("-", "").upper()
            if ident.startswith(self.device.mac.replace(":", "")):
                self.volume_update(old_level, new_level)
            else:
                log_fr(f"[MSG][{self.device.mac}] Volume de l'enceinte {getattr(output_device, 'name', ident)} : {new_level}", LEVEL_DEBUG)

        def outputdevices_update(self, old_devices, new_devices) -> None:
            names = ", ".join(getattr(d, "name", "?") for d in new_devices)
            log_fr(f"[MSG][{self.device.mac}] Sorties audio : {names or 'aucune'}", LEVEL_DEBUG)
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
        self.artwork_hash = None  # morceau dont la pochette a été (ou est en train d'être) récupérée

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
            except Exception as exc:
                log_fr(f"[{mac}] Suivi du volume impossible : {exc!r}", LEVEL_WARNING)

        # état initial, comme atvscript push_updates (sinon rien n'arrive tant que rien ne change)
        try:
            send_powerstate_event(mac, atv.power.power_state)
        except Exception as exc:
            log_fr(f"[{mac}] État d'alimentation indisponible : {exc!r}", LEVEL_DEBUG)
        # features initiales : en mode Python, Jeedom ne les interroge plus (relues ensuite après chaque changement)
        spawn(send_features_event(device), f"l'envoi des features de {mac}")
        # volume initial : en mode Python, Jeedom ne l'interroge plus, les changements arrivent par le listener
        try:
            if atv.features.in_state(FeatureState.Available, FeatureName.Volume):
                log_fr(f"[MSG][{mac}] Volume initial envoyé à jeedom |{atv.audio.volume}", LEVEL_DEBUG)
                send_to_jeedom("volume", mac, data=str(atv.audio.volume))
        except Exception as exc:
            log_fr(f"[{mac}] Volume indisponible : {exc!r}", LEVEL_DEBUG)
        if version_num == 3:
            spawn(send_playing_event(device), f"l'envoi de la lecture de {mac}")
        # liste des apps (appairage Companion) : à chaque connexion, sinon le widget reste sur « Aucune »
        # tant que personne ne lance « MàJ Liste App » (Jeedom la demandait parfois avant la fin de la connexion)
        try:
            if atv.features.in_state(FeatureState.Available, FeatureName.AppList):
                spawn(send_app_event(device), f"l'envoi de la liste des apps de {mac}")
        except Exception as exc:
            log_fr(f"[{mac}] Liste des apps indisponible : {exc!r}", LEVEL_DEBUG)

        await conn_listener.disconnected.wait()
        device.cancel_features()
        device.atv = None
        device.listeners = []
        atv.close()
        if device.give_up:
            break
        log_fr(f"[{mac}] Reconnexion...", LEVEL_DEBUG)
        await asyncio.sleep(0.1)

    if devices.get(mac) is device:  # pas une nouvelle connexion lancée entre-temps pour la même adresse
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
        if device.task is not None:
            device.task.cancel()  # connexion en cours ou attente avant nouvel essai
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

    errors = []
    for sub in cmd_str.split("|"):  # comme Node.js : les commandes suivantes sont envoyées même après une erreur
        name, args = parse_command(sub)
        try:
            await asyncio.wait_for(dispatch_and_notify(device, name, args), timeout=10)
        except Exception as exc:
            code, detail = error_code(exc)
            level = LEVEL_WARNING if code in ("unavailable", "unsupported") else LEVEL_ERROR
            log_fr(f"[CMD][{mac}] Commande '{sub}' en erreur ({code}){' : ' + detail if detail else ''}", level)
            errors.append({"cmd": sub, "code": code, "detail": detail})

    if errors:
        # « errors » n'existe qu'avec ce démon : le PHP ne change rien pour le démon Node.js
        return web.json_response({"result": "ko", "msg": errors[0]["code"], "errors": errors})
    return web.json_response({"result": "ok"})


@routes.get("/connect")
async def handle_connect(request: web.Request) -> web.Response:
    mac = request.query.get("mac", "").upper()
    version_num = js_parse_int(request.query.get("version", ""))

    existing = devices.get(mac)
    if existing is not None and not existing.give_up:
        if existing.version_num == version_num:
            log_fr(f"Connexion demandée mais déjà connecté/en cours sur {mac}", LEVEL_INFO)
            return web.json_response({"result": "ko", "msg": "alreadyConnected"})
        # version changée (détection après coup…) : les clés à charger ne sont plus les mêmes, on reconnecte
        log_fr(f"[{mac}] Version changée ({existing.version_num} -> {version_num}), reconnexion", LEVEL_INFO)
        await stop_device_supervisor(mac)

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


@routes.get("/reconnect")
async def handle_reconnect(request: web.Request) -> web.Response:
    """Ferme la connexion d'un appareil : la surveillance se reconnecte aussitôt en relisant les clés
    d'appairage (après un appairage par exemple)."""
    mac = request.query.get("mac", "").upper()
    device = devices.get(mac)
    if device is None or device.give_up:
        return web.json_response({"result": "ko", "msg": "notConnected"})
    log_fr(f"[{mac}] Reconnexion demandée (nouvelles clés d'appairage)", LEVEL_INFO)
    if device.atv is not None:
        device.atv.close()  # connection_closed -> le superviseur se reconnecte avec les nouvelles clés
    return web.json_response({"result": "ok"})


# ---------------------------------------------------------------------------
# Appairage interactif (Apple TV) : /pair/begin affiche le code sur la TV, /pair/finish reçoit le code saisi
# dans Jeedom et renvoie la clé. Remplace la commande « atvremote … pair » à taper en SSH.
# ---------------------------------------------------------------------------

PAIR_PROTOCOLS = {"airplay": Protocol.AirPlay, "companion": Protocol.Companion}
PAIR_TIMEOUT = 180  # appairage abandonné s'il n'est pas terminé dans ce délai
pairings: dict = {}


async def _pair_close(key) -> None:
    entry = pairings.pop(key, None)
    if entry is None:
        return
    entry["timer"].cancel()
    try:
        await entry["pairing"].close()
    except Exception as exc:
        log_fr(f"[PAIR][{key[0]}] Erreur à la fermeture de l'appairage {key[1]} : {exc!r}", LEVEL_DEBUG)


def _pair_key(request: web.Request):
    return request.query.get("mac", "").upper(), request.query.get("protocol", "").lower()


@routes.get("/pair/begin")
async def handle_pair_begin(request: web.Request) -> web.Response:
    key = _pair_key(request)
    mac, proto_name = key
    protocol = PAIR_PROTOCOLS.get(proto_name)
    if protocol is None:
        return web.json_response({"result": "ko", "msg": "invalid"})
    await _pair_close(key)  # appairage précédent non terminé
    loop = asyncio.get_event_loop()
    log_fr(f"[PAIR][{mac}] Début de l'appairage {proto_name}", LEVEL_INFO)
    storage = MemoryStorage()
    results = await pyatv.scan(loop, identifier=mac, timeout=5, storage=storage)
    if not results:
        return web.json_response({"result": "ko", "msg": "notFound"})
    conf = results[0]
    if conf.get_service(protocol) is None:
        return web.json_response({"result": "ko", "msg": "unsupported"})
    pairing = await pyatv.pair(conf, protocol, loop, storage=storage, name="Jeedom")
    try:
        await pairing.begin()
    except Exception as exc:
        await pairing.close()
        log_fr(f"[PAIR][{mac}] Échec du début de l'appairage {proto_name} : {exc!r}", LEVEL_ERROR)
        return web.json_response({"result": "ko", "msg": "beginFailed", "detail": str(exc)})
    pin = None
    if not pairing.device_provides_pin:
        pin = 1111 + int.from_bytes(os.urandom(2), "big") % 8888  # code à saisir sur l'appareil
        pairing.pin(pin)
    pairings[key] = {"pairing": pairing,
                     "timer": loop.call_later(PAIR_TIMEOUT, lambda: spawn(_pair_close(key), f"l'abandon de l'appairage de {mac}"))}
    return web.json_response({"result": "ok", "device_provides_pin": pairing.device_provides_pin, "pin": pin})


@routes.get("/pair/finish")
async def handle_pair_finish(request: web.Request) -> web.Response:
    key = _pair_key(request)
    mac, proto_name = key
    entry = pairings.get(key)
    if entry is None:
        return web.json_response({"result": "ko", "msg": "noPairing"})
    pairing = entry["pairing"]
    if pairing.device_provides_pin:
        pin = js_parse_int(request.query.get("pin", ""))
        if pin is None:
            return web.json_response({"result": "ko", "msg": "invalidPin"})  # on garde l'appairage : nouvel essai possible
        pairing.pin(pin)
    try:
        await pairing.finish()
        if not pairing.has_paired:
            log_fr(f"[PAIR][{mac}] Appairage {proto_name} refusé", LEVEL_WARNING)
            return web.json_response({"result": "ko", "msg": "failed"})
        log_fr(f"[PAIR][{mac}] Appairage {proto_name} réussi", LEVEL_INFO)
        return web.json_response({"result": "ok", "credentials": pairing.service.credentials})
    except Exception as exc:
        log_fr(f"[PAIR][{mac}] Échec de l'appairage {proto_name} : {exc!r}", LEVEL_WARNING)
        return web.json_response({"result": "ko", "msg": "failed", "detail": str(exc)})
    finally:
        await _pair_close(key)  # réussi ou non, cet appairage est terminé (un échec demande de recommencer)


@routes.get("/pair/cancel")
async def handle_pair_cancel(request: web.Request) -> web.Response:
    key = _pair_key(request)
    if key in pairings:
        log_fr(f"[PAIR][{key[0]}] Appairage {key[1]} annulé", LEVEL_INFO)
    await _pair_close(key)
    return web.json_response({"result": "ok"})


@routes.get("/scan")
async def handle_scan(request: web.Request) -> web.Response:
    """Recherche des appareils sur le réseau. Renvoie exactement le texte de « atvremote scan » (même
    str() de pyatv) : le PHP garde son analyse, sans lancer un nouveau programme Python."""
    timeout = js_parse_int(request.query.get("timeout", "")) or 3
    log_fr(f"Recherche des appareils ({timeout} s)...", LEVEL_INFO)
    results = await pyatv.scan(asyncio.get_event_loop(), timeout=timeout, storage=MemoryStorage())
    output = "Scan Results\n" + "=" * 40 + "\n" + "".join(f"{conf}\n\n" for conf in results)
    log_fr(f"Recherche terminée : {len(results)} appareil(s)", LEVEL_INFO)
    return web.json_response({"result": "ok", "count": len(results), "output": output})


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
    for key in list(pairings):
        await _pair_close(key)
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
