import asyncio
import datetime
from aiohttp import web
from enum import Enum
import pyatv
import json
loop = asyncio.get_event_loop()
routes = web.RouteTableDef()
devices = []
lock = asyncio.Lock()


def web_command(method):
    async def _handler(request):
        device_id = request.match_info["id"]
        atv = request.app["atv"].get(device_id)
        if not atv:
            return web.Response(text=f"Not connected to {device_id}", status=500)
        return await method(request, atv)
    return _handler

def add_credentials(config, query):
    for service in config.services:
        proto_name = service.protocol.name.lower()  # E.g. Protocol.MRP -> "mrp"
        if proto_name in query:
            config.set_credentials(service.protocol, query[proto_name])

def output(success: bool, error=None, exception=None, values=None):
    """Produce output in intermediate format before conversion."""
    now = datetime.datetime.now(datetime.timezone.utc).astimezone().isoformat()
    result = {"result": "success" if success else "failure", "datetime": str(now)}
    if error:
        result["error"] = error
    if exception:
        result["exception"] = str(exception)
        result["stacktrace"] = "".join(
            traceback.format_exception(
                type(exception), exception, exception.__traceback__
            )
        )
    if values:
        result.update(**values)
    return result
    
def output_playing(playing: pyatv.interface.Playing, app: pyatv.interface.App):
    """Produce output for what is currently playing."""

    def _convert(field):
        if isinstance(field, Enum):
            return field.name.lower()
        return field if field else None

    commands = pyatv.interface.retrieve_commands(pyatv.interface.Playing)
    values = {k: _convert(getattr(playing, k)) for k in commands}
    if values["position"] == None:
        return None
    if app:
        values["app"] = app.name
        values["app_id"] = app.identifier
    else:
        values["app"] = None
        values["app_id"] = None
    return output(True, values=values)


@routes.get('/trigger_scan')
async def trigger_scan(request = None):
    global devices
    devices = []
    results = await pyatv.scan(loop=loop)
    for result in results :

        services = []
        for service in result.services:
            services.append(
                {
                    "protocol": service.protocol.name.lower(), 
                    "port": service.port, 
                    "pairing":service.pairing.name, 
                    "properties": service.properties,
                    "cred":service.credentials,
                    "password": service.password,
                    "serv":str(service)
                }
            )

        devices.append(
            {
                "name": result.name, 
                "address": str(result.address),
                "id": result.identifier,
                "mac": result.device_info.mac,
                "device_info": str(result.device_info),
                "os": str(result.device_info.operating_system.name),
                "version": result.device_info.version,
                "build_number": result.device_info.build_number,
                "model": str(result.device_info.model.name),
                "deep_sleep": result.deep_sleep,
                "services": services
            }
        )
    
    if request:
        return web.Response(text="trigger_scan finished")
    else:
        print("trigger_scan finished")

@routes.get('/scan')
async def scan(request):
    if not devices:
        await hasDevices()
    print("get scan from cache", loop.time())
    return web.json_response({"devices": devices})


@routes.get('/connect/{id}')
async def connect(request):
    device_id = request.match_info["id"]

    if device_id in request.app["atv"].keys():
        return web.Response(text=f"Already connected to {device_id}")

    results = await pyatv.scan(identifier=device_id, loop=loop)
    if not results:
        return web.Response(text="Device not found", status=500)

    add_credentials(results[0], request.query)

    try:
        atv = await pyatv.connect(results[0], loop=loop)
    except Exception as ex:
        return web.Response(text=f"Failed to connect to device: {ex}", status=500)
    
    push_listener = PushListener(request.app, atv)
    device_listener = DeviceListener(request.app, device_id, push_listener)
    
    atv.listener = device_listener
    atv.push_updater.listener = push_listener  # <-- set the listener
    atv.push_updater.start()              # <-- start subscribing to updates
    request.app["listeners"].append(device_listener)
    request.app["listeners"].append(push_listener)
    
    print("Connect to",device_id);
    request.app["atv"][device_id] = atv
    return web.Response(text=f"Connected to device {device_id}")

@routes.get("/disconnect/{id}")
@web_command
async def disconnect(request, atv):
    atv.close()
    return web.Response(text="OK")

@routes.get("/cmd/{id}/{command}")
@web_command
async def cmd(request, atv):
    def _typeparse(value):
        try:
            return int(value)
        except ValueError:
            return value
            
    def _parse_args(cmd, args):
        arg = _typeparse(args)
        if cmd == "set_shuffle":
            return pyatv.const.ShuffleState(arg)
        if cmd == "set_repeat":
            return pyatv.const.RepeatState(arg)
        if cmd in ["up", "down", "left", "right", "select", "menu", "home"]:
            return pyatv.const.InputAction(arg)
        if cmd == "set_volume":
            return float(arg)
        if cmd == "set_position":
            return int(arg)
        return args

       
    try:
        if "param" in request.query:
            print(request.match_info["command"],_parse_args(request.match_info["command"],request.query["param"]))
            await getattr(atv.remote_control, request.match_info["command"])(_parse_args(request.match_info["command"],request.query["param"]))
        else:
            print(request.match_info["command"],"no param")
            await getattr(atv.remote_control, request.match_info["command"])()
            
    except Exception as ex:
        return web.Response(text=f"Remote control command failed: {ex}")

    return web.Response(text="OK")
    
@routes.get("/audio/{id}/{command}")
@web_command
async def audio(request, atv):
    def _typeparse(value):
        try:
            return int(value)
        except ValueError:
            return value
            
    def _parse_args(cmd, args):
        arg = _typeparse(args)
        if cmd == "set_volume":
            return float(arg)
        return args

       
    try:
        if "param" in request.query:
            print(request.match_info["command"],_parse_args(request.match_info["command"],request.query["param"]))
            await getattr(atv.audio, request.match_info["command"])(_parse_args(request.match_info["command"],request.query["param"]))
        else:
            print(request.match_info["command"],"no param")
            await getattr(atv.audio, request.match_info["command"])()
            
    except Exception as ex:
        return web.Response(text=f"Audio command failed: {ex}")

    return web.Response(text="OK")
   
"""    
    param="no"
    print(request.query.get("int"),type(request.query.get("int")))
    if "int" in request.query:
        print("there is int in query",request.query["int"])
        param=int(request.query["int"])
    if "str" in request.query:
        param=request.query["str"]
"""

@routes.get("/playing/{id}")
@web_command
async def playing(request, atv):
    try:
        status = await atv.metadata.playing()
    except Exception as ex:
        return web.Response(text=f"Remote control command failed: {ex}")
    return web.Response(text=str(status))



class DeviceListener(pyatv.interface.DeviceListener):
    def __init__(self, app, identifier, push_listener):
        self.app = app
        self.identifier = identifier
        self.push_listener = push_listener

    def connection_lost(self, exception: Exception) -> None:
        self._remove()

    def connection_closed(self) -> None:
        self._remove()

    def _remove(self):
        self.app["atv"].pop(self.identifier)
        self.app["listeners"].remove(self)
        self.app["listeners"].remove(self.push_listener)
        
class PushListener(pyatv.interface.PushListener):
    def __init__(self, app, atv):
        """Initialize a new PushPrinter."""
        self.app = app
        self.atv = atv

    def playstatus_update(self, updater, playstatus: pyatv.interface.Playing) -> None:
        json_line=output_playing(playstatus, self.atv.metadata.app)
        if json_line:
            print(json_line)
        #asyncio.ensure_future(jeedom.send(str(playstatus)))

    def playstatus_error(self, updater, exception: Exception) -> None:
        pass        




async def hasDevices():
    while True:
        if devices:
            return
        await asyncio.sleep(0.5)



        
async def periodic(period):
    def g_tick():
        t = loop.time()
        count = 0
        while True:
            count += 1
            yield max(t + count * period - loop.time(), 0)
    
    g = g_tick()

    while True:
        print('periodic start', loop.time())
        await trigger_scan()
        print('periodic stop', loop.time())
        await asyncio.sleep(next(g))




#loop.call_later(5, task.cancel)
async def on_shutdown(app: web.Application) -> None:
    for atv in app["atv"].values():
        atv.close()

def main():
    app = web.Application()
    app["atv"] = {}
    app["listeners"] = []
    app.add_routes(routes)
    app.on_shutdown.append(on_shutdown)
    task = loop.create_task(periodic(60))
    web.run_app(app)
    """try:
        loop.run_until_complete(task)
    except asyncio.CancelledError:
        pass"""

if __name__ == "__main__":
    main()

